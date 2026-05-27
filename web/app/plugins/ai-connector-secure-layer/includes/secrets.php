<?php
/**
 * Secret resolution for AI Connector Secure Layer.
 *
 * Priority: Pantheon Secrets → environment variable → null.
 *
 * Secret naming convention:
 *   provider ID 'anthropic' → Pantheon secret 'anthropic_api_key' / env var 'ANTHROPIC_API_KEY'
 */

namespace AICSL\Secrets;

/**
 * Returns the Pantheon Secrets key name for a provider.
 *
 * @param string $provider_id WP AI Client provider ID (e.g. 'anthropic').
 */
function get_secret_name( string $provider_id ): string {
	return $provider_id . '_api_key';
}

/**
 * Returns the environment variable name for a provider.
 *
 * @param string $provider_id WP AI Client provider ID (e.g. 'anthropic').
 */
function get_env_var_name( string $provider_id ): string {
	return strtoupper( $provider_id ) . '_API_KEY';
}

/**
 * Fetches the API key for a provider from the most secure available source.
 *
 * @param string $provider_id WP AI Client provider ID (e.g. 'anthropic').
 * @return string|null Key value, or null if not configured anywhere.
 */
function get_secret_for_provider( string $provider_id ): ?string {
	// Pantheon Secrets — only available on Pantheon or local Lando (via secrets.json wrapper).
	if ( function_exists( 'pantheon_get_secret' ) ) {
		$secret = pantheon_get_secret( get_secret_name( $provider_id ) );
		if ( ! empty( $secret ) ) {
			return $secret;
		}
	}

	// Fall back to environment variable.
	$env_val = getenv( get_env_var_name( $provider_id ) );
	if ( $env_val !== false && $env_val !== '' ) {
		return $env_val;
	}

	return null;
}

/**
 * Returns true if any configured secret source has a key for this provider.
 *
 * @param string $provider_id WP AI Client provider ID (e.g. 'anthropic').
 */
function has_secret_for_provider( string $provider_id ): bool {
	return null !== get_secret_for_provider( $provider_id );
}
