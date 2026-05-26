=== AI Connector Secure Layer ===
Contributors: jazzsequence
Tags: ai, llm, api-key, security, openai
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Browser-key-protected LLM API key storage for WordPress AI Connectors.

== Description ==

AI Connector Secure Layer protects LLM API keys from server-side compromise by ensuring the encryption key never leaves your browser session.

When you enter an API key in the settings page, it is encrypted in your browser using AES-256-GCM (via the Web Crypto API) before transmission. The server stores only the ciphertext. The decryption key lives in `sessionStorage` and is sent as a request header on each LLM API call — the server decrypts on demand and discards the key immediately.

**What this protects against:**

* Database dump attacks — a stolen database contains only ciphertext, which is useless without the key
* PHP remote code execution when the AI connector is not actively in use — there is no plaintext key anywhere on the server to intercept

**Residual risk:**

This plugin is significantly safer than storing a raw API key in `wp_options` or `wp-config.php`, but it does not eliminate all risk. See the README on GitHub for a full explanation of the remaining attack surface and its constraints.

== Installation ==

1. Upload the `ai-connector-secure-layer` directory to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **Settings → AI Connector**
4. Enter your LLM API key — it will be encrypted in your browser before being saved

Note: the encryption key is stored in `sessionStorage` and is lost when you close the browser tab. You will need to re-enter your API key at the start of each session.

== Frequently Asked Questions ==

= Why does closing the tab require re-entering my API key? =

The encryption key is intentionally stored only in `sessionStorage`, which is cleared when the tab closes. This is a security trade-off: `localStorage` would be more convenient but is readable by any JavaScript on the page, including via XSS. Re-entering the key is the price of the stronger guarantee.

= Does this work with Pantheon Secrets? =

This plugin stores the ciphertext in `wp_options`. On Pantheon, `wp_options` lives in the database, which is protected by Pantheon's network security but readable via PHP execution. This plugin's browser-key model provides additional protection on top of that — even if an attacker reads the database, they only get ciphertext.

= What LLM providers are supported? =

The initial implementation proxies to OpenAI's Chat Completions API. The architecture is designed to be extended — additional providers can be added to `includes/rest-api.php`.

= Is the API key ever logged? =

The plaintext key is never written to disk. WordPress debug logs, PHP error logs, and access logs will not contain it. `sodium_memzero()` is called on the decrypted key after use as a best-effort cleanup.

== Changelog ==

= 0.1.0 =
* Initial release
* AES-256-GCM client-side encryption via Web Crypto API
* `/aicsl/v1/setup` and `/aicsl/v1/complete` REST endpoints
* Admin settings page with security model disclosure
* Unit tests for crypto functions
* Integration tests for REST API endpoints
