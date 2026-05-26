<?php
/**
 * Plugin Name:       AI Connector Secure Layer
 * Plugin URI:        https://github.com/jazzsequence/ai-connector-secure-layer
 * Description:       Browser-key-protected LLM API key storage. Encrypts keys client-side with AES-256-GCM; the server stores only ciphertext. Compatible with WordPress AI Connectors (WP 7.0+).
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Chris Reynolds
 * Author URI:        https://github.com/jazzsequence
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       ai-connector-secure-layer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AICSL_VERSION', '0.1.0' );

require_once __DIR__ . '/includes/crypto.php';
require_once __DIR__ . '/includes/rest-api.php';
require_once __DIR__ . '/includes/admin.php';

add_action( 'rest_api_init', 'AICSL\REST_API\register_routes' );
add_action( 'admin_menu', 'AICSL\Admin\register_menu' );
add_action( 'admin_enqueue_scripts', 'AICSL\Admin\enqueue_scripts' );
