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

function get_newrelic_inline( string $where ) : void {
	$fn = $where === 'footer' ? 'newrelic_get_browser_timing_footer' : 'newrelic_get_browser_timing_header';

	if ( ! function_exists( $fn ) ) {
		return;
	}

	$raw = $fn();
	echo "\n<!-- NR diag {$where} length: ".strlen($raw)." -->\n";
	if ( $raw && preg_match( '#<script\b[^>]*>([\s\S]*?)</script>#i', $raw, $m ) ) {
		$js = $m[1];
	}

	echo "\n<!-- NR diag {$where} js length: ".(empty($js) ? 0 : strlen($js))." -->\n";
	if ( ! empty( $js ) ) {
		wp_print_inline_script_tag( $js, [ 'id' => "newrelic-browser-{$where}" ] );
	}
}

/**
 * Add New Relic headers to the page head.
 */
function add_newrelic_headers() {
	get_newrelic_inline( 'header' );
}

/**
 * Add New Relic footer to the page footer.
 */
function add_newrelic_footer() {
	get_newrelic_inline( 'footer' );
}

// Bootstrap the plugin.
bootstrap();
