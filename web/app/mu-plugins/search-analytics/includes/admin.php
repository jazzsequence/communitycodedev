<?php
/**
 * Admin page registration, asset enqueueing, and dashboard rendering.
 *
 * @package CommunityCode\SearchAnalytics
 */

namespace CommunityCode\SearchAnalytics;

// ─── Screen option getters ────────────────────────────────────────────────────

/**
 * Number of rows to show in the Recent Searches table.
 *
 * @since 1.1.0
 * @return int
 */
function get_rows_per_page(): int {
	$saved = (int) get_user_meta( get_current_user_id(), 'cc_search_analytics_rows', true );
	return $saved > 0 ? $saved : 50;
}

/**
 * Number of days to show in the Daily Volume table.
 *
 * @since 1.1.0
 * @return int
 */
function get_daily_limit(): int {
	$saved = (int) get_user_meta( get_current_user_id(), 'cc_search_analytics_daily', true );
	return $saved > 0 ? $saved : 30;
}

/**
 * Default analytics period in days. 0 = all time.
 *
 * Uses the empty-string check because 0 is a valid saved value (all time).
 *
 * @since 1.1.0
 * @return int
 */
function get_default_period(): int {
	$saved = get_user_meta( get_current_user_id(), 'cc_search_analytics_period', true );
	if ( $saved === '' || $saved === false ) {
		return 30;
	}
	return (int) $saved;
}

// ─── Screen options ───────────────────────────────────────────────────────────

/**
 * Render the Screen Options fieldsets for the analytics dashboard.
 *
 * Follows the ash-nazg pattern: the Apply button is included inside the
 * screen_settings HTML because WordPress does not add one automatically
 * when only custom content is injected via this filter.
 *
 * @since 1.1.0
 *
 * @param string     $settings Accumulated HTML from other callbacks.
 * @param \WP_Screen $screen   Current screen object.
 * @return string
 */
function render_screen_options( string $settings, \WP_Screen $screen ): string {
	if ( 'dashboard_page_' . ADMIN_SLUG !== $screen->id ) {
		return $settings;
	}

	$rows   = get_rows_per_page();
	$daily  = get_daily_limit();
	$period = get_default_period();
	$valid_periods = [ 7 => __( '7 days', 'community-code' ), 30 => __( '30 days', 'community-code' ), 90 => __( '90 days', 'community-code' ), 0 => __( 'All time', 'community-code' ) ];

	ob_start();
	?>
	<fieldset class="screen-options">
		<legend><?php esc_html_e( 'Recent Searches', 'community-code' ); ?></legend>
		<label for="cc-sa-rows">
			<?php esc_html_e( 'Number of rows:', 'community-code' ); ?>
			<input type="number" id="cc-sa-rows" name="cc_search_analytics_rows"
				value="<?php echo esc_attr( $rows ); ?>" min="1" max="200" step="1" style="width:50px" />
		</label>
	</fieldset>
	<fieldset class="screen-options">
		<legend><?php esc_html_e( 'Daily Volume', 'community-code' ); ?></legend>
		<label for="cc-sa-daily">
			<?php esc_html_e( 'Number of days:', 'community-code' ); ?>
			<input type="number" id="cc-sa-daily" name="cc_search_analytics_daily"
				value="<?php echo esc_attr( $daily ); ?>" min="1" max="90" step="1" style="width:50px" />
		</label>
	</fieldset>
	<fieldset class="screen-options">
		<legend><?php esc_html_e( 'Default Period', 'community-code' ); ?></legend>
		<?php foreach ( $valid_periods as $d => $label ) : ?>
		<label>
			<input type="radio" name="cc_search_analytics_period"
				value="<?php echo esc_attr( $d ); ?>" <?php checked( $period, $d ); ?> />
			<?php echo esc_html( $label ); ?>
		</label>
		<?php endforeach; ?>
	</fieldset>
	<?php submit_button( __( 'Apply', 'community-code' ), 'primary', 'screen-options-apply', false ); ?>
	<?php
	$settings .= ob_get_clean();
	return $settings;
}

/**
 * Save screen options on form submission.
 *
 * Checks for the Apply button POST key and the page slug, then persists
 * each option to user meta. Hooked to admin_init.
 *
 * @since 1.1.0
 * @return void
 */
function handle_screen_options(): void {
	if ( ! isset( $_POST['screen-options-apply'] ) ) {
		return;
	}
	if ( ! isset( $_GET['page'] ) || ADMIN_SLUG !== $_GET['page'] ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$user_id = get_current_user_id();

	if ( isset( $_POST['cc_search_analytics_rows'] ) ) {
		update_user_meta( $user_id, 'cc_search_analytics_rows', max( 1, min( 200, (int) $_POST['cc_search_analytics_rows'] ) ) );
	}
	if ( isset( $_POST['cc_search_analytics_daily'] ) ) {
		update_user_meta( $user_id, 'cc_search_analytics_daily', max( 1, min( 90, (int) $_POST['cc_search_analytics_daily'] ) ) );
	}
	if ( isset( $_POST['cc_search_analytics_period'] ) ) {
		$val = (int) $_POST['cc_search_analytics_period'];
		if ( in_array( $val, [ 7, 30, 90, 0 ], true ) ) {
			update_user_meta( $user_id, 'cc_search_analytics_period', $val );
		}
	}
}

// ─── Admin page ───────────────────────────────────────────────────────────────

/**
 * Register the Search Analytics page under Dashboard in wp-admin.
 *
 * @since 1.0.0
 * @return void
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
 * Enqueue scripts and styles for the Search Analytics dashboard page.
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
	$daily = array_slice( array_reverse( $data['daily_counts'] ), 0, get_daily_limit() );

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
 * Parse and validate the `days` query parameter for the period selector.
 *
 * Falls back to the user's saved default period screen option.
 *
 * @since 1.0.0
 * @return int Number of days. 0 = all time.
 */
function get_requested_days(): int {
	$valid = [ 7 => '', 30 => '', 90 => '', 0 => '' ];
	if ( ! isset( $_GET['days'] ) ) {
		return get_default_period();
	}
	$days = (int) sanitize_text_field( wp_unslash( $_GET['days'] ) );
	return array_key_exists( $days, $valid ) ? $days : get_default_period();
}

/**
 * Render the Search Analytics dashboard page.
 *
 * @since 1.0.0
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
	$terms_h = max( count( $terms ) * 28 + 40, 120 );
	$daily_rows = array_slice( $data['daily_counts'], 0, get_daily_limit() );
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
						<?php if ( empty( $daily_rows ) ) : ?>
							<tr><td colspan="2" class="sa-zero"><?php esc_html_e( 'No data', 'community-code' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $daily_rows as $bucket ) : ?>
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
			<?php $recent = query_recent_searches( $days, get_rows_per_page() ); ?>
			<?php if ( empty( $recent ) ) : ?>
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
