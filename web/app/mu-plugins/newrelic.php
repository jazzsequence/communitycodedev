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
    // Debug.
	echo "\n<!-- NR diag: ext=";
    echo (int)extension_loaded('newrelic');
    echo " fn=";
    echo (int)function_exists('newrelic_get_browser_timing_header');
    echo " -->\n";
	if ( function_exists( 'newrelic_get_browser_timing_header' ) ) {
		$raw = newrelic_get_browser_timing_header();
        echo "\n<!-- NR diag header length: ".strlen($raw)." -->\n";
		if ( preg_match( '#<script|^>]*>(.*)</script>#is', $raw, $m ) ) {
			$js = $m[1];
			wp_print_inline_script_tag( $js, [ 'id' => 'newrelic-browser' ] );
		} else {
			echo $raw; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
}

/**
 * Add New Relic footer to the page footer.
 */
function add_newrelic_footer() {
	if ( function_exists( 'newrelic_get_browser_timing_footer' ) ) {
		$raw = newrelic_get_browser_timing_footer();
        echo "\n<!-- NR diag footer length: ".strlen($raw)." -->\n";
		if ( preg_match( '#<script|^>]*>(.*)</script>#is', $raw, $m ) ) {
			$js = $m[1];
			wp_print_inline_script_tag( $js, [ 'id' => 'newrelic-browser' ] );
		} else {
			echo $raw; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
}

// Bootstrap the plugin.
bootstrap();
