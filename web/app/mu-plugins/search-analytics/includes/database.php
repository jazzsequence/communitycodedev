<?php
/**
 * Database operations: table creation, scheduled cleanup, and search event writes.
 *
 * @package CommunityCode\SearchAnalytics
 */

namespace CommunityCode\SearchAnalytics;

/**
 * Return the fully-qualified analytics table name for the current site.
 *
 * @since 1.0.0
 *
 * @return string Table name including the $wpdb prefix.
 */
function get_table_name(): string {
	global $wpdb;
	return $wpdb->prefix . 'cc_search_analytics';
}

/**
 * Create the analytics table when the DB schema version is outdated or absent.
 *
 * Runs on `init`. The DB_VERSION_OPTION option gates execution so dbDelta()
 * only fires once per schema version, not on every request.
 *
 * @since 1.0.0
 *
 * @return void
 */
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
		country VARCHAR(2) NOT NULL DEFAULT '',
		user_agent VARCHAR(500) NOT NULL DEFAULT '',
		referrer VARCHAR(500) NOT NULL DEFAULT '',
		PRIMARY KEY (id),
		KEY term (term(50)),
		KEY searched_at (searched_at)
	) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
	update_option( DB_VERSION_OPTION, DB_VERSION );
}

/**
 * Schedule the daily row-pruning cron event if it is not already queued.
 *
 * Runs on `init`.
 *
 * @since 1.0.0
 *
 * @return void
 */
function schedule_cleanup(): void {
	if ( ! wp_next_scheduled( 'cc_search_analytics_cleanup' ) ) {
		wp_schedule_event( time(), 'daily', 'cc_search_analytics_cleanup' );
	}
}

/**
 * Delete analytics rows older than 90 days.
 *
 * Fires on the `cc_search_analytics_cleanup` cron action, scheduled daily
 * by schedule_cleanup().
 *
 * @since 1.0.0
 *
 * @return void
 */
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

/**
 * Resolve a two-letter ISO country code for the current visitor.
 *
 * Pantheon exposes the real client IP in REMOTE_ADDR (via Cloudflare's
 * CF-Connecting-IP). CF-IPCountry is not forwarded at the GCDN layer, so
 * we fall back to a lightweight lookup against ipinfo.io's free API (50k
 * requests/month, returns just the 2-char country code). The call uses a
 * short timeout so a slow or failing API response never blocks the
 * tracker for more than 2 seconds.
 *
 * @since 1.1.0
 *
 * @return string Two-letter uppercase country code, or empty string on failure.
 */
function get_country_from_request(): string {
	// CF-IPCountry first (works if Pantheon ever forwards it).
	$country = strtoupper( substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '' ) ), 0, 2 ) );
	if ( $country && $country !== 'XX' && $country !== 'T1' ) {
		return $country;
	}

	// Pantheon sets REMOTE_ADDR to the real client IP via Cloudflare.
	$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
	if ( ! $ip || $ip === '127.0.0.1' ) {
		return '';
	}

	$response = wp_remote_get(
		'https://ipinfo.io/' . rawurlencode( $ip ) . '/country',
		[ 'timeout' => 2 ]
	);

	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return '';
	}

	$code = strtoupper( trim( wp_remote_retrieve_body( $response ) ) );
	return ( strlen( $code ) === 2 ) ? $code : '';
}

/**
 * Insert a single search event into the analytics table.
 *
 * Captures country (from Cloudflare's CF-IPCountry header), raw user agent,
 * and referrer from the current request at write time. These are stored
 * alongside the term so individual searches can be reviewed for bot/human
 * signals on the admin dashboard.
 *
 * @since 1.0.0
 *
 * @param string $term          The search term entered by the visitor.
 * @param int    $results_count Number of results returned for the search.
 * @return void
 */
function write_to_db( string $term, int $results_count ): void {
	global $wpdb;

	$country = get_country_from_request();
	$user_agent = substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 500 );
	$referrer = substr( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ?? '' ) ), 0, 500 );

	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		get_table_name(),
		[
			'term' => $term,
			'results' => $results_count,
			'searched_at' => gmdate( 'Y-m-d H:i:s' ),
			'country' => $country,
			'user_agent' => $user_agent,
			'referrer' => $referrer,
		],
		[ '%s', '%d', '%s', '%s', '%s', '%s' ]
	);
}
