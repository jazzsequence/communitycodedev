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
}

add_action( 'init', __NAMESPACE__ . '\\init' );
