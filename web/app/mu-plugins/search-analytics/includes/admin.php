<?php
/**
 * Admin page registration, asset enqueueing, and dashboard rendering.
 *
 * @package CommunityCode\SearchAnalytics
 */

namespace CommunityCode\SearchAnalytics;

/**
 * Return the number of recent searches to display for the current user.
 *
 * Reads the cc_search_analytics_per_page user meta key saved via the Screen
 * Options panel. Falls back to 50 if the option has not been saved.
 *
 * @since 1.1.0
 *
 * @return int Number of rows to display.
 */
function get_per_page(): int {
	$saved = (int) get_user_meta( get_current_user_id(), 'cc_search_analytics_per_page', true );
	return $saved > 0 ? $saved : 50;
}


/**
 * Render the per-page input inside the Screen Options panel.
 *
 * WordPress's built-in per_page screen option type only applies to WP_List_Table
 * contexts. For custom admin pages, the screen_settings filter is the correct
 * hook for injecting arbitrary screen option controls.
 *
 * @since 1.1.0
 *
 * @param string    $status  Accumulated HTML from other screen option callbacks.
 * @param \WP_Screen $screen  Current screen object.
 * @return string HTML with our input appended.
 */
function render_per_page_screen_option( string $status, \WP_Screen $screen ): string {
	if ( 'dashboard_page_' . ADMIN_SLUG !== $screen->id ) {
		return $status;
	}
	$per_page = get_per_page();
	$status .= '<fieldset><legend>' . esc_html__( 'Recent Searches', 'community-code' ) . '</legend>';
	$status .= '<label for="cc-sa-per-page">' . esc_html__( 'Number of rows:', 'community-code' ) . ' ';
	$status .= '<input type="number" id="cc-sa-per-page" name="cc_search_analytics_per_page"';
	$status .= ' value="' . esc_attr( $per_page ) . '" min="1" max="200" step="1" style="width:50px" />';
	$status .= '</label></fieldset>';
	return $status;
}

/**
 * Register the Search Analytics page under Dashboard in wp-admin.
 *
 * Wires up the screen_settings filter on page load so the per-page
 * control appears in the Screen Options panel.
 *
 * @since 1.0.0
 *
 * @return void
 */
function register_admin_page(): void {
	$hook = add_dashboard_page(
		__( 'Search Analytics', 'community-code' ),
		__( 'Search Analytics', 'community-code' ),
		'manage_options',
		ADMIN_SLUG,
		__NAMESPACE__ . '\\render_admin_page'
	);

	add_action(
		'load-' . $hook,
		function () {
			// wp_save_screen_options() only processes $_POST['wp_screen_options'][option/value],
			// not arbitrary POST fields, so saving must be handled here manually.
			if ( isset( $_POST['screenoptionnonce'], $_POST['cc_search_analytics_per_page'] ) ) {
				check_admin_referer( 'screen-options-nonce', 'screenoptionnonce' );
				$per_page = max( 1, min( 200, (int) $_POST['cc_search_analytics_per_page'] ) );
				update_user_meta( get_current_user_id(), 'cc_search_analytics_per_page', $per_page );
			}

			add_filter( 'screen_settings', __NAMESPACE__ . '\\render_per_page_screen_option', 10, 2 );
		}
	);
}

/**
 * Enqueue scripts and styles for the Search Analytics dashboard page.
 *
 * Registers Chart.js from the ash-nazg plugin bundle if it is not already
 * registered, then enqueues the analytics chart script and dashboard
 * stylesheet. Localizes ccSearchAnalytics with the chart data for the
 * currently selected period.
 *
 * @since 1.0.0
 *
 * @param string $hook The current admin page hook suffix.
 * @return void
 */
