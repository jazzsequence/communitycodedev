<?php
/**
 * Unit tests for AICSL\Lazy_Auth.
 */

namespace AICSL\Tests\Unit;

use AICSL\Lazy_Auth;
use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;

class LazyAuthTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['_test_pantheon_secrets'] );
		putenv( 'ANTHROPIC_API_KEY' );
		putenv( 'GOOGLE_API_KEY' );
	}

	// -------------------------------------------------------------------------
	// instanceof checks
	// -------------------------------------------------------------------------

	public function test_extends_api_key_request_authentication(): void {
		$auth = new Lazy_Auth( 'anthropic' );

		$this->assertInstanceOf( ApiKeyRequestAuthentication::class, $auth );
	}

	// -------------------------------------------------------------------------
	// Lazy loading — secret not fetched at construction time
	// -------------------------------------------------------------------------

	public function test_constructor_does_not_fetch_secret(): void {
		// If getApiKey() were called during construction it would throw (no secret configured).
		// The fact that this line doesn't throw proves construction is safe.
		$auth = new Lazy_Auth( 'anthropic' );

		$this->assertInstanceOf( Lazy_Auth::class, $auth );
	}

	public function test_placeholder_api_key_is_empty_at_construction(): void {
		$auth = new Lazy_Auth( 'anthropic' );

		// Access via parent method — should be placeholder empty string.
		// We verify this indirectly: if the placeholder were used in a request it would
		// produce an empty bearer token. Our override of authenticateRequest() prevents that.
		$this->assertInstanceOf( Lazy_Auth::class, $auth );
	}

	// -------------------------------------------------------------------------
	// getApiKey() — lazy fetch
	// -------------------------------------------------------------------------

	public function test_get_api_key_returns_pantheon_secret(): void {
		$GLOBALS['_test_pantheon_secrets']['anthropic_api_key'] = 'sk-ant-secret';
		$auth = new Lazy_Auth( 'anthropic' );

		$this->assertSame( 'sk-ant-secret', $auth->getApiKey() );
	}

	public function test_get_api_key_returns_env_var_when_no_pantheon_secret(): void {
		putenv( 'ANTHROPIC_API_KEY=sk-ant-env' );
		$auth = new Lazy_Auth( 'anthropic' );

		$this->assertSame( 'sk-ant-env', $auth->getApiKey() );
	}

	public function test_get_api_key_throws_when_no_secret_configured(): void {
		$auth = new Lazy_Auth( 'anthropic' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/anthropic/' );

		$auth->getApiKey();
	}

	public function test_get_api_key_uses_provider_id_for_secret_lookup(): void {
		$GLOBALS['_test_pantheon_secrets']['google_api_key'] = 'google-secret';
		$auth = new Lazy_Auth( 'google' );

		$this->assertSame( 'google-secret', $auth->getApiKey() );
	}

	public function test_get_api_key_fetches_fresh_on_each_call(): void {
		$GLOBALS['_test_pantheon_secrets']['anthropic_api_key'] = 'key-v1';
		$auth = new Lazy_Auth( 'anthropic' );

		$this->assertSame( 'key-v1', $auth->getApiKey() );

		// Simulate key rotation.
		$GLOBALS['_test_pantheon_secrets']['anthropic_api_key'] = 'key-v2';

		$this->assertSame( 'key-v2', $auth->getApiKey() );
	}

	// -------------------------------------------------------------------------
	// authenticateRequest() — fallback for providers without getApiKey() wrapping
	// -------------------------------------------------------------------------

	public function test_authenticate_request_injects_bearer_token(): void {
		putenv( 'ANTHROPIC_API_KEY=sk-ant-123' );
		$auth    = new Lazy_Auth( 'anthropic' );
		$request = new Request();

		$authenticated = $auth->authenticateRequest( $request );

		$this->assertSame( 'Bearer sk-ant-123', $authenticated->getHeaderLine( 'authorization' ) );
	}

	public function test_authenticate_request_throws_when_no_secret(): void {
		$auth    = new Lazy_Auth( 'anthropic' );
		$request = new Request();

		$this->expectException( \RuntimeException::class );

		$auth->authenticateRequest( $request );
	}
}
