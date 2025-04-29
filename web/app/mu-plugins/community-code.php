<?php
/**
 * Plugin Name: Community + Code customizations
 * Author: Chris Reynolds
 * License: MIT License
 */

 add_action( 'customize_register', '__return_true' );
 add_action( 'init', function() {
     if ( current_theme_supports( 'customizer' ) ) {
         add_theme_support( 'custom-css' );
     }
 });
