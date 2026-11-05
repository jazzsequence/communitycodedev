<?php
/**
 * Shared REST plumbing: bearer-secret authorisation and response shaping.
 *
 * @package CommunityCode\P1Forms
 */

namespace CommunityCode\P1Forms;

/**
 * Read the shared secret for a route.
 *
 * Each route has its own constant (see ROUTES) so a leaked contact secret cannot touch the
 * newsletter list and vice versa. Constants are defined in config/application.php (env var or
 * Pantheon Secret, same pattern as the ElasticPress credentials). The filter exists purely as a
 * seam for tests/cc-p1-forms/*; a constant cannot be un-defined or redefined.
 *
 * @param string $constant Constant name from ROUTES.
 * @return string Empty string when not configured.
 */
function get_secret( string $constant ): string {
	$secret = ( '' !== $constant && defined( $constant ) ) ? constant( $constant ) : '';
	$secret = apply_filters( 'cc_p1_forms_secret', $secret, $constant );
	return is_string( $secret ) ? trim( $secret ) : '';
}

/**
 * Shared permission_callback for every route here.
 *
 * Fails closed: with no secret configured nothing is served (503), so a missing
 * secret on a new environment can never open the endpoint to the world.
 *
 * @param \WP_REST_Request $request Incoming request.
 * @return true|\WP_Error
 */
function authorize( \WP_REST_Request $request ) {
	$secret = get_secret( ROUTES[ $request->get_route() ] ?? '' );
	if ( '' === $secret ) {
		return new \WP_Error( 'cc_p1_not_configured', 'This endpoint is not configured.', [ 'status' => 503 ] );
	}

	$header = (string) $request->get_header( 'authorization' );
	if ( 1 !== preg_match( '/^Bearer\s+(\S+)$/i', trim( $header ), $matches ) || ! hash_equals( $secret, $matches[1] ) ) {
		return new \WP_Error( 'cc_p1_unauthorized', 'Unauthorized.', [ 'status' => 401 ] );
	}

	return true;
}

/**
 * Make authorisation win over argument validation.
 *
 * Core validates args *before* it calls permission_callback and, if validation failed, skips the
 * permission check entirely. Left alone, an unauthenticated caller sending bad input would get a
 * 400 (and learn our validation rules), and an unconfigured site would answer 400 instead of 503.
 * This filter runs after validation but before the permission check, so we can replace that error.
 * permission_callback still does the same check: this is ordering, not a second source of truth.
 *
 * @param \WP_REST_Response|\WP_Error|mixed $response Current result (a validation error, or null).
 * @param array                             $handler  Matched route handler (unused).
 * @param \WP_REST_Request                  $request  Request.
 * @return \WP_REST_Response|\WP_Error|mixed
 */
function authorize_before_validation_errors( $response, $handler, $request ) {
	if ( ! isset( ROUTES[ $request->get_route() ] ) ) {
		return $response;
	}

	$auth = authorize( $request );
	return is_wp_error( $auth ) ? $auth : $response;
}

/**
 * Normalise responses for our routes: `{ "error": "<message>" }` on failure, and never cacheable.
 *
 * WP core's default error body is { code, message, data }; the P1 side expects a single
 * human-readable `error` string, so we flatten it here instead of per-route. Runs on
 * rest_post_dispatch so it also covers core-generated errors (missing params, permission failures).
 *
 * @param \WP_REST_Response|mixed $response Dispatched response.
 * @param \WP_REST_Server         $server   Server instance (unused).
 * @param \WP_REST_Request        $request  Request.
 * @return \WP_REST_Response|mixed
 */
function shape_response( $response, $server, $request ) {
	if ( ! isset( ROUTES[ $request->get_route() ] ) || ! $response instanceof \WP_REST_Response ) {
		return $response;
	}

	$response->header( 'Cache-Control', 'no-store' );

	if ( $response->is_error() ) {
		$data    = (array) $response->get_data();
		$message = isset( $data['message'] ) ? (string) $data['message'] : 'Request failed.';

		// Surface the per-field message our validate callbacks wrote rather than core's "Invalid parameter(s): name".
		if ( 'rest_invalid_param' === ( $data['code'] ?? '' ) && ! empty( $data['data']['params'] ) && is_array( $data['data']['params'] ) ) {
			$message = (string) reset( $data['data']['params'] );
		}

		$response->set_data( [ 'error' => $message ] );
	}

	return $response;
}
