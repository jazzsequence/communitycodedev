<?php
/**
 * Integration tests for AICSL\REST_API functions.
 *
 * Requires WordPress to be bootstrapped via wpunit-helpers.
 * Run: composer test:integration
 */

namespace AICSL\Tests\Integration;

use WP_UnitTestCase;
use WP_REST_Request;

class RestApiTest extends WP_UnitTestCase {

	private \WP_REST_Server $server;

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// /setup endpoint
	// -------------------------------------------------------------------------

	public function test_setup_requires_authentication(): void {
		wp_set_current_user( 0 );
		$request  = new WP_REST_Request( 'POST', '/aicsl/v1/setup' );
		$request->set_param( 'ciphertext', 'abc' );
		$request->set_param( 'iv', 'def' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_setup_requires_manage_options_capability(): void {
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );

		$request = new WP_REST_Request( 'POST', '/aicsl/v1/setup' );
		$request->set_param( 'ciphertext', 'abc' );
		$request->set_param( 'iv', 'def' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_setup_stores_ciphertext_and_iv(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', '/aicsl/v1/setup' );
		$request->set_param( 'ciphertext', 'dGVzdGNpcGhlcg==' );
		$request->set_param( 'iv', 'dGVzdGl2' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'dGVzdGNpcGhlcg==', get_option( 'aicsl_ciphertext' ) );
		$this->assertSame( 'dGVzdGl2', get_option( 'aicsl_iv' ) );
	}

	public function test_setup_requires_ciphertext_param(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', '/aicsl/v1/setup' );
		$request->set_param( 'iv', 'dGVzdGl2' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_setup_requires_iv_param(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', '/aicsl/v1/setup' );
		$request->set_param( 'ciphertext', 'dGVzdGNpcGhlcg==' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// /complete endpoint
	// -------------------------------------------------------------------------

	public function test_complete_requires_authentication(): void {
		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'POST', '/aicsl/v1/complete' );
		$request->set_param( 'prompt', 'hello' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_complete_returns_400_without_key_header(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', '/aicsl/v1/complete' );
		$request->set_param( 'prompt', 'hello' );
		// No X-AICSL-Key header.
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_complete_returns_400_when_no_ciphertext_stored(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );
		delete_option( 'aicsl_ciphertext' );
		delete_option( 'aicsl_iv' );

		$request = new WP_REST_Request( 'POST', '/aicsl/v1/complete' );
		$request->set_param( 'prompt', 'hello' );
		$request->set_header( 'X-AICSL-Key', base64_encode( random_bytes( 32 ) ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_complete_returns_401_when_decryption_fails(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		// Store valid-looking ciphertext but send the wrong key.
		update_option( 'aicsl_ciphertext', 'dGVzdGNpcGhlcnRleHQ=' );
		update_option( 'aicsl_iv', base64_encode( random_bytes( 12 ) ) );

		$request = new WP_REST_Request( 'POST', '/aicsl/v1/complete' );
		$request->set_param( 'prompt', 'hello' );
		$request->set_header( 'X-AICSL-Key', base64_encode( random_bytes( 32 ) ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
	}
}
