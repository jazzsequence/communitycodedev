<?php
/**
 * Plugin Name: Community + Code customizations
 * Author: Chris Reynolds
 * License: MIT License
 */

namespace CommunityCode\MuPlugin;

/**
 * Kick everything off.
 */
function init() {
	// Add the customizer for CSS controls.
	add_action( 'customize_register', '__return_true' );
	add_action( 'init', function() {
		if ( current_theme_supports( 'customizer' ) ) {
			add_theme_support( 'custom-css' );
		}
	});

    add_action( 'init', __NAMESPACE__ . '\\register_episodes' );

    add_filter( 'powerpress_post_types', function( $post_types ) {
        $post_types[] = 'episodes'; // Allow PowerPress fields on episodes
        return $post_types;
    });
}

/**
 * Register Episodes post type.
 */
function register_episodes() {
	// Create an episodes post type for podcast episodes. Slug will be /episodes/ and permalink structure should be /episodes/year/month/title.
	$labels = array(
		'name' => _x( 'Episodes', 'post type general name', 'community-code' ),
		'singular_name' => _x( 'Episode', 'post type singular name', 'community-code' ),
		'menu_name' => _x( 'Episodes', 'admin menu', 'community-code' ),
		'add_new_item' => __( 'Add New Episode', 'community-code' ),
		'new_item' => __( 'New Episode', 'community-code' ),
		'edit_item' => __( 'Edit Episode', 'community-code' ),
		'view_item' => __( 'View Episode', 'community-code' ),
		'all_items' => __( 'All Episodes', 'community-code' ),
		'search_items' => __( 'Search Episodes', 'community-code' ),
		'parent_item_colon' => __( 'Parent Episodes:', 'community-code' ),
		'not_found' => __( 'No episodes found.', 'community-code' ),
		'not_found_in_trash' => __( 'No episodes found in Trash.', 'community-code' ),
	);

	$args = [
		'labels' => $labels,
		'public' => true,
		'publicly_queryable' => true,
		'show_ui' => true,
		'show_in_menu' => true,
		'query_var' => true,
		'rewrite' => [
            'slug' => 'episodes',
            'with_front' => false
        ],
		'capability_type' => 'post',
		'has_archive' => true,
		'hierarchical' => false,
		'menu_position' => 2,
		'supports' => [ 'title', 'editor', 'author', 'thumbnail', 'excerpt', 'comments' ],
        'show_in_rest' => true,
        'menu_icon' => 'dashicons-microphone',
        'feeds' => true,
    ];

    register_post_type( 'episodes', $args );
}

init();
