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

/**
 * Get the configured ES host URL.
 */
function get_es_host(): string {
	return defined( 'EP_HOST' ) ? (string) EP_HOST : '';
}

/**
 * Get the Basic Auth header value for ES requests.
 */
function get_es_auth_header(): string {
	if ( ! defined( 'EP_CREDENTIALS' ) || ! EP_CREDENTIALS ) {
		return '';
	}
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	return 'Basic ' . base64_encode( EP_CREDENTIALS );
}

/**
 * Get the full ES index URL for the search analytics index.
 */
function get_index_url(): string {
	$host   = get_es_host();
	$prefix = defined( 'EP_INDEX_PREFIX' ) ? EP_INDEX_PREFIX : '';
	if ( ! $host || ! $prefix ) {
		return '';
	}
	return trailingslashit( $host ) . $prefix . '_' . INDEX_SUFFIX;
}

/**
 * Detect obvious bot requests to skip logging.
 */
function is_bot(): bool {
	$agent = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ) );
	$bots  = [ 'bot', 'crawl', 'spider', 'slurp', 'mediapartners', 'wget', 'curl' ];
	foreach ( $bots as $indicator ) {
		if ( str_contains( $agent, $indicator ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Hook into the main search query. If the user is anonymous and not a bot,
 * register a one-shot filter to capture results after the query runs.
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

	// Capture the result count after the query runs, then write to ES.
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
 * Write a search event to the ES analytics index. Non-blocking — does not
 * delay the page response.
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
			'blocking' => false, // Fire and forget — don't hold up the page.
		]
	);
}

/**
 * Register the admin page under Dashboard.
 */
function register_admin_page(): void {
	add_dashboard_page(
		__( 'Search Analytics', 'community-code' ),
		__( 'Search Analytics', 'community-code' ),
		'manage_options',
		ADMIN_SLUG,
		__NAMESPACE__ . '\\render_admin_page'
	);
}

/**
 * Enqueue minimal inline styles for the analytics page.
 */
function enqueue_admin_assets( string $hook ): void {
	if ( 'dashboard_page_' . ADMIN_SLUG !== $hook ) {
		return;
	}
	$css = '
		.sa-bar-wrap { background:#f0f0f1; border-radius:3px; height:16px; min-width:80px; }
		.sa-bar { background:#2271b1; border-radius:3px; height:16px; }
		.sa-zero { color:#646970; font-style:italic; }
		.sa-period a { display:inline-block; padding:4px 12px; border:1px solid #2271b1; border-radius:3px; margin-right:4px; text-decoration:none; color:#2271b1; }
		.sa-period a.current { background:#2271b1; color:#fff; }
		.sa-stat { display:inline-block; margin-right:32px; }
		.sa-stat strong { display:block; font-size:2em; line-height:1.2; }
	';
	wp_register_style( 'cc-search-analytics', false );
	wp_enqueue_style( 'cc-search-analytics' );
	wp_add_inline_style( 'cc-search-analytics', $css );
}

/**
 * Query ES for search analytics aggregations.
 *
 * @param int $days Number of days to look back. 0 = all time.
 * @return array{top_terms: array, daily_counts: array, total: int, unique: int}
 */
function query_es( int $days ): array {
	$index_url = get_index_url();
	$auth      = get_es_auth_header();

	$empty = [ 'top_terms' => [], 'daily_counts' => [], 'total' => 0, 'unique' => 0 ];

	if ( ! $index_url || ! $auth ) {
		return $empty;
	}

	$query = [
		'size'  => 0,
		'aggs'  => [
			'top_terms'    => [
				'terms' => [ 'field' => 'term.keyword', 'size' => 25 ],
			],
			'daily_counts' => [
				'date_histogram' => [
					'field'             => 'timestamp',
					'calendar_interval' => 'day',
					'format'            => 'yyyy-MM-dd',
					'min_doc_count'     => 1,
				],
			],
			'unique_terms' => [
				'cardinality' => [ 'field' => 'term.keyword' ],
			],
		],
	];

	if ( $days > 0 ) {
		$query['query'] = [
			'range' => [ 'timestamp' => [ 'gte' => 'now-' . $days . 'd/d' ] ],
		];
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
		'top_terms'    => $aggs['top_terms']['buckets'] ?? [],
		'daily_counts' => array_slice( array_reverse( $aggs['daily_counts']['buckets'] ?? [] ), 0, 30 ),
		'total'        => (int) ( $body['hits']['total']['value'] ?? 0 ),
		'unique'       => (int) ( $aggs['unique_terms']['value'] ?? 0 ),
	];
}

/**
 * Render the search analytics dashboard page.
 */
function render_admin_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Permission denied.', 'community-code' ) );
	}

	$days    = (int) sanitize_text_field( wp_unslash( $_GET['days'] ?? '30' ) );
	$periods = [ 7 => '7 days', 30 => '30 days', 90 => '90 days', 0 => 'All time' ];
	if ( ! array_key_exists( $days, $periods ) ) {
		$days = 30;
	}

	$data      = query_es( $days );
	$top_terms = $data['top_terms'];
	$max_count = ! empty( $top_terms ) ? (int) $top_terms[0]['doc_count'] : 1;
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

		<?php if ( empty( $top_terms ) ) : ?>
			<div class="notice notice-info inline">
				<p><?php esc_html_e( 'No search data yet for this period.', 'community-code' ); ?></p>
			</div>
		<?php else : ?>

		<div style="display:flex;gap:40px;align-items:flex-start;flex-wrap:wrap;">

			<div style="flex:1;min-width:300px;">
				<h2><?php esc_html_e( 'Top Search Terms', 'community-code' ); ?></h2>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Term', 'community-code' ); ?></th>
							<th style="width:60px"><?php esc_html_e( 'Count', 'community-code' ); ?></th>
							<th style="width:120px"></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $top_terms as $bucket ) : ?>
							<?php
							$count = (int) $bucket['doc_count'];
							$pct   = $max_count > 0 ? round( ( $count / $max_count ) * 100 ) : 0;
							?>
							<tr>
								<td><code><?php echo esc_html( $bucket['key'] ); ?></code></td>
								<td><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
								<td>
									<div class="sa-bar-wrap">
										<div class="sa-bar" style="width:<?php echo esc_attr( $pct ); ?>%"></div>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div style="flex:1;min-width:280px;">
				<h2><?php esc_html_e( 'Daily Search Volume', 'community-code' ); ?></h2>
				<table class="wp-list-table widefat fixed striped">
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
