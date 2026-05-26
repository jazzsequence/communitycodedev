<?php
/**
 * Lazy-loading authentication for WP AI Client providers.
 *
 * Extends ApiKeyRequestAuthentication so provider models that check
 * `instanceof ApiKeyRequestAuthentication` (e.g. AnthropicTextGenerationModel)
 * will call getApiKey() to retrieve the key and wrap it in their own
 * provider-specific authentication class with the correct headers.
 *
 * The API key is fetched from Pantheon Secrets or an environment variable
 * only at the moment getApiKey() is called — never at construction time
 * and never stored in a PHP constant or wp_options.
 */

namespace AICSL;

use AICSL\Secrets;
use RuntimeException;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;

class Lazy_Auth extends ApiKeyRequestAuthentication {

	/**
	 * @param string $provider_id WP AI Client provider ID (e.g. 'anthropic', 'google').
	 */
	public function __construct( private string $provider_id ) {
		parent::__construct( '' ); // placeholder — never read directly
	}

	/**
	 * Fetches the API key from external secrets at call time.
	 *
	 * Called by provider model overrides (e.g. AnthropicTextGenerationModel::getRequestAuthentication())
	 * to obtain the key before wrapping it in their provider-specific auth class.
	 *
	 * @throws RuntimeException When no secret is configured for the provider.
	 */
	public function getApiKey(): string {
		$key = Secrets\get_secret_for_provider( $this->provider_id );

		if ( empty( $key ) ) {
			throw new RuntimeException(
				sprintf(
					'No API key secret configured for provider "%s". ' .
					'Set the "%s" Pantheon Secret or the "%s" environment variable.',
					$this->provider_id,
					Secrets\get_secret_name( $this->provider_id ),
					Secrets\get_env_var_name( $this->provider_id )
				)
			);
		}

		return $key;
	}

	/**
	 * Fallback authenticateRequest() for providers that do not override getRequestAuthentication().
	 *
	 * For Anthropic and Google, this method is never reached — their model classes call
	 * getApiKey() directly and wrap the result in their own provider-specific auth. This
	 * implementation handles any future provider that passes the stored auth through as-is.
	 *
	 * @throws RuntimeException When no secret is configured.
	 */
	public function authenticateRequest( Request $request ): Request {
		return $request->withHeader( 'Authorization', 'Bearer ' . $this->getApiKey() );
	}
}
