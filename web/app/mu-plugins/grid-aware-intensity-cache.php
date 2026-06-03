<?php
/**
 * Plugin Name: Grid Aware Intensity – shared render-path cache
 * Description: Wraps the third-party Grid Aware WP plugin's Electricity Maps API
 *              call with a site-wide persistent cache so the front-end render path
 *              (template-canvas) no longer blocks on a per-visitor external request.
 * Author:      Community + Code
 * License:     MIT License
 *
 * WHY THIS EXISTS
 * ---------------
 * grid-aware-wp (web/app/plugins/grid-aware-wp) calls the Electricity Maps API on
 * EVERY request — once when its main file loads and again on `wp_body_open`. Its
 * own transient is keyed per visitor IP (md5 of the IP), so on a public site the
 * cache almost never hits: each new visitor triggers a fresh wp_remote_get with a
 * 10s timeout on the hot path, and upstream failures are never cached. That is the
 * tail behind the slow `WebTransaction/Action/template-canvas` p95.
 *
 * grid-aware-wp is third-party code, so instead of editing it we intercept its
 * outbound HTTP calls through the WordPress HTTP API filters and serve a single
 * site-wide cached response. mu-plugins load before regular plugins, so this
 * filter is registered before grid-aware-wp's load-time call fires.
 *
 * Trade-off: the upstream value becomes site-wide rather than per-visitor-IP. The
 * intensity is a coarse low/medium/high bucket and the plugin still exposes the
 * manual `?grid_intensity=` switcher, so a shared value is an acceptable
 * approximation in exchange for removing a per-visitor blocking external call.
 */

namespace CommunityCode\GridAwareIntensityCache;

const API_HOST    = 'api.electricitymap.org';
const CACHE_KEY   = 'cc_gaw_em_intensity_shared';
const HIT_TTL     = 600; // Match the plugin's own 10-minute freshness window.
const MISS_TTL    = 120; // Short TTL so upstream failures don't retry every request.
const LOCK_TTL    = 30;  // Brief placeholder to prevent a cold-window stampede.
const HOT_TIMEOUT = 2;   // Hard cap on render-path blocking (plugin default is 10s).

/**
 * Is this HTTP request the Electricity Maps API call we want to cache?
 *
 * @param string $url Request URL.
 * @return bool
 */
function is_target_request( $url ) : bool {
	return is_string( $url ) && false !== strpos( $url, API_HOST );
}

/**
 * Re-entrancy flag so our own cold-miss fetch isn't intercepted by short_circuit().
 *
 * @param bool|null $set Pass true/false to set, null to read.
 * @return bool
 */
function is_fetching( $set = null ) : bool {
	static $state = false;
	if ( null !== $set ) {
		$state = (bool) $set;
	}
	return $state;
}

/**
 * Build a minimal, serialize-safe HTTP response array that the plugin can parse
 * with wp_remote_retrieve_response_code() / wp_remote_retrieve_body().
 *
 * @param int    $code HTTP status code.
 * @param string $body Response body.
 * @return array
 */
function make_response( int $code, string $body ) : array {
	return [
		'headers'       => [],
		'body'          => $body,
		'response'      => [
			'code'    => $code,
			'message' => 200 === $code ? 'OK' : 'Service Unavailable',
		],
		'cookies'       => [],
		'http_response' => null,
	];
}

/**
 * A safe fallback the plugin maps to the 'low' intensity level.
 *
 * The body mimics the RAW Electricity Maps API JSON (keyed `data[].level`, the
 * pre-normalized schema the plugin parses in get_current_intensity_level_direct),
 * not the plugin's normalized `intensity_level` output.
 *
 * @return array
 */
function fallback_response() : array {
	return make_response(
		200,
		wp_json_encode(
			[
				'zone' => 'unknown',
				'data' => [
					[
						'level'    => 'low',
						'datetime' => null,
					],
				],
			]
		)
	);
}

/**
 * Cap the render-path timeout for the Electricity Maps call.
 *
 * @param array  $args HTTP request args.
 * @param string $url  Request URL.
 * @return array
 */
function cap_timeout( $args, $url ) {
	if ( is_target_request( $url ) ) {
		$args['timeout'] = HOT_TIMEOUT;
	}
	return $args;
}
add_filter( 'http_request_args', __NAMESPACE__ . '\\cap_timeout', 10, 2 );

/**
 * Short-circuit the Electricity Maps call with a site-wide cached response.
 *
 * On a warm cache, returns the cached response without any network call. On a cold
 * miss it primes a brief placeholder (a stampede lock so concurrent requests in the
 * cold window are served instantly), then performs ONE upstream fetch itself — so
 * success, HTTP errors, AND transport-level WP_Errors are all caught and cached in
 * a single place rather than relying on the http_response filter (which never fires
 * for transport failures).
 *
 * @param false|array|\WP_Error $pre  Short-circuit value (false to proceed normally).
 * @param array                 $args HTTP request args.
 * @param string                $url  Request URL.
 * @return false|array|\WP_Error
 */
function short_circuit( $pre, $args, $url ) {
	if ( false !== $pre || is_fetching() || ! is_target_request( $url ) ) {
		return $pre;
	}

	$cached = get_transient( CACHE_KEY );
	if ( false !== $cached ) {
		// Hit (real value, fallback, or placeholder): serve without a network call.
		return $cached;
	}

	// Cold miss: prime a short-lived placeholder so concurrent requests in this
	// window are served instantly instead of all stampeding the upstream API.
	set_transient( CACHE_KEY, fallback_response(), LOCK_TTL );

	// Perform the single cold-miss fetch ourselves (re-entrancy guarded so this
	// filter doesn't intercept it). Timeout is capped by cap_timeout().
	is_fetching( true );
	$response = wp_remote_request( $url, $args );
	is_fetching( false );

	if ( is_wp_error( $response ) ) {
		// Transport failure (DNS, refused, SSL, timeout): negative-cache the
		// fallback for MISS_TTL so we don't retry the dead upstream every request.
		$fallback = fallback_response();
		set_transient( CACHE_KEY, $fallback, MISS_TTL );
		return $fallback;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );

	if ( 200 === $code && '' !== $body ) {
		$real = make_response( 200, $body );
		set_transient( CACHE_KEY, $real, HIT_TTL );
		return $real;
	}

	// Non-200 HTTP response: negative-cache the fallback for the short MISS_TTL.
	$fallback = fallback_response();
	set_transient( CACHE_KEY, $fallback, MISS_TTL );
	return $fallback;
}
add_filter( 'pre_http_request', __NAMESPACE__ . '\\short_circuit', 10, 3 );
