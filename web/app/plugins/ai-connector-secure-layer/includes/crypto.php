<?php
/**
 * Cryptographic helpers for AI Connector Secure Layer.
 *
 * AES-256-GCM, compatible with Web Crypto API output.
 * Web Crypto appends the 16-byte auth tag directly to the ciphertext bytes
 * before base64-encoding, so PHP must split it off before passing to openssl.
 */

namespace AICSL\Crypto;

/**
 * Decrypt an AES-256-GCM ciphertext produced by the browser's Web Crypto API.
 *
 * @param string $key_b64        Base64-encoded 32-byte AES key.
 * @param string $ciphertext_b64 Base64-encoded (ciphertext || 16-byte GCM tag).
 * @param string $iv_b64         Base64-encoded 12-byte IV (nonce).
 * @return string|false Plaintext on success, false on any validation or auth failure.
 */
function decrypt( string $key_b64, string $ciphertext_b64, string $iv_b64 ): string|false {
	if ( empty( $key_b64 ) || empty( $ciphertext_b64 ) || empty( $iv_b64 ) ) {
		return false;
	}

	$key  = base64_decode( $key_b64, strict: true );
	$iv   = base64_decode( $iv_b64, strict: true );
	$data = base64_decode( $ciphertext_b64, strict: true );

	if ( $key === false || $iv === false || $data === false ) {
		return false;
	}

	if ( strlen( $key ) !== 32 || strlen( $iv ) !== 12 ) {
		return false;
	}

	// Must contain at least one byte of ciphertext beyond the 16-byte tag.
	if ( strlen( $data ) <= 16 ) {
		return false;
	}

	$tag        = substr( $data, -16 );
	$ciphertext = substr( $data, 0, -16 );

	return openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
}
