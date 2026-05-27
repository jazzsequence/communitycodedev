<?php
/**
 * WordPress Connectors API integration for AI Connector Secure Layer.
 *
 * Hooks:
 *   wp_connectors_init  — blocks DB writes for all AI connector options
 *   init:21             — injects Lazy_Auth into the AI client registry
 *   script_module_data_options-connectors-wp-admin:11 — updates UI state for configured providers
 *   admin_notices       — Terminus instructions for unconfigured providers on the Connectors page
 */

namespace AICSL\Connectors;

use AICSL\Lazy_Auth;
use WordPress\AiClient\AiClient;

/**
 * Fired on wp_connectors_init (during init:15).
 *
 * Registers pre_update_option filters for every AI connector option so that
 * keys cannot be saved to wp_options through the Connectors UI or REST API.
 */
function on_connectors_init( \WP_Connector_Registry $registry ): void {
	foreach ( wp_get_connectors() as $data ) {
		if ( 'ai_provider' !== ( $data['type'] ?? '' ) ) {
			continue;
		}

		$option = $data['authentication']['setting_name'] ?? '';
		if ( ! $option ) {
			continue;
		}

		// Return old value so update_option() detects no change and skips the write.
		add_filter(
			"pre_update_option_{$option}",
			static fn( $new, $old ) => $old,
			10,
			2
		);
	}
}

/**
 * Fired at init:21 — after _wp_connectors_pass_default_keys_to_ai_client() at init:20.
 *
 * For each AI provider that has a secret configured, injects a Lazy_Auth instance
 * into the AI client registry. The key is fetched from Pantheon Secrets or an
 * environment variable only when an LLM request is actually made.
 */
function inject_lazy_auth(): void {
	try {
		$ai_registry = AiClient::defaultRegistry();
	} catch ( \Exception $e ) {
		return;
	}

	foreach ( wp_get_connectors() as $id => $data ) {
		if ( 'ai_provider' !== ( $data['type'] ?? '' ) ) {
			continue;
		}

		if ( ! \AICSL\Secrets\has_secret_for_provider( $id ) ) {
			continue;
		}

		try {
			$ai_registry->setProviderRequestAuthentication( $id, new Lazy_Auth( $id ) );
		} catch ( \Exception $e ) {
			// Provider plugin may not be active — skip silently.
		}
	}
}

/**
 * Filters the data passed to the Connectors admin JS module.
 *
 * For providers with a configured secret, sets keySource to 'constant' (which
 * triggers the read-only "This API key is configured as a constant." UI state)
 * and isConnected to true (green Connected badge). This avoids the expensive
 * live API call that isProviderConfigured() would make for every page load.
 *
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function filter_script_module_data( array $data ): array {
	if ( ! isset( $data['connectors'] ) || ! is_array( $data['connectors'] ) ) {
		return $data;
	}

	foreach ( $data['connectors'] as $id => $connector ) {
		if ( 'ai_provider' !== ( $connector['type'] ?? '' ) ) {
			continue;
		}

		if ( ! \AICSL\Secrets\has_secret_for_provider( $id ) ) {
			continue;
		}

		$data['connectors'][ $id ]['authentication']['keySource']   = 'constant';
		$data['connectors'][ $id ]['authentication']['isConnected'] = true;
	}

	return $data;
}

/**
 * Tells the WordPress AI plugin (wordpress.org/plugins/ai) that credentials are
 * available when a Pantheon Secret or environment variable is configured for any
 * AI provider.
 *
 * The AI plugin's has_ai_credentials() checks get_option() directly. Because this
 * plugin blocks DB writes for connector options, that check always returns empty.
 * This filter corrects that so the AI settings page and editor features stay enabled.
 *
 * @param bool  $has_credentials Whether the AI plugin found credentials via wp_options.
 * @param array $connectors      All registered connectors.
 */
function filter_has_ai_credentials( bool $has_credentials, array $connectors ): bool {
	if ( $has_credentials ) {
		return true;
	}

	foreach ( $connectors as $id => $data ) {
		if ( 'ai_provider' !== ( $data['type'] ?? '' ) ) {
			continue;
		}
		if ( \AICSL\Secrets\has_secret_for_provider( $id ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Shows admin notices on the Connectors page for unconfigured providers.
 *
 * The Connectors page is a JS SPA but renders inside the standard wp-admin
 * header which does output admin_notices above the app div.
 */
function show_admin_notices(): void {
	global $pagenow;

	if ( 'options-connectors.php' !== ( $pagenow ?? '' ) ) {
		return;
	}

	$unconfigured = [];
	foreach ( wp_get_connectors() as $id => $data ) {
		if ( 'ai_provider' !== ( $data['type'] ?? '' ) ) {
			continue;
		}
		if ( ! \AICSL\Secrets\has_secret_for_provider( $id ) ) {
			$unconfigured[ $id ] = $data;
		}
	}

	if ( empty( $unconfigured ) ) {
		return;
	}

	echo '<div class="notice notice-info"><p>';
	echo '<strong>' . esc_html__( 'AI keys managed via Pantheon Secrets', 'ai-connector-secure-layer' ) . '</strong><br>';
	esc_html_e(
		'This site manages AI provider API keys through Pantheon Secrets — not through this form. Keys entered here cannot be saved. To connect a provider, run the Terminus command for it:',
		'ai-connector-secure-layer'
	);
	echo '</p><ul>';

	foreach ( $unconfigured as $id => $data ) {
		$secret_name   = \AICSL\Secrets\get_secret_name( $id );
		$provider_name = esc_html( $data['name'] );
		$site_name     = sanitize_title( get_bloginfo( 'name' ) );

		echo '<li>' . $provider_name . ': ';
		echo '<code>' . esc_html( "terminus secret:site:set {$site_name} {$secret_name} YOUR_KEY --type=runtime --scope=web,user" ) . '</code>';
		echo '</li>';
	}

	echo '</ul></div>';
}
