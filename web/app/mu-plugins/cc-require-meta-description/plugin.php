<?php
/**
 * Plugin Name: Community Code Require Meta Description
 * Description: Blocks publishing or scheduling until the Yoast SEO meta description is filled in.
 * Version: 1.0.0
 * Author: Chris Reynolds
 * Licence: MIT
 * Text Domain: community-code
 */

namespace Community_Code\Require_Meta_Description;

const SCRIPT_HANDLE = 'cc-require-meta-description';

/**
 * Kick things off.
 */
function init() {
	add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\\enqueue_gate' );
}
init();

/**
 * Post types that need a meta description before they can go live.
 *
 * The Yoast description is the canonical short description for this site -- it
 * feeds the ElasticPress index and the syndication that announces new episodes.
 * When it is empty the syndication falls back to raw post content, which is how
 * a YouTube URL, a [powerpress] shortcode and the show-notes link dump ended up
 * as the body of a LinkedIn post.
 *
 * @return string[] Post type slugs.
 */
function get_gated_post_types() : array {
	return (array) apply_filters( 'cc_meta_description_required_post_types', [ 'episodes', 'post' ] );
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

	$path = __DIR__ . '/js/gate.js';

	wp_enqueue_script(
		SCRIPT_HANDLE,
		plugins_url( 'js/gate.js', __FILE__ ),
		[ 'wp-data', 'wp-dom-ready', 'wp-i18n', 'wp-editor' ],
		file_exists( $path ) ? (string) filemtime( $path ) : '1.0.0',
		true
	);

	if ( function_exists( 'wp_set_script_translations' ) ) {
		wp_set_script_translations( SCRIPT_HANDLE, 'community-code' );
	}
}
