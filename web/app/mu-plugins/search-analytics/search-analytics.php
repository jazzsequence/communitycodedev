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
require_once CC_SEARCH_ANALYTICS_DIR . 'includes/suggestions.php';
require_once CC_SEARCH_ANALYTICS_DIR . 'includes/admin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once CC_SEARCH_ANALYTICS_DIR . 'includes/cli.php';
	\WP_CLI::add_command( 'cc-analytics tag-gaps', 'CommunityCode\SearchAnalytics\CLI\Tag_Gaps_Command' );
}

// Database
add_action( 'init', __NAMESPACE__ . '\\maybe_create_table' );
add_action( 'init', __NAMESPACE__ . '\\schedule_cleanup' );
add_action( 'cc_search_analytics_cleanup', __NAMESPACE__ . '\\run_cleanup' );

// Tracking — priority 20 runs after Pantheon's MU plugin sets EP_DIRECT_HOST at priority 10.
add_action( 'wp', __NAMESPACE__ . '\\maybe_track_standard_search' );
add_action( 'rest_api_init', __NAMESPACE__ . '\\register_ep_proxy_endpoint' );
add_filter( 'ep_instant_results_search_endpoint', __NAMESPACE__ . '\\redirect_ep_to_proxy', 20 );

// Admin
add_action( 'admin_init', __NAMESPACE__ . '\\handle_screen_options' );
add_filter( 'set-screen-option', __NAMESPACE__ . '\\save_screen_option', 10, 3 );
add_action( 'admin_menu', __NAMESPACE__ . '\\register_admin_page' );
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_admin_assets' );
add_action( 'wp_ajax_cc_create_analytics_tag', __NAMESPACE__ . '\\handle_create_analytics_tag' );
