/**
 * AI Connector Secure Layer — admin setup.
 *
 * Handles client-side AES-256-GCM key generation and encryption.
 * The raw API key never leaves the browser; only ciphertext is sent to the server.
 *
 * Encryption key is stored in sessionStorage so it's gone when the tab closes.
 */

/* global aicslConfig */

const AICSL = ( () => {
	const SESSION_KEY = 'aicsl_encryption_key';

	// -------------------------------------------------------------------------
	// Web Crypto helpers
	// -------------------------------------------------------------------------

	async function generateKey() {
		return crypto.subtle.generateKey(
			{ name: 'AES-GCM', length: 256 },
			true, // extractable so we can export it to sessionStorage
			[ 'encrypt', 'decrypt' ]
		);
	}

	async function exportKey( cryptoKey ) {
		const raw = await crypto.subtle.exportKey( 'raw', cryptoKey );
		return bufferToBase64( raw );
	}

	async function importKey( base64 ) {
		const raw = base64ToBuffer( base64 );
		return crypto.subtle.importKey( 'raw', raw, { name: 'AES-GCM' }, false, [ 'decrypt' ] );
	}

	async function encryptString( plaintext, cryptoKey ) {
		const iv      = crypto.getRandomValues( new Uint8Array( 12 ) );
		const encoded = new TextEncoder().encode( plaintext );

		// AES-GCM output = ciphertext || 16-byte auth tag (appended by the browser).
		const encrypted = await crypto.subtle.encrypt( { name: 'AES-GCM', iv }, cryptoKey, encoded );

		return {
			ciphertext: bufferToBase64( encrypted ),
			iv: bufferToBase64( iv ),
		};
	}

	function bufferToBase64( buffer ) {
		return btoa( String.fromCharCode( ...new Uint8Array( buffer ) ) );
	}

	function base64ToBuffer( base64 ) {
		return Uint8Array.from( atob( base64 ), ( c ) => c.charCodeAt( 0 ) );
	}

	// -------------------------------------------------------------------------
	// Session key management
	// -------------------------------------------------------------------------

	function saveKeyToSession( keyBase64 ) {
		sessionStorage.setItem( SESSION_KEY, keyBase64 );
	}

	function getKeyFromSession() {
		return sessionStorage.getItem( SESSION_KEY );
	}

	function clearSessionKey() {
		sessionStorage.removeItem( SESSION_KEY );
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Encrypt the raw API key and send ciphertext to the server.
	 * Stores the encryption key in sessionStorage.
	 */
	async function setupApiKey( rawApiKey ) {
		const key        = await generateKey();
		const { ciphertext, iv } = await encryptString( rawApiKey, key );
		const exportedKey = await exportKey( key );

		const response = await fetch( aicslConfig.restUrl + 'setup', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': aicslConfig.nonce,
			},
			body: JSON.stringify( { ciphertext, iv } ),
		} );

		if ( ! response.ok ) {
			throw new Error( `Setup failed: ${ response.status }` );
		}

		saveKeyToSession( exportedKey );
		return true;
	}

	return { setupApiKey, getKeyFromSession, clearSessionKey, importKey };
} )();

// -------------------------------------------------------------------------
// Admin page UI wiring
// -------------------------------------------------------------------------

document.addEventListener( 'DOMContentLoaded', () => {
	const saveBtn  = document.getElementById( 'aicsl-save-key' );
	const input    = document.getElementById( 'aicsl-api-key' );
	const statusEl = document.getElementById( 'aicsl-status' );

	if ( ! saveBtn || ! input ) {
		return;
	}

	function setStatus( message, isError = false ) {
		statusEl.textContent  = message;
		statusEl.className    = isError ? 'notice notice-error inline' : 'notice notice-success inline';
		statusEl.style.display = 'block';
	}

	saveBtn.addEventListener( 'click', async () => {
		const rawKey = input.value.trim();
		if ( ! rawKey ) {
			setStatus( 'Please enter an API key.', true );
			return;
		}

		saveBtn.disabled = true;
		setStatus( 'Encrypting and saving…' );

		try {
			await AICSL.setupApiKey( rawKey );
			input.value = '';
			setStatus( 'Key encrypted and saved. The decryption key is stored in this browser session.' );
		} catch ( err ) {
			setStatus( `Error: ${ err.message }`, true );
		} finally {
			saveBtn.disabled = false;
		}
	} );
} );

// Export for use in connector.js
window.AICSL = AICSL;
