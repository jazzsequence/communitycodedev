<?php
/**
 * Plugin Name: Search Analytics
 * Description: Logs anonymous search queries to a custom database table and provides a dashboard to view search trends.
 * Author: Chris Reynolds
 * License: MIT
 */

namespace CommunityCode\SearchAnalytics;

const ADMIN_SLUG = 'cc-search-analytics';
const DB_VERSION = '1.0';
const DB_VERSION_OPTION = 'cc_search_analytics_db_version';

add_action( 'init', __NAMESPACE__ . '\\maybe_create_table' );
add_action( 'init', __NAMESPACE__ . '\\schedule_cleanup' );
add_action( 'cc_search_analytics_cleanup', __NAMESPACE__ . '\\run_cleanup' );
add_action( 'wp', __NAMESPACE__ . '\\maybe_track_standard_search' );
add_action( 'rest_api_init', __NAMESPACE__ . '\\register_ep_proxy_endpoint' );
// Priority 20 runs after Pantheon's MU plugin sets EP_DIRECT_HOST endpoint at priority 10.
add_filter( 'ep_instant_results_search_endpoint', __NAMESPACE__ . '\\redirect_ep_to_proxy', 20 );
add_action( 'admin_menu', __NAMESPACE__ . '\\register_admin_page' );
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_admin_assets' );

// ─── Database ─────────────────────────────────────────────────────────────────

function get_table_name(): string {
	global $wpdb;
	return $wpdb->prefix . 'cc_search_analytics';
}

function maybe_create_table(): void {
	if ( get_option( DB_VERSION_OPTION ) === DB_VERSION ) {
		return;
	}
	global $wpdb;
	$table = get_table_name();
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		term VARCHAR(200) NOT NULL,
		results MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
		searched_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		KEY term (term(50)),
		KEY searched_at (searched_at)
	) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
	update_option( DB_VERSION_OPTION, DB_VERSION );
}

function schedule_cleanup(): void {
	if ( ! wp_next_scheduled( 'cc_search_analytics_cleanup' ) ) {
		wp_schedule_event( time(), 'daily', 'cc_search_analytics_cleanup' );
	}
}

function run_cleanup(): void {
	global $wpdb;
	$table = get_table_name();
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"DELETE FROM {$table} WHERE searched_at < %s",
			gmdate( 'Y-m-d H:i:s', strtotime( '-90 days' ) )
		)
	);
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
 * Track standard WordPress search page loads (?s=query full-page requests).
 * Fires on the `wp` action, after the main query has fully executed.
 */
function maybe_track_standard_search(): void {
	global $wp_query;
	if ( ! $wp_query->is_search() ) {
		return;
	}
	if ( is_user_logged_in() || is_bot() ) {
		return;
	}
	$term = trim( $wp_query->get( 's' ) );
	if ( empty( $term ) ) {
		return;
	}
	write_to_db( $term, (int) $wp_query->found_posts );
}

/**
 * Redirect EP Instant Results searches through our WP REST proxy.
 *
 * Pantheon's MU plugin routes EP Instant Results directly from the browser to
 * hosted-elasticpress.io (bypassing WordPress PHP). We intercept at the filter
 * level so the localized apiEndpoint points to our proxy instead — which means
 * every search passes through WordPress, where we can log it.
 *
 * Runs at priority 20, after Pantheon's filter at 10 sets EP_DIRECT_HOST.
 */
function redirect_ep_to_proxy(): string {
	return rest_url( 'community-code/v1/ep-search' );
}

/**
 * Reconstruct the original EP.io direct search endpoint from platform constants.
 */
function get_ep_direct_endpoint(): string {
	if ( ! defined( 'EP_DIRECT_HOST' ) || ! class_exists( '\\ElasticPress\\Indexables' ) ) {
		return '';
	}
	$index = \ElasticPress\Indexables::factory()->get( 'post' )->get_index_name();
	return $index ? EP_DIRECT_HOST . '/api/v1/search/posts/' . $index : '';
}

function register_ep_proxy_endpoint(): void {
	register_rest_route(
		'community-code/v1',
		'/ep-search',
		[
			'methods' => \WP_REST_Server::READABLE,
			'callback' => __NAMESPACE__ . '\\handle_ep_search_proxy',
			'permission_callback' => '__return_true',
		]
	);
}

