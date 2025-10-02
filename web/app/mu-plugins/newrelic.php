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
		echo "\n<!-- NR diag {$where} function {$fn} does not exist -->\n";
		return;
	}

	// 1) Try JS-only (no <script> wrapper).
	$js_only = $fn( false );
	echo "\n<!-- NR diag {$where}: js_only len " . strlen( (string) $js_only ) . " -->\n";

	if ( $js_only !== '' ) {
		// Print inside a proper <script> tag (no raw JS echo).
		wp_print_inline_script_tag( $js_only, [ 'id' => "newrelic-browser-$where" ] );
		return;
	}

	// 2) Fallback: try wrapped (<script>…</script>) and emit it as-is so it executes.
	$wrapped = $fn( true );
	echo "\n<!-- NR diag {$where}: wrapped len " . strlen( (string) $wrapped ) . " -->\n";

	if ( $wrapped !== '' ) {
		// DO NOT strip the tag here; print the full tag so the code actually runs.
		echo $wrapped; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return;
	}

	// 3) Nothing available.
	echo "\n<!-- NR diag {$where}: no snippet returned -->\n";
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
