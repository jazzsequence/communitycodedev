<?php
/**
 * Plugin Name: Search Analytics
 * Description: Logs anonymous search queries to Elasticsearch and provides a dashboard to view search trends.
 * Author: Chris Reynolds
 * License: MIT
 */

namespace CommunityCode\SearchAnalytics;

const INDEX_SUFFIX = 'search_analytics';
const ADMIN_SLUG   = 'cc-search-analytics';

add_action( 'pre_get_posts', __NAMESPACE__ . '\\maybe_track_search' );
add_action( 'admin_menu', __NAMESPACE__ . '\\register_admin_page' );
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_admin_assets' );

// ─── ES connection ────────────────────────────────────────────────────────────

function get_es_host(): string {
	return defined( 'EP_HOST' ) ? (string) EP_HOST : '';
}

function get_es_auth_header(): string {
	if ( ! defined( 'EP_CREDENTIALS' ) || ! EP_CREDENTIALS ) {
		return '';
	}
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	return 'Basic ' . base64_encode( EP_CREDENTIALS );
}

function get_index_url(): string {
	$host   = get_es_host();
	$prefix = defined( 'EP_INDEX_PREFIX' ) ? EP_INDEX_PREFIX : '';
	if ( ! $host || ! $prefix ) {
		return '';
	}
	return trailingslashit( $host ) . $prefix . '_' . INDEX_SUFFIX;
}

// ─── Bot detection ────────────────────────────────────────────────────────────

function is_bot(): bool {
	$agent = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ) );
	foreach ( [ 'bot', 'crawl', 'spider', 'slurp', 'mediapartners', 'wget', 'curl' ] as $indicator ) {
		if ( str_contains( $agent, $indicator ) ) {
			return true;
		}
	}
	return false;
}

// ─── Search tracking ──────────────────────────────────────────────────────────

/**
 * Hook into the main search query. For anonymous non-bot requests, register a
 * one-shot the_posts filter to capture results and write to ES.
 */
function maybe_track_search( \WP_Query $query ): void {
	if ( ! $query->is_main_query() || ! $query->is_search() ) {
		return;
	}
	if ( is_user_logged_in() || is_bot() ) {
		return;
	}
	$term = trim( $query->get( 's' ) );
	if ( empty( $term ) ) {
		return;
	}

	$tracker = null;
	$tracker = function ( array $posts, \WP_Query $q ) use ( $term, &$tracker ): array {
		if ( $q->is_main_query() && $q->is_search() ) {
			write_to_es( $term, (int) $q->found_posts );
			remove_filter( 'the_posts', $tracker, 10 );
		}
		return $posts;
	};
	add_filter( 'the_posts', $tracker, 10, 2 );
}

/**
 * Write a search event to ES. Non-blocking — does not delay the page response.
 */
function write_to_es( string $term, int $results_count ): void {
	$index_url = get_index_url();
	$auth      = get_es_auth_header();
	if ( ! $index_url || ! $auth ) {
		return;
	}

	wp_remote_post(
		$index_url . '/_doc',
		[
			'headers'  => [
				'Content-Type'  => 'application/json',
				'Authorization' => $auth,
			],
			'body'     => wp_json_encode(
				[
					'term'          => $term,
					'results_count' => $results_count,
					'timestamp'     => gmdate( 'c' ),
				]
			),
			'blocking' => false,
		]
	);
}

// ─── Data layer ───────────────────────────────────────────────────────────────

/**
 * Static cache so enqueue_admin_assets() and render_admin_page() share one ES query.
 */
function get_analytics_data( int $days ): array {
	static $cache = [];
	if ( ! isset( $cache[ $days ] ) ) {
		$cache[ $days ] = query_es( $days );
	}
	return $cache[ $days ];
}

/**
 * Query ES for aggregated search analytics.
 *
 * @param int $days Days to look back. 0 = all time.
 * @return array{top_terms: array, daily_counts: array, total: int, unique: int}
 */
