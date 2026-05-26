# AI Connector Secure Layer

A WordPress plugin that protects LLM API keys from server-side compromise by keeping the encryption key exclusively in the browser session.

## The Problem

WordPress AI Connectors (introduced in WP 7.0) need to store LLM API keys somewhere. Keys stored in `wp_options` or `wp-config.php` are readable by any code that executes on the server — including code injected through plugin vulnerabilities. A single compromised plugin on a site with a stored OpenAI key can expose a key with real financial value.

## How This Plugin Defends Against That

```
Browser                              Server (WordPress / Pantheon)
──────                               ─────────────────────────────
Generate AES-256-GCM key ──────────► /aicsl/v1/setup
Encrypt API key with it              Store ciphertext only (useless without key)
Store key in sessionStorage

On each LLM request:
Send key in X-AICSL-Key header ────► /aicsl/v1/complete
                                     Decrypt → call LLM API → discard key
                                     Return response
```

**What this protects against:**
- Database dump attacks — the stored value is ciphertext; the key is never on the server at rest
- PHP RCE when no admin LLM request is in flight — there is simply no key anywhere to intercept

**Residual risk:**

The decryption key travels in the `X-AICSL-Key` request header *only* during an active call to `/aicsl/v1/complete` — when the AI connector is actively being used, not during general WordPress admin activity. An attacker would need two things to coincide: a persistent server-side foothold that intercepts incoming requests, *and* a completion request happening at that same moment.

On a standard WordPress host with a writable filesystem, a compromised plugin could install a file-based backdoor that achieves this. On hosts with read-only server filesystems the persistent foothold vectors are narrowed, but database-stored payloads (serialization exploits, stored eval via options) remain possible on any host.

Compare this to storing a raw API key in `wp_options`: an attacker with any database read access retrieves it instantly, with no timing requirement and no active user session needed.

The key is held in `sessionStorage` (cleared when the browser tab closes) rather than `localStorage`. This is intentional — `localStorage` persists across sessions and is accessible to any JavaScript on the page, widening the XSS attack surface.

## Requirements

- PHP 8.1+
- WordPress 6.0+
- The `openssl` PHP extension (standard on all Pantheon environments)

## Installation

Install via Composer or drop the plugin directory into `wp-content/plugins/`. No Composer runtime dependencies — `vendor/` is dev-only.

```bash
composer require jazzsequence/ai-connector-secure-layer
```

Activate the plugin, then visit **Settings → AI Connector** to enter your LLM API key. The key is encrypted in your browser before submission.

## Development

```bash
composer install

# Unit tests (no WordPress required)
composer test

# Integration tests (requires WordPress test suite)
composer test:integration

# Linting
composer lint
composer lint:phpcbf   # auto-fix
```

### Running Integration Tests

Integration tests use [wpunit-helpers](https://github.com/pantheon-systems/wpunit-helpers) to bootstrap WordPress. You'll need a local WordPress test suite installed:

```bash
# Set environment variables for your local DB, then:
bash vendor/pantheon-systems/wpunit-helpers/bin/install-wp-tests.sh \
  wordpress_test root '' localhost latest
```

Then run:

```bash
WP_TESTS_DIR=/tmp/wordpress-tests-lib composer test:integration
```

## Security Architecture Notes

- Encryption: AES-256-GCM via the browser's native [Web Crypto API](https://developer.mozilla.org/en-US/docs/Web/API/SubtleCrypto)
- The 16-byte GCM authentication tag is appended to the ciphertext (matching Web Crypto's output format), then the combined value is base64-encoded for storage
- PHP decryption uses `openssl_decrypt` with `OPENSSL_RAW_DATA` and splits off the last 16 bytes as the tag
- `sodium_memzero()` is called on the plaintext API key after use (best-effort — PHP's GC may have already copied it)

## License

MIT
