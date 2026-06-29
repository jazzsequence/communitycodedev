<?php
/**
 * Plugin Name: Grid Aware – Electricity Maps render-path cache
 * Description: Override-only hardening for the third-party Grid Aware WP plugin's
 *  synchronous Electricity Maps API call on the front-end render path. Adds a
 *  zone-keyed shared cache (preserving per-visitor location awareness), caches
 *  negative results, and bounds the render-path timeout. Does NOT modify the
 *  third-party plugin.
 * Author: Community + Code
 * License: MIT
 *
 * Why this exists
 * ---------------
 * Grid Aware WP calls Electricity Maps synchronously during render to decide the
 * visitor's grid carbon intensity (low/medium/high), which it then bakes into the
 * server-rendered HTML (image/video placeholders, system fonts, body class). Its
 * own transient is keyed by md5(visitor IP), so on a public site almost every
 * anonymous visitor is a cold miss and pays a blocking external call. Errors and
 * slow responses are never cached, so a slow/failing upstream is retried on every
 * request. New Relic flagged WebTransaction/Uri//index.php at p95 1171 ms / p99
 * 3531 ms with ext_avg 712 ms — dominated by this call.
 *
 * The Electricity Maps response already contains the visitor's `zone` (e.g. "US",
 * "DE", "ES"). Carbon intensity is a property of that zone, not of the individual
 * IP — every IP in a zone gets the identical value. We therefore add a shared,
 * ZONE-keyed cache so visitors in the same zone reuse one lookup, while different
 * zones still get different values (per-visitor location awareness is preserved).
 *
 * @package CommunityCode\GridAwareEMCache
 */

namespace CommunityCode\GridAwareEMCache;

if ( ! defined( 'WPINC' ) ) {
	die;
}

const EM_HOST = 'api.electricitymap.org';

// IP -> zone mapping is very stable; cache it long so a returning visitor's zone
// is known without another lookup.
const IP_ZONE_TTL = WEEK_IN_SECONDS;

// Zone -> intensity is the freshness-sensitive value. Match the third-party
// plugin's own 600s cache window so staleness semantics are unchanged.
const ZONE_TTL = 600;

// After a failed/slow upstream, suppress retries on the hot path for this long so
// a single bad upstream does not block every subsequent render. Short enough to
// recover quickly on its own.
const FAIL_TTL = 60;

// Cap the render-path wait. The third-party plugin sets 10s, which is a render
// eternity. This is the explicit cold-miss policy: short timeout + skip → the
// plugin falls back to its existing 'low' default for that one render.
const RENDER_TIMEOUT = 2;

/**
 * Is this the Electricity Maps carbon-intensity endpoint?
 *
 * @param string $url Outgoing request URL.
 * @return bool
 */
function is_em_url( string $url ): bool {
	return false !== strpos( $url, EM_HOST );
}

/**
 * Only engage on front-end render requests — the hot path that was flagged.
 *
 * REST (the plugin's own /intensity and admin /test-api routes) and wp-admin keep
 * stock, live behavior so an admin testing an API key always hits the upstream.
 *
 * @return bool
 */
function is_render_request(): bool {
	if ( is_admin() ) {
		return false;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return false;
	}
	if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
		return false;
	}
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return false;
	}
	return true;
}

/**
 * Extract the visitor IP from the outgoing request args.
 *
 * The plugin geolocates by sending the visitor IP in X-Forwarded-For, so reading
 * it back from the request args guarantees our cache key matches the value the
 * plugin keyed on. Local/dev requests (no header) collapse to a single bucket.
 *
 * @param array $args wp_remote_get args.
 * @return string Visitor IP, or 'local' when none was sent.
 */
function visitor_ip( array $args ): string {
	$ip = '';
	if ( ! empty( $args['headers']['X-Forwarded-For'] ) ) {
		$ip = is_array( $args['headers']['X-Forwarded-For'] )
			? reset( $args['headers']['X-Forwarded-For'] )
			: (string) $args['headers']['X-Forwarded-For'];
	}
	$ip = trim( (string) $ip );
	return '' === $ip ? 'local' : $ip;
}

