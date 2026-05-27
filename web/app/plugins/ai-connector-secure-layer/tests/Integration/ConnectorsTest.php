<?php
/**
 * Integration tests for AICSL\Connectors hook callbacks.
 *
 * Requires WordPress bootstrapped via wpunit-helpers.
 * Run: composer test:integration
 */

namespace AICSL\Tests\Integration;

use WP_UnitTestCase;

class ConnectorsTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		putenv( 'ANTHROPIC_API_KEY' );
		putenv( 'GOOGLE_API_KEY' );
	}

	protected function tearDown(): void {
		putenv( 'ANTHROPIC_API_KEY' );
		putenv( 'GOOGLE_API_KEY' );
		// Clean up any options we may have set.
		delete_option( 'connectors_ai_anthropic_api_key' );
		delete_option( 'connectors_ai_google_api_key' );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// DB write blocking
	// -------------------------------------------------------------------------

	public function test_pre_update_option_filter_is_registered_for_anthropic(): void {
		$this->assertGreaterThan(
			0,
			has_filter( 'pre_update_option_connectors_ai_anthropic_api_key' ),
			'pre_update_option hook should be registered for the anthropic connector option'
		);
	}

	public function test_pre_update_option_filter_is_registered_for_google(): void {
		$this->assertGreaterThan(
			0,
			has_filter( 'pre_update_option_connectors_ai_google_api_key' ),
			'pre_update_option hook should be registered for the google connector option'
		);
	}

	public function test_connector_api_key_cannot_be_saved_to_database(): void {
		// Attempt to save a key through the normal WordPress options API.
		update_option( 'connectors_ai_anthropic_api_key', 'sk-ant-should-not-save' );

		$stored = get_option( 'connectors_ai_anthropic_api_key', '' );
		$this->assertSame( '', $stored, 'API key must not be stored in wp_options' );
	}

	// -------------------------------------------------------------------------
	// Lazy auth injection
	// -------------------------------------------------------------------------

	public function test_lazy_auth_is_injected_when_secret_configured(): void {
		putenv( 'ANTHROPIC_API_KEY=sk-ant-test' );

		// Re-run inject_lazy_auth to simulate post-init state.
		\AICSL\Connectors\inject_lazy_auth();

		$ai_registry = \WordPress\AiClient\AiClient::defaultRegistry();
		$auth        = $ai_registry->getProviderRequestAuthentication( 'anthropic' );

		$this->assertInstanceOf(
			\AICSL\Lazy_Auth::class,
			$auth,
			'Lazy_Auth should be set on the AI registry for anthropic when secret is configured'
		);
	}

	public function test_lazy_auth_not_injected_when_no_secret(): void {
		// No env var, no Pantheon secret — provider should not get our auth.
		\AICSL\Connectors\inject_lazy_auth();

		$ai_registry = \WordPress\AiClient\AiClient::defaultRegistry();

		try {
			$auth = $ai_registry->getProviderRequestAuthentication( 'anthropic' );
			// If we got here without throwing, auth was set — but it shouldn't be ours.
			$this->assertNotInstanceOf(
				\AICSL\Lazy_Auth::class,
				$auth,
				'Lazy_Auth should not be set when no secret is configured'
			);
		} catch ( \Exception $e ) {
			// No auth set at all — also acceptable.
			$this->addToAssertionCount( 1 );
		}
	}

	// -------------------------------------------------------------------------
	// wpai_has_ai_credentials filter
	// -------------------------------------------------------------------------

	public function test_filter_has_ai_credentials_returns_true_when_secret_configured(): void {
		putenv( 'GOOGLE_API_KEY=google-test-key' );

		$connectors = [
			'google' => [ 'type' => 'ai_provider' ],
		];

		$result = \AICSL\Connectors\filter_has_ai_credentials( false, $connectors );

		$this->assertTrue( $result );
	}

	public function test_filter_has_ai_credentials_returns_false_when_no_secret(): void {
		$connectors = [
			'google' => [ 'type' => 'ai_provider' ],
		];

		$result = \AICSL\Connectors\filter_has_ai_credentials( false, $connectors );

		$this->assertFalse( $result );
	}

	public function test_filter_has_ai_credentials_passes_through_existing_true(): void {
		$result = \AICSL\Connectors\filter_has_ai_credentials( true, [] );

		$this->assertTrue( $result );
	}

	public function test_filter_has_ai_credentials_ignores_non_ai_connectors(): void {
		// Akismet has type spam_filtering — should not satisfy the credential check.
		$connectors = [
			'akismet' => [ 'type' => 'spam_filtering' ],
		];

		$result = \AICSL\Connectors\filter_has_ai_credentials( false, $connectors );

		$this->assertFalse( $result );
	}

	// -------------------------------------------------------------------------
	// script_module_data filter
	// -------------------------------------------------------------------------

	public function test_script_module_data_sets_key_source_to_constant_when_secret_configured(): void {
		putenv( 'ANTHROPIC_API_KEY=sk-ant-test' );

		$input = [
			'connectors' => [
				'anthropic' => [
					'type'           => 'ai_provider',
					'authentication' => [
						'keySource'   => 'none',
						'isConnected' => false,
					],
				],
			],
		];

		$output = \AICSL\Connectors\filter_script_module_data( $input );

		$this->assertSame( 'constant', $output['connectors']['anthropic']['authentication']['keySource'] );
		$this->assertTrue( $output['connectors']['anthropic']['authentication']['isConnected'] );
	}

	public function test_script_module_data_leaves_unconfigured_providers_unchanged(): void {
		$input = [
			'connectors' => [
				'anthropic' => [
					'type'           => 'ai_provider',
					'authentication' => [
						'keySource'   => 'none',
						'isConnected' => false,
					],
				],
			],
		];

		$output = \AICSL\Connectors\filter_script_module_data( $input );

		$this->assertSame( 'none', $output['connectors']['anthropic']['authentication']['keySource'] );
		$this->assertFalse( $output['connectors']['anthropic']['authentication']['isConnected'] );
	}

	public function test_script_module_data_does_not_modify_non_ai_connectors(): void {
		putenv( 'AKISMET_API_KEY=akismet-key' );

		$input = [
			'connectors' => [
				'akismet' => [
					'type'           => 'spam_filtering',
					'authentication' => [
						'keySource'   => 'none',
						'isConnected' => false,
					],
				],
			],
		];

		$output = \AICSL\Connectors\filter_script_module_data( $input );

		$this->assertSame( 'none', $output['connectors']['akismet']['authentication']['keySource'] );
		putenv( 'AKISMET_API_KEY' );
	}
}
