<?php
/**
 * Plugin Name: Community + Code Progress Planner customizations
 * Author: Chris Reynolds
 * License: MIT License
 * Description: Customizations for the Community + Code implementation of the Progress Planner plugin.
 */

namespace CommunityCode\ProgressPlanner;

/**
 * Kick everything off.
 */
function init() {
    // Expose the Progress Planner CPTUI.
    add_filter( 'progress_planner_tasks_show_ui', '__return_true' );
    add_filter( 'register_post_type_args', function( $args, $post_type ) {
        if ( 'prpl_recommendations' === $post_type ) {
            $args['show_ui'] = true;
            $args['show_in_menu'] = true;
            $args['map_meta_cap'] = true;
            $args['labels'] = array(
                'name' => 'Tasks',
                'singular_name' => 'Task',
                'menu_name' => 'Tasks',
                'all_items' => 'All Tasks',
                'add_new' => 'Add New',
                'add_new_item' => 'Add New Task',
                'edit_item' => 'Edit Task',
                'new_item' => 'New Task',
                'view_item' => 'View Task',
                'search_items' => 'Search Tasks',
                'not_found' => 'No tasks found',
                'not_found_in_trash' => 'No tasks found in trash'
            );
        }
        return $args;
    }, 5, 2 );
    
    // Customize row actions for tasks
    add_filter( 'post_row_actions', function( $actions, $post ) {
        if ( 'prpl_recommendations' === $post->post_type && isset( $actions['trash'] ) ) {
            $trash_url = get_delete_post_link( $post->ID );
            $actions['trash'] = sprintf(
                '<a href="%s" class="submitdelete">Complete</a>',
                $trash_url
            );
        }
        return $actions;
    }, 99, 2 );
    
    // Also try targeting the page-specific filter
    add_filter( 'page_row_actions', function( $actions, $post ) {
        if ( 'prpl_recommendations' === $post->post_type && isset( $actions['trash'] ) ) {
            $trash_url = get_delete_post_link( $post->ID );
            $actions['trash'] = sprintf(
                '<a href="%s" class="submitdelete">Complete</a>',
                $trash_url
            );
        }
        return $actions;
    }, 99, 2 );
}

add_action( 'init', __NAMESPACE__ . '\\init', 0 );