function query_es( int $days ): array {
	$index_url = get_index_url();
	$auth      = get_es_auth_header();
	$empty     = [ 'top_terms' => [], 'daily_counts' => [], 'total' => 0, 'unique' => 0 ];

	if ( ! $index_url || ! $auth ) {
		return $empty;
	}

	$query = [
		'size' => 0,
		'aggs' => [
			'top_terms'    => [ 'terms' => [ 'field' => 'term.keyword', 'size' => 25 ] ],
			'daily_counts' => [
				'date_histogram' => [
					'field'             => 'timestamp',
					'calendar_interval' => 'day',
					'format'            => 'yyyy-MM-dd',
					'min_doc_count'     => 1,
				],
			],
			'unique_terms' => [ 'cardinality' => [ 'field' => 'term.keyword' ] ],
		],
	];

	if ( $days > 0 ) {
		$query['query'] = [ 'range' => [ 'timestamp' => [ 'gte' => 'now-' . $days . 'd/d' ] ] ];
	}

	$response = wp_remote_post(
		$index_url . '/_search',
		[
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => $auth,
			],
			'body'    => wp_json_encode( $query ),
			'timeout' => 10,
		]
	);

	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return $empty;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	$aggs = $body['aggregations'] ?? [];

	return [
		// ES returns daily_counts oldest-first. Reverse for table display (most-recent-first).
		'top_terms'    => $aggs['top_terms']['buckets'] ?? [],
		'daily_counts' => array_slice( array_reverse( $aggs['daily_counts']['buckets'] ?? [] ), 0, 30 ),
		'total'        => (int) ( $body['hits']['total']['value'] ?? 0 ),
		'unique'       => (int) ( $aggs['unique_terms']['value'] ?? 0 ),
	];
}

// ─── Admin ────────────────────────────────────────────────────────────────────

function register_admin_page(): void {
	add_dashboard_page(
		__( 'Search Analytics', 'community-code' ),
		__( 'Search Analytics', 'community-code' ),
		'manage_options',
		ADMIN_SLUG,
		__NAMESPACE__ . '\\render_admin_page'
	);
}

function enqueue_admin_assets( string $hook ): void {
	if ( 'dashboard_page_' . ADMIN_SLUG !== $hook ) {
		return;
	}

	// Register Chart.js from ash-nazg's bundled copy if not already registered.
	if ( ! wp_script_is( 'chartjs', 'registered' ) ) {
		$chart_file = WP_PLUGIN_DIR . '/ash-nazg/assets/js/libs/chart.umd.js';
		if ( file_exists( $chart_file ) ) {
			wp_register_script( 'chartjs', plugins_url( 'ash-nazg/assets/js/libs/chart.umd.js' ), [], false, true );
		}
	}

	wp_register_script( 'cc-search-analytics', false, [ 'chartjs' ], false, true );
	wp_enqueue_script( 'cc-search-analytics' );

	// Localize chart data (daily_counts reversed back to chronological for the line chart).
	$days = get_requested_days();
	$data = get_analytics_data( $days );

	$daily = array_reverse( $data['daily_counts'] ); // chronological for chart
	wp_localize_script(
		'cc-search-analytics',
		'ccSearchAnalytics',
		[
			'daily' => [
				'labels' => array_column( $daily, 'key_as_string' ),
				'counts' => array_map( 'intval', array_column( $daily, 'doc_count' ) ),
			],
			'terms' => [
				'labels' => array_column( $data['top_terms'], 'key' ),
				'counts' => array_map( 'intval', array_column( $data['top_terms'], 'doc_count' ) ),
			],
		]
	);

	wp_add_inline_script( 'cc-search-analytics', get_chart_js() );

	wp_register_style( 'cc-search-analytics', false );
	wp_enqueue_style( 'cc-search-analytics' );
	wp_add_inline_style( 'cc-search-analytics', get_admin_css() );
}

function get_requested_days(): int {
	$days = (int) sanitize_text_field( wp_unslash( $_GET['days'] ?? '30' ) );
	return array_key_exists( $days, [ 7 => '', 30 => '', 90 => '', 0 => '' ] ) ? $days : 30;
}

