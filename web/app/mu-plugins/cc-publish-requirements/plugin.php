<?php
/**
 * Plugin Name: Community Code Publish Requirements
 * Description: Blocks publishing or scheduling until the required editorial fields are filled in.
 * Version: 1.1.0
 * Author: Chris Reynolds
 * Licence: MIT
 * Text Domain: community-code
 */

namespace Community_Code\Publish_Requirements;

const SCRIPT_HANDLE = 'cc-publish-requirements';

/**
 * Kick things off.
 */
function init() {
	add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\\enqueue_gate' );
}
init();

/**
 * Post types that must satisfy the requirements before they can go live.
 *
 * The Yoast description and the YouTube URL are both consumed well outside the
 * post itself -- the description feeds the ElasticPress index and the syndication
 * that announces new episodes, the URL feeds the same syndication. When the
 * description is empty that syndication falls back to raw post content, which is
 * how a [powerpress] shortcode and a show-notes link dump ended up as the body of
 * a LinkedIn post.
 *
 * @return string[] Post type slugs.
 */
function get_gated_post_types() : array {
	return (array) apply_filters( 'cc_publish_requirements_post_types', [ 'episodes', 'post' ] );
}

/**
 * Load the editor gate on gated post types.
 */
function enqueue_gate() {
	if ( ! function_exists( 'get_current_screen' ) ) {
		return;
	}

	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->post_type, get_gated_post_types(), true ) ) {
		return;
	}

	$path = __DIR__ . '/js/requirements.js';

	wp_enqueue_script(
		SCRIPT_HANDLE,
		plugins_url( 'js/requirements.js', __FILE__ ),
		[ 'wp-components', 'wp-data', 'wp-dom-ready', 'wp-editor', 'wp-element', 'wp-i18n', 'wp-plugins' ],
		file_exists( $path ) ? (string) filemtime( $path ) : '1.1.0',
		true
	);

	if ( function_exists( 'wp_set_script_translations' ) ) {
		wp_set_script_translations( SCRIPT_HANDLE, 'community-code' );
	}
}
