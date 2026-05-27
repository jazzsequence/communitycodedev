<?php
/**
 * Plugin Name:       AI Connector Secure Layer
 * Plugin URI:        https://github.com/jazzsequence/ai-connector-secure-layer
 * Description:       Keeps LLM API keys out of the WordPress database. Fetches keys from Pantheon Secrets or environment variables on-demand at request time — never stored in wp_options, never pre-loaded as PHP constants. Compatible with WordPress 7.0 AI Connectors.
 * Version:           1.0.0
 * Requires at least: 7.0
 * Requires PHP:      8.2
 * Author:            Chris Reynolds
 * Author URI:        https://next.jazzsequence.com
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       ai-connector-secure-layer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AICSL_VERSION', '1.0.0' );

require_once __DIR__ . '/includes/secrets.php';
require_once __DIR__ . '/includes/class-lazy-auth.php';
require_once __DIR__ . '/includes/connectors.php';

// During init:15 (_wp_connectors_init), register pre_update_option hooks to block DB writes.
add_action( 'wp_connectors_init', 'AICSL\Connectors\on_connectors_init' );

// After init:20 (_wp_connectors_pass_default_keys_to_ai_client), inject lazy auth.
add_action( 'init', 'AICSL\Connectors\inject_lazy_auth', 21 );

// Override keySource/isConnected for the Connectors admin JS — priority 11 runs after WP's 10.
add_filter( 'script_module_data_options-connectors-wp-admin', 'AICSL\Connectors\filter_script_module_data', 11 );

// Terminus instructions above the Connectors page SPA for unconfigured providers.
add_action( 'admin_notices', 'AICSL\Connectors\show_admin_notices' );

/*
 * Tell the WordPress AI plugin that credentials are available when keys come
 * from Pantheon Secrets rather than wp_options (which we intentionally block).
 */
add_filter( 'wpai_has_ai_credentials', 'AICSL\Connectors\filter_has_ai_credentials', 10, 2 );
