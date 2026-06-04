<?php
/**
 * Analytics data query layer.
 *
 * @package CommunityCode\SearchAnalytics
 */

namespace CommunityCode\SearchAnalytics;

/**
 * Return cached analytics data for the given window.
 *
 * Uses a static cache so enqueue_admin_assets() and render_admin_page() share
 * a single database round-trip per request.
 *
 * @since 1.0.0
 *
 * @param int $days Number of days to look back. 0 means all time.
 * @return array{top_terms: array, daily_counts: array, total: int, unique: int}
 */
function get_analytics_data( int $days ): array {
	static $cache = [];
	if ( ! isset( $cache[ $days ] ) ) {
		$cache[ $days ] = query_db( $days );
	}
	return $cache[ $days ];
}

/**
 * Query the database for aggregated search analytics.
 *
 * Returns arrays shaped to match the former Elasticsearch response so the
 * admin UI needs no changes: top_terms items have `key`/`doc_count` keys;
 * daily_counts items have `key_as_string`/`doc_count` keys.
 *
 * @since 1.0.0
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
		// Stored DESC (most-recent-first) for the table; enqueue_admin_assets() reverses for the chart.
		'top_terms' => $top_terms,
		'daily_counts' => $daily_counts,
		'total' => $total,
		'unique' => $unique,
	];
}