/**
 * Build a synthetic successful wp_remote_get response from a cached EM body.
 *
 * @param string $body Raw JSON body previously returned by Electricity Maps.
 * @return array A response array shaped like the WP HTTP API's.
 */
function synthetic_response( string $body ): array {
	return [
		'headers'       => [],
		'body'          => $body,
		'response'      => [ 'code' => 200, 'message' => 'OK' ],
		'cookies'       => [],
		'http_response' => null,
	];
}

/**
 * Cap the render-path timeout for the Electricity Maps call.
 */
add_filter(
	'http_request_args',
	function ( $args, $url ) {
		if ( is_em_url( (string) $url ) && is_render_request() ) {
			$current        = isset( $args['timeout'] ) ? (int) $args['timeout'] : RENDER_TIMEOUT;
			$args['timeout'] = min( $current, RENDER_TIMEOUT );
		}
		return $args;
	},
	10,
	2
);

/**
 * Short-circuit the blocking call when we can satisfy it from cache.
 *
 * 1. Negative cache: if this visitor's lookup recently failed, fail fast so the
 *    plugin uses its 'low' fallback instead of re-hitting a slow/dead upstream.
 * 2. Zone cache: if we know this visitor's zone (from a prior lookup) and that
 *    zone's intensity is still fresh, return it without any network call. The
 *    value is identical to what a live call for this visitor would return, so
 *    per-visitor location awareness is preserved.
 */
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		if ( ! is_em_url( (string) $url ) || ! is_render_request() ) {
			return $pre;
		}

		$ip = visitor_ip( (array) $args );

		// 1. Negative-result cache (cold-miss policy: skip on recent failure).
		if ( false !== get_transient( 'gaw_em_fail_' . md5( $ip ) ) ) {
			return new \WP_Error(
				'gaw_em_circuit_open',
				'Electricity Maps lookup suppressed after a recent failure; using fallback intensity.'
			);
		}

		// 2. Zone-keyed shared cache.
		$zone = get_transient( 'gaw_em_ip_zone_' . md5( $ip ) );
		if ( is_string( $zone ) && '' !== $zone ) {
			$body = get_transient( 'gaw_em_zone_body_' . $zone );
			if ( is_string( $body ) && '' !== $body ) {
				return synthetic_response( $body );
			}
		}

		return $pre;
	},
	10,
	3
);

/**
 * Record outcomes of real (non-short-circuited) Electricity Maps calls.
 *
 * On success: remember this visitor's zone (long) and cache the zone's intensity
 * body (short, shared across every IP in that zone). On a non-200: record a
 * short-lived failure so the next render fails fast.
 */
add_filter(
	'http_response',
	function ( $response, $args, $url ) {
		if ( ! is_em_url( (string) $url ) || ! is_render_request() ) {
			return $response;
		}

		$ip   = visitor_ip( (array) $args );
		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			set_transient( 'gaw_em_fail_' . md5( $ip ), 1, FAIL_TTL );
			return $response;
		}

		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( is_array( $data ) && ! empty( $data['zone'] ) ) {
			$zone = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $data['zone'] );
			if ( '' !== $zone ) {
				set_transient( 'gaw_em_ip_zone_' . md5( $ip ), $zone, IP_ZONE_TTL );
				set_transient( 'gaw_em_zone_body_' . $zone, $body, ZONE_TTL );
				delete_transient( 'gaw_em_fail_' . md5( $ip ) );
			}
		}

		return $response;
	},
	10,
	3
);

/**
 * Transport-level failures (timeouts, DNS) surface as a WP_Error and never reach
 * the http_response filter — record them here so the hot path still fails fast.
 */
add_action(
	'http_api_debug',
	function ( $response, $context, $class, $args, $url ) {
		unset( $context, $class );
		if ( is_em_url( (string) $url ) && is_render_request() && is_wp_error( $response ) ) {
			set_transient( 'gaw_em_fail_' . md5( visitor_ip( (array) $args ) ), 1, FAIL_TTL );
		}
	},
	10,
	5
);
