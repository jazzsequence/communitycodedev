<?php
/**
 * Plugin Name: Community + Code LinkedIn Integration
 * Author: Chris Reynolds
 * License: MIT License
 * Description: Automatically post episodes to the Community + Code LinkedIn page.
 * Version: 1.0.0
 */

namespace CommunityCode\LinkedIn;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

defined( 'LINKEDIN_CLIENT_ID' ) || define( 'LINKEDIN_CLIENT_ID', function_exists( 'pantheon_get_secret' ) ? pantheon_get_secret( 'linkedin_client_id' ) : 'your-client-id-here' );
defined( 'LINKEDIN_CLIENT_SECRET' ) || define( 'LINKEDIN_CLIENT_SECRET', function_exists( 'pantheon_get_secret' ) ? pantheon_get_secret( 'linkedin_client_secret' ) : 'your-client-secret-here' );
defined( 'LINKEDIN_REDIRECT_URI' ) || define( 'LINKEDIN_REDIRECT_URI', 'https://communitycode.dev/wp-json/communitycode-linkedin/v1/oauth/callback' );
defined( 'LINKEDIN_ORG_ID' ) || define( 'LINKEDIN_ORG_ID', function_exists( 'pantheon_get_secret' ) ? pantheon_get_secret( 'linkedin_org_id' ) :'your-organization-id-here' );

const TR_ACCESS = 'cc_li_access_token'; // Transient key for access token.
const TR_REFRESH = 'cc_li_refresh_token'; // Transient key for refresh token.
const VERSION_HDR = '202510'; // LinkedIn API version header.
const RESTLI_VER = '2.0.0'; // LinkedIn REST version.
const API_BASE = 'https://api.linkedin.com/rest';
const MSG_TPL = 'New episode published of Community + Code: {title}\n{permalink}'; // Message template for posts.
const CPTS = [ 'post', 'episode' ]; // Post types to monitor.
const EXP_BUFFER = 120; // Expiration buffer expiry time in seconds.

/**
 * Initialize the plugin.
 */
function bootstrap() {
    add_action( 'rest_api_init', __NAMESPACE__ . '\register_rest_routes' );
    add_action( 'transition_post_status', __NAMESPACE__ . '\\on_post_publish', 10, 3 );
}

function register_rest_routes() {
    register_rest_route( 'communitycode-linkedin/v1', '/oauth/start', [
        'methods' => 'GET',
        'callback' => __NAMESPACE__ . '\oauth_start',
        'permission_callback' => __NAMESPACE__ . '\rest_oauth_permission_callback',
    ] );

    register_rest_route( 'communitycode-linkedin/v1', '/oauth/callback', [
        'methods' => 'GET',
        'callback' => __NAMESPACE__ . '\oauth_callback',
        'permission_callback' => '__return_true',
    ] );
}

function rest_oauth_permission_callback() {
    return current_user_can( 'manage_options' );
}

function oauth_start() {
    if ( ! LINKEDIN_CLIENT_ID || LINKEDIN_CLIENT_ID === 'your-client-id-here' ) {
        return new \WP_Error( 'missing_client_id', 'LinkedIn Client ID is not configured.', [ 'status' => 500 ] );
    }

    if ( ! LINKEDIN_REDIRECT_URI || LINKEDIN_REDIRECT_URI === 'https://your-redirect-uri-here' ) {
        return new \WP_Error( 'missing_redirect_uri', 'LinkedIn Redirect URI is not configured.', [ 'status' => 500 ] );
    }

    $state = wp_create_nonce( 'cc_li_state' );
    $params = [
        'response_type' => 'code',
        'client_id' => LINKEDIN_CLIENT_ID,
        'redirect_uri' => LINKEDIN_REDIRECT_URI,
        'state' => $state,
        'scope' => 'openid profile email w_organization_social',
    ];

    return wp_redirect( 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ) );
}

function oauth_callback( \WP_REST_Request $req ) {
    $code = $req->get_param( 'code' );
    $state = $req->get_param( 'state' );

    if ( ! $code || ! wp_verify_nonce( $state, 'cc_li_state' ) ) {
        return new \WP_Error( 'invalid_oauth_response', 'Invalid OAuth response.', [ 'status' => 400 ] );
    }

    $resp = wp_remote_post( 'https://www.linkedin.com/oauth/v2/accessToken', [
        'timeout' => 30,
        'body' => [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => LINKEDIN_REDIRECT_URI,
            'client_id' => LINKEDIN_CLIENT_ID,
            'client_secret' => LINKEDIN_CLIENT_SECRET,
        ],
    ] );

    if ( is_wp_error( $resp ) ) {
        return new \WP_Error( 'token_request_failed', 'Failed to request access token.' . $resp->get_error_message(), [ 'status' => 500 ] );
    }

    $data = json_decode( wp_remote_retrieve_body( $resp ), true );
    if ( empty( $data['access_token'] ) || empty( $data['expires_in'] ) ) {
        return new \WP_Error( 'invalid_token_response', 'Invalid token response from LinkedIn.', [ 'status' => 500 ] );
    }

    // Store tokens in transients.
    $expires_in = (int) $data['expires_in'] ?? 3600;
    set_transient( TR_ACCESS, [
        'access_token' => $data['access_token'],
        'expires_at' => time() + $expires_in,
    ], $expires_in - EXP_BUFFER );

    if ( ! empty( $data['refresh_token'] ) ) {
        set_transient( TR_REFRESH, [
            'refresh_token' => $data['refresh_token'],
        ], YEAR_IN_SECONDS );
    } elseif ( SEED_REFRESH_TOKEN ) {
        set_transient( TR_REFRESH, [
            'refresh_token' => SEED_REFRESH_TOKEN,
        ], YEAR_IN_SECONDS );
    }

    return new \WP_REST_Response( '<html><body><p>LinkedIn connected. You can close this tab.</p></body></html>', 200 );
}

