<?php
/**
 * REST API endpoints for AI Connector Secure Layer.
 *
 * POST /aicsl/v1/setup    — Store encrypted API key ciphertext (admin only).
 * POST /aicsl/v1/complete — Decrypt key per-request and proxy to LLM API (admin only).
 */

namespace AICSL\REST_API;

use WP_REST_Request;
use WP_REST_Response;

function register_routes(): void {
	register_rest_route(
		'aicsl/v1',
		'/setup',
		[
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\handle_setup',
			'permission_callback' => __NAMESPACE__ . '\require_admin',
			'args'                => [
				'ciphertext' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
				'iv'         => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		]
	);

	register_rest_route(
		'aicsl/v1',
		'/complete',
		[
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\handle_complete',
			'permission_callback' => __NAMESPACE__ . '\require_admin',
			'args'                => [
				'prompt' => [
					'required' => true,
					'type'     => 'string',
				],
			],
		]
	);
}

function require_admin(): bool {
	return current_user_can( 'manage_options' );
}

function handle_setup( WP_REST_Request $request ): WP_REST_Response {
	update_option( 'aicsl_ciphertext', $request['ciphertext'], false );
	update_option( 'aicsl_iv', $request['iv'], false );

	return new WP_REST_Response( [ 'success' => true ] );
}

function handle_complete( WP_REST_Request $request ): WP_REST_Response {
	$key_b64 = $request->get_header( 'X-AICSL-Key' );
	if ( empty( $key_b64 ) ) {
		return new WP_REST_Response( [ 'error' => 'Missing X-AICSL-Key header.' ], 400 );
	}

	$ciphertext = get_option( 'aicsl_ciphertext' );
	$iv         = get_option( 'aicsl_iv' );
	if ( ! $ciphertext || ! $iv ) {
		return new WP_REST_Response( [ 'error' => 'No API key configured. Visit Settings → AI Connector.' ], 400 );
	}

	$api_key = \AICSL\Crypto\decrypt( $key_b64, $ciphertext, $iv );
	if ( $api_key === false ) {
		return new WP_REST_Response( [ 'error' => 'Decryption failed. Re-enter your API key.' ], 401 );
	}

	$result = call_llm_api( $api_key, $request['prompt'] );

	// Zero the key string — best-effort in PHP, no guarantee the GC hasn't copied it.
	sodium_memzero( $api_key );

	return new WP_REST_Response( $result );
}

/**
 * @return array<string, mixed>
 */
function call_llm_api( string $api_key, string $prompt ): array {
	$response = wp_remote_post(
		'https://api.openai.com/v1/chat/completions',
		[
			'headers' => [
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode(
				[
					'model'    => 'gpt-4o-mini',
					'messages' => [ [ 'role' => 'user', 'content' => $prompt ] ],
				]
			),
			'timeout' => 30,
		]
	);

	if ( is_wp_error( $response ) ) {
		return [ 'error' => $response->get_error_message() ];
	}

	return json_decode( wp_remote_retrieve_body( $response ), true ) ?? [ 'error' => 'Invalid LLM response.' ];
}
