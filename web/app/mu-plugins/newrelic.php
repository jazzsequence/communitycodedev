<?php
/**
 * Plugin Name: New Relic integration
 * Author: Chris Reynolds
 * License: MIT License
 * Description: Modifies the New Relic integration to be more compatible with OpenGraph.
 * Plugin URI: https://communitycode.dev
 * Author URI: https://chrisreynolds.io
 * Version: 0.1
 */

namespace CommunityCode\NewRelic;

/**
 * Kick everything off.
 */
function bootstrap() {
    add_action( 'wp_head', __NAMESPACE__ . '\\add_newrelic_headers', 99 );
    add_action( 'wp_footer', __NAMESPACE__ . '\\add_newrelic_footer', 99 );
}

/**
 * Add New Relic headers to the page head.
 */
function add_newrelic_headers() {
    if ( function_exists( 'newrelic_get_browser_timing_header' ) ) {
        echo newrelic_get_browser_timing_header();
    }
}

/**
 * Add New Relic footer to the page footer.
 */
function add_newrelic_footer() {
    if ( function_exists( 'newrelic_get_browser_timing_footer' ) ) {
        echo newrelic_get_browser_timing_footer();
    }
}

// Bootstrap the plugin.
bootstrap();
