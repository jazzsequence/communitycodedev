/**
 * AI Connector Secure Layer — runtime connector.
 *
 * Sends LLM completion requests with the session encryption key in the
 * X-AICSL-Key header. The server decrypts the stored ciphertext per-request
 * and discards the key immediately after the LLM call.
 *
 * Usage:
 *   const result = await AIConnector.complete( 'Summarise this post.' );
 */

/* global aicslConfig, AICSL */

const AIConnector = ( () => {
	/**
	 * Send a prompt to the LLM API via the secure server-side proxy.
	 *
	 * @param {string} prompt
	 * @returns {Promise<object>} Raw LLM API response object.
	 * @throws {Error} If no session key is available or the request fails.
	 */
	async function complete( prompt ) {
		const keyBase64 = AICSL.getKeyFromSession();
		if ( ! keyBase64 ) {
			throw new Error(
				'No decryption key found in this session. ' +
				'Visit Settings → AI Connector and re-enter your API key.'
			);
		}

		const response = await fetch( aicslConfig.restUrl + 'complete', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': aicslConfig.nonce,
				// Encryption key travels in this header over HTTPS.
				// It is never stored server-side.
				'X-AICSL-Key': keyBase64,
			},
			body: JSON.stringify( { prompt } ),
		} );

		if ( response.status === 401 ) {
			// Key in session no longer matches stored ciphertext — user must re-setup.
			AICSL.clearSessionKey();
			throw new Error( 'Session key mismatch. Re-enter your API key in Settings → AI Connector.' );
		}

		const data = await response.json();

		if ( ! response.ok ) {
			throw new Error( data.error ?? `Request failed: ${ response.status }` );
		}

		return data;
	}

	return { complete };
} )();

window.AIConnector = AIConnector;
