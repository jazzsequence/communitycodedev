<?php
/**
 * Unit tests for AICSL\Crypto functions.
 *
 * Known AES-256-GCM test vector (all-zero key + IV, plaintext "hello"):
 *   Generated with PHP openssl_encrypt to match Web Crypto AES-GCM output format
 *   (ciphertext || 16-byte auth tag, base64-encoded).
 *
 *   key_b64:    AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
 *   iv_b64:     AAAAAAAAAAAAAAAA
 *   ct_tag_b64: psIsUSKLkI9/Yv/Opqkvq+85v02T
 *
 * To verify in browser console:
 *   const key = await crypto.subtle.importKey('raw', new Uint8Array(32), 'AES-GCM', false, ['encrypt']);
 *   const ct  = await crypto.subtle.encrypt({ name: 'AES-GCM', iv: new Uint8Array(12) }, key, new TextEncoder().encode('hello'));
 *   btoa(String.fromCharCode(...new Uint8Array(ct))); // => psIsUSKLkI9/Yv/Opqkvq+85v02T
 */

namespace AICSL\Tests\Unit;

use PHPUnit\Framework\TestCase;

class CryptoTest extends TestCase {

	// -------------------------------------------------------------------------
	// Input validation
	// -------------------------------------------------------------------------

	public function test_decrypt_returns_false_for_empty_key(): void {
		$this->assertFalse( \AICSL\Crypto\decrypt( '', 'ciphertext', 'iv' ) );
	}

	public function test_decrypt_returns_false_for_empty_ciphertext(): void {
		$this->assertFalse( \AICSL\Crypto\decrypt( 'key', '', 'iv' ) );
	}

	public function test_decrypt_returns_false_for_empty_iv(): void {
		$this->assertFalse( \AICSL\Crypto\decrypt( 'key', 'ciphertext', '' ) );
	}

	public function test_decrypt_returns_false_for_invalid_base64_key(): void {
		// Strict base64 decode rejects characters outside the alphabet.
		$this->assertFalse( \AICSL\Crypto\decrypt( '!!!invalid!!!', base64_encode( 'ct' ), base64_encode( 'iv' ) ) );
	}

	public function test_decrypt_returns_false_for_wrong_key_length(): void {
		// AES-256 requires a 32-byte key; 16 bytes should fail.
		$short_key = base64_encode( random_bytes( 16 ) );
		$this->assertFalse( \AICSL\Crypto\decrypt( $short_key, base64_encode( 'ct' ), base64_encode( 'iv' ) ) );
	}

	public function test_decrypt_returns_false_for_wrong_iv_length(): void {
		// AES-GCM requires a 12-byte IV.
		$key    = base64_encode( random_bytes( 32 ) );
		$bad_iv = base64_encode( random_bytes( 8 ) );
		$this->assertFalse( \AICSL\Crypto\decrypt( $key, base64_encode( 'ct' ), $bad_iv ) );
	}

	public function test_decrypt_returns_false_for_ciphertext_too_short_for_tag(): void {
		// Ciphertext must be longer than the 16-byte GCM auth tag.
		$key = base64_encode( random_bytes( 32 ) );
		$iv  = base64_encode( random_bytes( 12 ) );
		$this->assertFalse( \AICSL\Crypto\decrypt( $key, base64_encode( 'tooshort' ), $iv ) );
	}

	// -------------------------------------------------------------------------
	// Tamper detection
	// -------------------------------------------------------------------------

	public function test_decrypt_returns_false_for_tampered_ciphertext(): void {
		[ $key_b64, $ct_b64, $iv_b64 ] = $this->make_test_vector( 'hello world' );

		$ct    = base64_decode( $ct_b64 );
		$ct[0] = chr( ord( $ct[0] ) ^ 0xFF ); // flip first byte
		$this->assertFalse( \AICSL\Crypto\decrypt( $key_b64, base64_encode( $ct ), $iv_b64 ) );
	}

	public function test_decrypt_returns_false_for_tampered_auth_tag(): void {
		[ $key_b64, $ct_b64, $iv_b64 ] = $this->make_test_vector( 'hello world' );

		$ct              = base64_decode( $ct_b64 );
		$last            = strlen( $ct ) - 1;
		$ct[ $last ]     = chr( ord( $ct[ $last ] ) ^ 0xFF ); // flip last byte of tag
		$this->assertFalse( \AICSL\Crypto\decrypt( $key_b64, base64_encode( $ct ), $iv_b64 ) );
	}

	// -------------------------------------------------------------------------
	// Correct decryption
	// -------------------------------------------------------------------------

	public function test_decrypt_round_trips_arbitrary_plaintext(): void {
		$plaintext               = 'sk-ant-test-key-' . bin2hex( random_bytes( 8 ) );
		[ $key_b64, $ct_b64, $iv_b64 ] = $this->make_test_vector( $plaintext );

		$this->assertSame( $plaintext, \AICSL\Crypto\decrypt( $key_b64, $ct_b64, $iv_b64 ) );
	}

	public function test_decrypt_known_vector_matches_webcrypto_output(): void {
		// All-zero 32-byte key, all-zero 12-byte IV, plaintext "hello".
		// Format matches Web Crypto: base64( ciphertext || 16-byte GCM tag ).
		$key_b64 = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
		$iv_b64  = 'AAAAAAAAAAAAAAAA';
		$ct_b64  = 'psIsUSKLkI9/Yv/Opqkvq+85v02T';

		$this->assertSame( 'hello', \AICSL\Crypto\decrypt( $key_b64, $ct_b64, $iv_b64 ) );
	}

	public function test_decrypt_handles_unicode_plaintext(): void {
		$plaintext               = 'sk-🔑-unicode-test';
		[ $key_b64, $ct_b64, $iv_b64 ] = $this->make_test_vector( $plaintext );

		$this->assertSame( $plaintext, \AICSL\Crypto\decrypt( $key_b64, $ct_b64, $iv_b64 ) );
	}

	// -------------------------------------------------------------------------
	// Helper
	// -------------------------------------------------------------------------

	/**
	 * Encrypt using the same AES-256-GCM format Web Crypto produces:
	 * base64( ciphertext || 16-byte auth tag ).
	 *
	 * @return array{0: string, 1: string, 2: string} [key_b64, ct_tag_b64, iv_b64]
	 */
	private function make_test_vector( string $plaintext ): array {
		$key = random_bytes( 32 );
		$iv  = random_bytes( 12 );
		$tag = '';

		$ct = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );

		return [
			base64_encode( $key ),
			base64_encode( $ct . $tag ),
			base64_encode( $iv ),
		];
	}
}
