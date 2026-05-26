<?php
/**
 * Unit tests for AICSL\Secrets functions.
 */

namespace AICSL\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SecretsTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['_test_pantheon_secrets'] );
		putenv( 'ANTHROPIC_API_KEY' );
		putenv( 'GOOGLE_API_KEY' );
		putenv( 'OPENAI_API_KEY' );
	}

	// -------------------------------------------------------------------------
	// get_secret_name()
	// -------------------------------------------------------------------------

	public function test_get_secret_name_appends_api_key_suffix(): void {
		$this->assertSame( 'anthropic_api_key', \AICSL\Secrets\get_secret_name( 'anthropic' ) );
	}

	public function test_get_secret_name_for_google(): void {
		$this->assertSame( 'google_api_key', \AICSL\Secrets\get_secret_name( 'google' ) );
	}

	public function test_get_secret_name_for_openai(): void {
		$this->assertSame( 'openai_api_key', \AICSL\Secrets\get_secret_name( 'openai' ) );
	}

	// -------------------------------------------------------------------------
	// get_env_var_name()
	// -------------------------------------------------------------------------

	public function test_get_env_var_name_is_uppercase(): void {
		$this->assertSame( 'ANTHROPIC_API_KEY', \AICSL\Secrets\get_env_var_name( 'anthropic' ) );
	}

	public function test_get_env_var_name_for_google(): void {
		$this->assertSame( 'GOOGLE_API_KEY', \AICSL\Secrets\get_env_var_name( 'google' ) );
	}

	// -------------------------------------------------------------------------
	// get_secret_for_provider() — Pantheon Secrets path
	// -------------------------------------------------------------------------

	public function test_get_secret_returns_pantheon_secret_when_available(): void {
		$GLOBALS['_test_pantheon_secrets']['anthropic_api_key'] = 'sk-ant-from-pantheon';

		$this->assertSame( 'sk-ant-from-pantheon', \AICSL\Secrets\get_secret_for_provider( 'anthropic' ) );
	}

	public function test_get_secret_falls_back_to_env_var_when_pantheon_secret_is_null(): void {
		$GLOBALS['_test_pantheon_secrets']['anthropic_api_key'] = null;
		putenv( 'ANTHROPIC_API_KEY=sk-ant-from-env' );

		$this->assertSame( 'sk-ant-from-env', \AICSL\Secrets\get_secret_for_provider( 'anthropic' ) );
	}

	public function test_get_secret_falls_back_to_env_var_when_pantheon_secret_is_empty_string(): void {
		$GLOBALS['_test_pantheon_secrets']['anthropic_api_key'] = '';
		putenv( 'ANTHROPIC_API_KEY=sk-ant-from-env' );

		$this->assertSame( 'sk-ant-from-env', \AICSL\Secrets\get_secret_for_provider( 'anthropic' ) );
	}

	// -------------------------------------------------------------------------
	// get_secret_for_provider() — env var path
	// -------------------------------------------------------------------------

	public function test_get_secret_returns_env_var_when_set(): void {
		putenv( 'ANTHROPIC_API_KEY=sk-ant-env-only' );

		$this->assertSame( 'sk-ant-env-only', \AICSL\Secrets\get_secret_for_provider( 'anthropic' ) );
	}

	public function test_get_secret_returns_null_when_nothing_configured(): void {
		$this->assertNull( \AICSL\Secrets\get_secret_for_provider( 'anthropic' ) );
	}

	public function test_get_secret_returns_null_when_env_var_is_empty(): void {
		putenv( 'ANTHROPIC_API_KEY=' );

		$this->assertNull( \AICSL\Secrets\get_secret_for_provider( 'anthropic' ) );
	}

	// -------------------------------------------------------------------------
	// has_secret_for_provider()
	// -------------------------------------------------------------------------

	public function test_has_secret_returns_true_when_pantheon_secret_exists(): void {
		$GLOBALS['_test_pantheon_secrets']['google_api_key'] = 'google-key';

		$this->assertTrue( \AICSL\Secrets\has_secret_for_provider( 'google' ) );
	}

	public function test_has_secret_returns_true_when_env_var_set(): void {
		putenv( 'ANTHROPIC_API_KEY=some-key' );

		$this->assertTrue( \AICSL\Secrets\has_secret_for_provider( 'anthropic' ) );
	}

	public function test_has_secret_returns_false_when_nothing_configured(): void {
		$this->assertFalse( \AICSL\Secrets\has_secret_for_provider( 'anthropic' ) );
	}
}