/**
 * Log the search term, then proxy the request to EP.io and return its response.
 *
 * @param \WP_REST_Request $request
 * @return \WP_REST_Response|\WP_Error
 */
function handle_ep_search_proxy( \WP_REST_Request $request ) {
	$term = trim( sanitize_text_field( (string) ( $request->get_param( 'ep-search' ) ?? '' ) ) );
	if ( mb_strlen( $term ) >= 2 && ! is_user_logged_in() && ! is_bot() ) {
		write_to_db( $term, 0 );
	}

	$ep_endpoint = get_ep_direct_endpoint();
	if ( ! $ep_endpoint ) {
		return new \WP_Error( 'ep_proxy_error', 'EP endpoint not configured.', [ 'status' => 500 ] );
	}

	$ep_url = add_query_arg( $request->get_query_params(), $ep_endpoint );
	$ep_response = wp_remote_get(
		$ep_url,
		[
			'timeout' => 10,
			'headers' => [ 'Accept' => 'application/json' ],
		]
	);

	if ( is_wp_error( $ep_response ) ) {
		return new \WP_Error( 'ep_proxy_upstream', $ep_response->get_error_message(), [ 'status' => 502 ] );
	}

	$status = wp_remote_retrieve_response_code( $ep_response );
	$data = json_decode( wp_remote_retrieve_body( $ep_response ), true );
	$response = new \WP_REST_Response( $data, $status );
	$response->header( 'Cache-Control', 'no-store' );
	return $response;
}

function write_to_db( string $term, int $results_count ): void {
	global $wpdb;
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		get_table_name(),
		[
			'term'        => $term,
			'results'     => $results_count,
			'searched_at' => gmdate( 'Y-m-d H:i:s' ),
		],
		[ '%s', '%d', '%s' ]
	);
}

// ─── Data layer ───────────────────────────────────────────────────────────────

/**
 * Static cache so enqueue_admin_assets() and render_admin_page() share one DB query.
 */
function get_analytics_data( int $days ): array {
	static $cache = [];
	if ( ! isset( $cache[ $days ] ) ) {
		$cache[ $days ] = query_db( $days );
	}
	return $cache[ $days ];
}

/**
 * Query the DB for aggregated search analytics.
 *
 * Returns arrays shaped to match the former ES response so the admin UI
 * needs no changes: top_terms items have `key`/`doc_count` keys; daily_counts
 * items have `key_as_string`/`doc_count` keys.
 *
 * @param int $days Days to look back. 0 = all time.
 * @return array{top_terms: array, daily_counts: array, total: int, unique: int}
 */
function query_db( int $days ): array {
	global $wpdb;
	$table = get_table_name();
	$empty = [ 'top_terms' => [], 'daily_counts' => [], 'total' => 0, 'unique' => 0 ];

	if ( $days > 0 ) {
		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$where_sql = $wpdb->prepare( 'WHERE searched_at >= %s', $since );
	} else {
		$where_sql = '';
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$top_terms = $wpdb->get_results(
		"SELECT term AS `key`, COUNT(*) AS doc_count FROM {$table} {$where_sql} GROUP BY term ORDER BY doc_count DESC LIMIT 25",
		ARRAY_A
	) ?: [];

	$daily_counts = $wpdb->get_results(
		"SELECT DATE(searched_at) AS key_as_string, COUNT(*) AS doc_count FROM {$table} {$where_sql} GROUP BY DATE(searched_at) ORDER BY key_as_string DESC LIMIT 30",
		ARRAY_A
	) ?: [];

	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where_sql}" );
	$unique = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT term) FROM {$table} {$where_sql}" );
	// phpcs:enable

	return [
		'top_terms' => $top_terms,
		// Stored DESC (most-recent-first) for the table; enqueue_admin_assets() reverses for the chart.
		'daily_counts' => $daily_counts,
		'total' => $total,
		'unique'       => $unique,
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

	$days = get_requested_days();
	$periods = [ 7 => '7 days', 30 => '30 days', 90 => '90 days', 0 => 'All time' ];
	$data = get_analytics_data( $days );
	$terms = $data['top_terms'];
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
