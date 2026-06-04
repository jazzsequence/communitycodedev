<?php
/**
 * Plugin Name: Search Analytics
 * Description: Logs anonymous search queries to a custom database table and provides a dashboard to view search trends.
 * Author: Chris Reynolds
 * License: MIT
 */

namespace CommunityCode\SearchAnalytics;

const ADMIN_SLUG = 'cc-search-analytics';
const DB_VERSION = '1.1';
const DB_VERSION_OPTION = 'cc_search_analytics_db_version';

define( 'CC_SEARCH_ANALYTICS_DIR', __DIR__ . '/' );
define( 'CC_SEARCH_ANALYTICS_URL', str_replace( WP_CONTENT_DIR, WP_CONTENT_URL, __DIR__ ) . '/' );

require_once CC_SEARCH_ANALYTICS_DIR . 'includes/database.php';
require_once CC_SEARCH_ANALYTICS_DIR . 'includes/data.php';
require_once CC_SEARCH_ANALYTICS_DIR . 'includes/tracking.php';
require_once CC_SEARCH_ANALYTICS_DIR . 'includes/admin.php';

// Database
add_action( 'init', __NAMESPACE__ . '\\maybe_create_table' );
add_action( 'init', __NAMESPACE__ . '\\schedule_cleanup' );
add_action( 'cc_search_analytics_cleanup', __NAMESPACE__ . '\\run_cleanup' );

// Tracking — priority 20 runs after Pantheon's MU plugin sets EP_DIRECT_HOST at priority 10.
add_action( 'wp', __NAMESPACE__ . '\\maybe_track_standard_search' );
add_action( 'rest_api_init', __NAMESPACE__ . '\\register_ep_proxy_endpoint' );
add_filter( 'ep_instant_results_search_endpoint', __NAMESPACE__ . '\\redirect_ep_to_proxy', 20 );

// Admin
add_action( 'admin_menu', __NAMESPACE__ . '\\register_admin_page' );
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_admin_assets' );

// Temporary diagnostic — remove before merge.
add_action( 'rest_api_init', function () {
	register_rest_route( 'community-code/v1', '/ip-diag', [
		'methods'             => 'GET',
		'callback'            => function () {
			$keys = [
				'REMOTE_ADDR',
				'HTTP_X_FORWARDED_FOR',
				'HTTP_CF_CONNECTING_IP',
				'HTTP_CF_IPCOUNTRY',
				'HTTP_TRUE_CLIENT_IP',
				'HTTP_X_REAL_IP',
				'HTTP_X_CLUSTER_CLIENT_IP',
			];
			$out = [];
			foreach ( $keys as $k ) {
				$out[ $k ] = $_SERVER[ $k ] ?? '(not set)';
			}
			return $out;
		},
		'permission_callback' => '__return_true',
	] );
} );
