<?php
/**
 * Plugin Name: Performance: Async Google Fonts
 * Description: Removes render-blocking Google Fonts stylesheets from the critical render path on the front end. The font stylesheets (the theme's Victor Mono / Source Sans 3 and MailPoet's custom font families) are still loaded and still apply — they are switched to a non-blocking "print → all" media swap and given preconnect hints, so first paint no longer waits on a fresh cross-origin connection to fonts.googleapis.com. This addresses the homepage LCP regression flagged by New Relic Browser RUM (p75 4437ms vs 2500ms threshold) without changing which fonts render.
 * Author: Community + Code
 * License: MIT
 *
 * @package CommunityCode\MuPlugin\Perf
 */

namespace CommunityCode\MuPlugin\Perf\AsyncFonts;

/**
 * The cross-origin host whose stylesheets we move off the critical path.
 */
const FONT_HOST = 'fonts.googleapis.com';

/**
 * Add preconnect resource hints so the browser can open the connection to the
 * Google Fonts origins early, in parallel with HTML parsing, instead of only
 * after it discovers the (now non-blocking) font stylesheet.
 *
 * @param array  $hints    The URLs/attributes to print for the given relation.
 * @param string $relation The resource hint relation type.
 * @return array Filtered hints.
 */
function add_preconnect( array $hints, string $relation ) : array {
	if ( 'preconnect' !== $relation || is_admin() ) {
		return $hints;
	}

	// fonts.googleapis.com serves the CSS; fonts.gstatic.com serves the font files.
	$hints[] = 'https://fonts.googleapis.com';
	$hints[] = [
		'href'        => 'https://fonts.gstatic.com',
		'crossorigin' => '',
	];

	return $hints;
}
add_filter( 'wp_resource_hints', __NAMESPACE__ . '\\add_preconnect', 10, 2 );

/**
 * Rewrite Google Fonts <link> stylesheets so they no longer block rendering.
 *
 * The stylesheet is requested with media="print" (which the browser does not
 * treat as render-blocking) and promoted to media="all" once it has loaded.
 * A <noscript> copy of the original tag preserves the fonts for visitors with
 * JavaScript disabled. Because the font URLs already carry &display=swap, text
 * paints immediately in the CSS fallback stack (monospace / sans-serif, both
 * declared in theme.json) and swaps to the web font when it arrives — so the
 * same fonts still render, just without holding up first paint.
 *
 * @param string $html   The link tag HTML for the enqueued style.
 * @param string $handle The style's registered handle.
 * @param string $href   The stylesheet URL.
 * @param string $media  The media attribute.
 * @return string Possibly-rewritten link tag HTML.
 */
function async_font_tag( string $html, string $handle, string $href, string $media ) : string {
	if ( is_admin() || false === strpos( $href, FONT_HOST ) ) {
		return $html;
	}

	// Already non-blocking (e.g. media="print"); leave it alone.
	if ( false !== strpos( $html, 'onload=' ) ) {
		return $html;
	}

	// Swap the render-blocking media="all" for a deferred print → all load.
	$async = preg_replace(
		'/media=([\'"])all\1/',
		'media=$1print$1 onload="this.media=\'all\'"',
		$html,
		1,
		$count
	);

	// Fallback if the media attribute was not the expected media="all".
	if ( ! $count ) {
		$async = preg_replace( '/\s*\/?>\s*$/', ' media="print" onload="this.media=\'all\'" />', $html, 1 );
	}

	// Preserve the fonts for no-JS visitors with an unmodified copy.
	return $async . '<noscript>' . $html . '</noscript>';
}
add_filter( 'style_loader_tag', __NAMESPACE__ . '\\async_font_tag', 10, 4 );