function get_admin_css(): string {
	return '
		.sa-period a { display:inline-block; padding:4px 12px; border:1px solid #2271b1; border-radius:3px; margin-right:4px; text-decoration:none; color:#2271b1; }
		.sa-period a.current { background:#2271b1; color:#fff; }
		.sa-stat { display:inline-block; margin-right:32px; }
		.sa-stat strong { display:block; font-size:2em; line-height:1.2; }
		.sa-card { background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:16px 20px; margin:20px 0; }
		.sa-zero { color:#646970; font-style:italic; }
		.sa-chart-wrap { position:relative; height:260px; }
		.sa-terms-wrap { position:relative; }
	';
}

function get_chart_js(): string {
	return <<<'JS'
(function () {
	'use strict';
	if ( typeof Chart === 'undefined' || typeof ccSearchAnalytics === 'undefined' ) { return; }

	var blue = { border: 'rgb(28,151,234)', bg: 'rgba(28,151,234,0.1)' };
	var baseOpts = {
		responsive: true,
		maintainAspectRatio: false,
		plugins: {
			legend: { display: false },
			tooltip: { backgroundColor: 'rgba(0,0,0,0.8)', padding: 10 }
		},
		scales: {
			x: { grid: { display: false }, ticks: { font: { size: 11 } } },
			y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { font: { size: 11 } } }
		}
	};

	var dailyEl = document.getElementById( 'cc-sa-daily-chart' );
	if ( dailyEl && ccSearchAnalytics.daily.labels.length ) {
		new Chart( dailyEl.getContext( '2d' ), {
			type: 'line',
			data: {
				labels: ccSearchAnalytics.daily.labels,
				datasets: [ {
					label: 'Searches',
					data: ccSearchAnalytics.daily.counts,
					borderColor: blue.border,
					backgroundColor: blue.bg,
					borderWidth: 3,
					pointRadius: 4,
					pointHoverRadius: 6,
					pointBackgroundColor: blue.border,
					pointBorderColor: '#fff',
					pointBorderWidth: 2,
					fill: true,
					tension: 0.4
				} ]
			},
			options: baseOpts
		} );
	}

	var termsEl = document.getElementById( 'cc-sa-terms-chart' );
	if ( termsEl && ccSearchAnalytics.terms.labels.length ) {
		new Chart( termsEl.getContext( '2d' ), {
			type: 'bar',
			data: {
				labels: ccSearchAnalytics.terms.labels,
				datasets: [ {
					label: 'Searches',
					data: ccSearchAnalytics.terms.counts,
					backgroundColor: blue.bg,
					borderColor: blue.border,
					borderWidth: 2
				} ]
			},
			options: {
				indexAxis: 'y',
				responsive: true,
				maintainAspectRatio: false,
				plugins: { legend: { display: false }, tooltip: { backgroundColor: 'rgba(0,0,0,0.8)', padding: 10 } },
				scales: {
					x: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { font: { size: 11 } } },
					y: { grid: { display: false }, ticks: { font: { size: 11 } } }
				}
			}
		} );
	}
})();
JS;
}

function render_admin_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Permission denied.', 'community-code' ) );
	}

	$days    = get_requested_days();
	$periods = [ 7 => '7 days', 30 => '30 days', 90 => '90 days', 0 => 'All time' ];
	$data    = get_analytics_data( $days );
	$terms   = $data['top_terms'];
	$terms_h = max( count( $terms ) * 28 + 40, 120 ); // px height for bar chart
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Search Analytics', 'community-code' ); ?></h1>
		<p><?php esc_html_e( 'Anonymous searches only — logged-in users and bots are excluded.', 'community-code' ); ?></p>

		<p class="sa-period">
			<?php foreach ( $periods as $d => $label ) : ?>
				<a href="<?php echo esc_url( add_query_arg( [ 'page' => ADMIN_SLUG, 'days' => $d ], admin_url( 'index.php' ) ) ); ?>"
				   class="<?php echo $d === $days ? 'current' : ''; ?>">
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</p>

		<div style="margin:20px 0;">
			<span class="sa-stat">
				<strong><?php echo esc_html( number_format_i18n( $data['total'] ) ); ?></strong>
				<?php esc_html_e( 'Total searches', 'community-code' ); ?>
			</span>
			<span class="sa-stat">
				<strong><?php echo esc_html( number_format_i18n( $data['unique'] ) ); ?></strong>
				<?php esc_html_e( 'Unique terms', 'community-code' ); ?>
			</span>
		</div>

		<?php if ( empty( $terms ) ) : ?>
			<div class="notice notice-info inline">
				<p><?php esc_html_e( 'No search data yet for this period.', 'community-code' ); ?></p>
			</div>
		<?php else : ?>

		<div class="sa-card">
			<h2 style="margin-top:0"><?php esc_html_e( 'Daily Search Volume', 'community-code' ); ?></h2>
			<div class="sa-chart-wrap"><canvas id="cc-sa-daily-chart"></canvas></div>
		</div>

		<div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap;">

			<div class="sa-card" style="flex:2;min-width:300px;">
				<h2 style="margin-top:0"><?php esc_html_e( 'Top Search Terms', 'community-code' ); ?></h2>
				<div class="sa-terms-wrap" style="height:<?php echo esc_attr( $terms_h ); ?>px">
					<canvas id="cc-sa-terms-chart"></canvas>
				</div>
			</div>

			<div class="sa-card" style="flex:1;min-width:220px;">
				<h2 style="margin-top:0"><?php esc_html_e( 'Daily Volume', 'community-code' ); ?></h2>
				<table class="wp-list-table widefat fixed striped" style="margin-top:0">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'community-code' ); ?></th>
							<th style="width:60px"><?php esc_html_e( 'Searches', 'community-code' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $data['daily_counts'] ) ) : ?>
							<tr><td colspan="2" class="sa-zero"><?php esc_html_e( 'No data', 'community-code' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $data['daily_counts'] as $bucket ) : ?>
								<tr>
									<td><?php echo esc_html( $bucket['key_as_string'] ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (int) $bucket['doc_count'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

		</div>
		<?php endif; ?>
	</div>
	<?php
}
