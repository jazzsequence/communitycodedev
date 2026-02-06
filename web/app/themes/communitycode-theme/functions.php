<?php
/**
 * Community + Code Theme Functions
 *
 * @package CommunityCode
 */

namespace CommunityCode\Theme;

/**
 * Enqueue parent and child theme styles
 */
function enqueue_styles() {
	// Enqueue parent theme styles
	wp_enqueue_style(
		'twentytwentyfive-style',
		get_template_directory_uri() . '/style.css',
		array(),
		wp_get_theme()->parent()->get( 'Version' )
	);

	// Enqueue child theme styles (compiled from SCSS)
	wp_enqueue_style(
		'communitycode-style',
		get_stylesheet_directory_uri() . '/style.css',
		array( 'twentytwentyfive-style' ),
		wp_get_theme()->get( 'Version' )
	);
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_styles' );

/**
 * Enqueue Google Fonts
 */
function enqueue_fonts() {
	// Victor Mono for headings and code
	wp_enqueue_style(
		'victor-mono',
		'https://fonts.googleapis.com/css2?family=Victor+Mono:wght@400;500;600;700&display=swap',
		array(),
		null
	);

	// Source Sans 3 for body text
	wp_enqueue_style(
		'source-sans-3',
		'https://fonts.googleapis.com/css2?family=Source+Sans+3:wght@300;400;600;700&display=swap',
		array(),
		null
	);
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_fonts' );

/**
 * Add editor styles
 */
function editor_styles() {
	add_editor_style( 'style.css' );
}
add_action( 'after_setup_theme', __NAMESPACE__ . '\\editor_styles' );
