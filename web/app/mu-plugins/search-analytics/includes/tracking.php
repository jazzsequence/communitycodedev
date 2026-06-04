<?php
/**
 * Search tracking: bot detection, standard WP search, and ElasticPress proxy.
 *
 * @package CommunityCode\SearchAnalytics
 */

namespace CommunityCode\SearchAnalytics;

/**
 * Determine whether the current request appears to originate from a bot or crawler.
 *
 * Checks a set of common bot-related strings against the User-Agent header.
 * This is intentionally lightweight — it is not an exhaustive bot fingerprint.
 *
 * @since 1.0.0
 *
 * @return bool True if the request looks like a bot, false otherwise.
 */
function is_bot(): bool {
	$agent = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ) );
	// 'WordPress/' matches WP's own HTTP client (loopback/cron requests like "WordPress/7.0; https://...").
	foreach ( [ 'bot', 'crawl', 'spider', 'slurp', 'mediapartners', 'wget', 'curl', 'wordpress/' ] as $indicator ) {
		if ( str_contains( $agent, $indicator ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Track a standard WordPress search page load.
 *
 * Fires on the `wp` action after the main query has fully executed, which
 * makes found_posts and the search term reliably available. Skips logged-in
 * users and bot traffic so only genuine anonymous visitor searches are stored.
 *
 * @since 1.0.0
 *
 * @return void
 */
function maybe_track_standard_search(): void {
	global $wp_query;
	if ( ! $wp_query->is_search() ) {
		return;
	}
	if ( is_user_logged_in() || is_bot() ) {
		return;
	}
	$term = trim( $wp_query->get( 's' ) );
	if ( empty( $term ) ) {
		return;
	}
	write_to_db( $term, (int) $wp_query->found_posts );
}

/**
 * Return the WP REST URL of the EP Instant Results proxy endpoint.
 *
 * Hooked to `ep_instant_results_search_endpoint` at priority 20, which runs
 * after Pantheon's MU plugin sets the EP_DIRECT_HOST endpoint at priority 10.
 * Replacing the endpoint here causes EP's JavaScript to call our proxy instead
 * of the hosted-elasticpress.io URL directly, so every search passes through
 * WordPress PHP where it can be logged.
 *
 * @since 1.0.0
 *
 * @return string The WP REST URL for the proxy endpoint.
 */
function redirect_ep_to_proxy(): string {
	return rest_url( 'community-code/v1/ep-search' );
}

/**
 * Reconstruct the original EP.io direct search endpoint from platform constants.
 *
 * Uses the EP_DIRECT_HOST constant (set by Pantheon's MU plugin) and the
 * ElasticPress post index name to build the full upstream URL that the proxy
 * should forward requests to.
 *
 * @since 1.0.0
 *
 * @return string Full EP.io search URL, or empty string if EP is unavailable.
 */
function get_ep_direct_endpoint(): string {
	if ( ! defined( 'EP_DIRECT_HOST' ) || ! class_exists( '\\ElasticPress\\Indexables' ) ) {
		return '';
	}
	$index = \ElasticPress\Indexables::factory()->get( 'post' )->get_index_name();
	return $index ? EP_DIRECT_HOST . '/api/v1/search/posts/' . $index : '';
}

/**
 * Register the EP Instant Results proxy REST endpoint.
 *
 * Fires on `rest_api_init`. The endpoint is public (`__return_true`) because
 * it must be reachable by anonymous visitors whose searches we want to track.
 *
 * @since 1.0.0
 *
 * @return void
 */
function register_ep_proxy_endpoint(): void {
	register_rest_route(
		'community-code/v1',
		'/ep-search',
		[
			'methods' => \WP_REST_Server::READABLE,
			'callback' => __NAMESPACE__ . '\\handle_ep_search_proxy',
			'permission_callback' => '__return_true',
		]
	);
}

/**
 * Log the search term, then proxy the request to EP.io and return its response.
 *
 * Skips logging for logged-in users and bots. The proxy transparently forwards
 * all query parameters to EP.io so EP's JavaScript receives a response
 * identical to what it would have gotten from the direct URL.
 *
 * @since 1.0.0
 *
 * @param \WP_REST_Request $request The incoming REST request.
 * @return \WP_REST_Response|\WP_Error Search results from EP.io, or a WP_Error on failure.
 */
function handle_ep_search_proxy( \WP_REST_Request $request ) {
	$term = trim( sanitize_text_field( (string) ( $request->get_param( 'search' ) ?? '' ) ) );
	if ( mb_strlen( $term ) >= 2 && ! is_user_logged_in() && ! is_bot() ) {
		write_to_db( $term, 0 );
	}

	$ep_endpoint = get_ep_direct_endpoint();
	if ( ! $ep_endpoint ) {
		return new \WP_Error( 'ep_proxy_error', 'EP endpoint not configured.', [ 'status' => 500 ] );
	}

	$ep_url = add_query_arg( $request->get_query_params(), $ep_endpoint );
	$ep_response = wp_remote_get(
		$ep_url,
		[
			'timeout' => 10,
			'headers' => [ 'Accept' => 'application/json' ],
		]
	);

	if ( is_wp_error( $ep_response ) ) {
		return new \WP_Error( 'ep_proxy_upstream', $ep_response->get_error_message(), [ 'status' => 502 ] );
	}

	$status = wp_remote_retrieve_response_code( $ep_response );
	$data = json_decode( wp_remote_retrieve_body( $ep_response ), true );
	$response = new \WP_REST_Response( $data, $status );
	$response->header( 'Cache-Control', 'no-store' );
	return $response;
}
