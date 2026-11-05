<?php
/**
 * Plugin Name: Community + Code P1 Forms
 * Description: Authenticated REST receivers for form submissions posted server-side by the headless P1 frontend (contact and newsletter).
 * Author: Chris Reynolds
 * License: MIT
 *
 * Why this exists: the P1 frontend cannot reuse the Ninja Forms AJAX endpoint (page-specific
 * nonces) or its reCAPTCHA (bound to communitycode.dev). Instead P1's server posts here with a
 * shared secret. Rate limiting and bot checks live on the P1 side by design.
 *
 * Loaded by the Bedrock autoloader (bedrock-autoloader.php), which picks up plugin-headed files
 * one directory deep in mu-plugins. Add another route by dropping a file into includes/,
 * requiring it below and registering it in register_routes().
 */

namespace CommunityCode\P1Forms;

const REST_NAMESPACE = 'community-code/v1';

/**
 * Full REST routes (as WP_REST_Request::get_route() reports them) that this plugin owns, mapped to
 * the constant holding that route's bearer secret. The shared auth/response shaping only touches
 * these routes. One secret per route limits the blast radius of a leak.
 */
const ROUTES = [
	'/community-code/v1/contact'    => 'CC_P1_CONTACT_SECRET',
	'/community-code/v1/newsletter' => 'CC_P1_NEWSLETTER_SECRET',
];

require_once __DIR__ . '/includes/rest.php';
require_once __DIR__ . '/includes/contact.php';
require_once __DIR__ . '/includes/newsletter.php';

add_action( 'rest_api_init', __NAMESPACE__ . '\\register_routes' );
add_filter( 'rest_request_before_callbacks', __NAMESPACE__ . '\\authorize_before_validation_errors', 10, 3 );
add_filter( 'rest_post_dispatch', __NAMESPACE__ . '\\shape_response', 10, 3 );

/**
 * Register every route this plugin owns.
 */
function register_routes(): void {
	register_contact_route();
	register_newsletter_route();
}