function get_access_token() {
    $token = get_transient( TR_ACCESS );
    if ( is_array( $token ) && ! empty( $token['access_token'] ) && time() < (int) $token['expires_at'] - EXP_BUFFER ) {
        return $token['access_token'];
    }

    // Try to refresh the transient.
    $refresh = get_transient( TR_REFRESH );
    if ( ! $refresh ) {
        // No refresh token available. You need to seed one or reconnect.
        error_log( '[CommunityCode\LinkedIn] No refresh token available. Please reconnect LinkedIn.' );
        return null;
    }

    if ( ! LINKEDIN_CLIENT_ID || ! LINKEDIN_CLIENT_SECRET ) {
        error_log( '[CommunityCode\LinkedIn] LinkedIn Client ID or Client Secret is not configured.' );
        return null;
    }

    $response = wp_remote_post( 'https://www.linkedin.com/oauth/v2/accessToken', [
        'timeout' => 30,
        'body' => [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh['refresh_token'],
            'client_id' => LINKEDIN_CLIENT_ID,
            'client_secret' => LINKEDIN_CLIENT_SECRET,
        ],
    ] );

    if ( is_wp_error( $response ) ) {
        error_log( '[CommunityCode\LinkedIn] Failed to refresh access token: ' . $response->get_error_message() );
        return null;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( $code < 200 || $code >= 300 || empty( $data['access_token'] ) ) {
        error_log( '[CommunityCode\LinkedIn] Invalid response while refreshing access token: ' . wp_remote_retrieve_body( $response ) );
        return null;
    }

    $ttl = (int) $data['expires_in'] ?? 3600;
    set_transient( TR_ACCESS, [
        'access_token' => $data['access_token'],
        'expires_at' => time() + $ttl,
    ], $ttl - EXP_BUFFER );

    return $data['access_token'];
}

function build_message( \WP_Post $post ) {
    $title = get_the_title( $post );
    $permalink = get_permalink( $post );
    return strtr( MSG_TPL, [
        '{title}' => $title,
        '{permalink}' => $permalink,
    ] );
}

function on_post_publish( $new, $old, $post ) {
    if ( $new !== 'publish' || $old === 'publish' ) {
        return;
    }

    if ( wp_is_post_revision( $post->ID ) ) {
        return;
    }

    if ( ! in_array( $post->post_type, CPTS, true ) ) {
        return;
    }

    $token = get_access_token();
    if ( ! $token ) {
        error_log( '[CommunityCode\LinkedIn] No valid access token available. Cannot post to LinkedIn.' );
        return;
    }

    $payload = [
        'author' => 'urn:li:organization:' . LINKEDIN_ORG_ID,
        'commentary' => build_message( $post ),
        'visibility' => 'PUBLIC',
        'distribution' => [
            'feedDistribution' => 'MAIN_FEED',
        ],
        'content' => [
            'contentEntities' => [
                [
                    'entityLocation' => get_permalink( $post ),
                    'thumbnails' => [
                        [
                            'resolvedUrl' => get_the_post_thumbnail_url( $post, 'full' ),
                        ],
                    ],
                ],
            ],
        ],
        'lifecycleState' => 'PUBLISHED',
    ];

    $response = wp_remote_post( API_BASE . '/posts', [
        'timeout' => 30,
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'X-Restli-Protocol-Version' => RESTLI_VER,
            'LinkedIn-Version' => VERSION_HDR,
            'Content-Type' => 'application/json',
        ],
        'body' => wp_json_encode( $payload ),
    ] );

    if ( is_wp_error( $response ) ) {
        error_log( '[CommunityCode\LinkedIn] Failed to post to LinkedIn: ' . $response->get_error_message() );
        return;
    }

    $code = wp_remote_retrieve_response_code( $response );

    if ( $code < 200 || $code >= 300 ) {
        error_log( '[CommunityCode\LinkedIn] Invalid response while posting to LinkedIn: ' . wp_remote_retrieve_body( $response ) );
        return;
    }

    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[CommunityCode\LinkedIn] Posted to LinkedIn: ' . wp_remote_retrieve_body( $response ) );
    }
}

// Kick things off.
bootstrap();