function enqueue_admin_assets( string $hook ): void {
	if ( 'dashboard_page_' . ADMIN_SLUG !== $hook ) {
		return;
	}

	if ( ! wp_script_is( 'chartjs', 'registered' ) ) {
		$chart_file = WP_PLUGIN_DIR . '/ash-nazg/assets/js/libs/chart.umd.js';
		if ( file_exists( $chart_file ) ) {
			wp_register_script( 'chartjs', plugins_url( 'ash-nazg/assets/js/libs/chart.umd.js' ), [], false, true );
		}
	}

	wp_enqueue_script(
		'cc-search-analytics',
		CC_SEARCH_ANALYTICS_URL . 'assets/js/admin.js',
		[ 'chartjs' ],
		(string) filemtime( CC_SEARCH_ANALYTICS_DIR . 'assets/js/admin.js' ),
		true
	);

	$days = get_requested_days();
	$data = get_analytics_data( $days );
	$daily = array_reverse( $data['daily_counts'] ); // chronological order for the line chart

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

	wp_enqueue_style(
		'cc-search-analytics',
		CC_SEARCH_ANALYTICS_URL . 'assets/css/admin.css',
		[],
		(string) filemtime( CC_SEARCH_ANALYTICS_DIR . 'assets/css/admin.css' )
	);
}

/**
 * Parse and validate the `days` query parameter for the analytics period selector.
 *
 * Accepts 7, 30, 90, or 0 (all time). Returns 30 for any unrecognised value.
 *
 * @since 1.0.0
 *
 * @return int Number of days for the analytics window. 0 means all time.
 */
function get_requested_days(): int {
	$days = (int) sanitize_text_field( wp_unslash( $_GET['days'] ?? '30' ) );
	return array_key_exists( $days, [ 7 => '', 30 => '', 90 => '', 0 => '' ] ) ? $days : 30;
}

/**
 * Render the Search Analytics dashboard page.
 *
 * Outputs the period selector, summary stats, daily volume chart, top terms
 * chart, daily volume table, and a Recent Searches table showing individual
 * search events with country, user agent, and referrer for bot/human analysis.
 * Chart rendering is handled by admin.js via the ccSearchAnalytics localised object.
 *
 * @since 1.0.0
 *
 * @return void
 */
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

		<div class="sa-card">
			<h2 style="margin-top:0"><?php esc_html_e( 'Recent Searches', 'community-code' ); ?></h2>
			<?php
			$recent = query_recent_searches( $days, get_per_page() );
			if ( empty( $recent ) ) :
			?>
				<p class="sa-zero"><?php esc_html_e( 'No searches recorded for this period.', 'community-code' ); ?></p>
			<?php else : ?>
			<table class="wp-list-table widefat fixed striped" style="margin-top:0">
				<thead>
					<tr>
						<th style="width:140px"><?php esc_html_e( 'Term', 'community-code' ); ?></th>
						<th style="width:130px"><?php esc_html_e( 'Date', 'community-code' ); ?></th>
						<th style="width:60px"><?php esc_html_e( 'Country', 'community-code' ); ?></th>
						<th><?php esc_html_e( 'User Agent', 'community-code' ); ?></th>
						<th><?php esc_html_e( 'Referrer', 'community-code' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $recent as $row ) :
						$country = $row['country'] ?: '—';
						$ua = $row['user_agent'];
						$referrer = $row['referrer'];
						$referrer_display = $referrer ? wp_parse_url( $referrer, PHP_URL_HOST ) . wp_parse_url( $referrer, PHP_URL_PATH ) : '—';
					?>
					<tr>
						<td><?php echo esc_html( $row['term'] ); ?></td>
						<td><?php echo esc_html( get_date_from_gmt( $row['searched_at'], 'Y-m-d H:i' ) ); ?></td>
						<td><?php echo esc_html( $country ); ?></td>
						<td class="sa-truncate" title="<?php echo esc_attr( $ua ); ?>"><?php echo esc_html( $ua ); ?></td>
						<td class="sa-truncate" title="<?php echo esc_attr( $referrer ); ?>"><?php echo esc_html( $referrer_display ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>

		<?php endif; ?>
	</div>
	<?php
}
