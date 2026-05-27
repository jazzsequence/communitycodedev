# AI Connector Secure Layer

![WordPress 
  7.0+](https://img.shields.io/badge/WordPress-7.0%2B-0073aa?logo=wordpress&logoColor=white)
![GitHub Release](https://img.shields.io/github/v/release/jazzsequence/ai-connector-secure-layer)
![GitHub License](https://img.shields.io/github/license/jazzsequence/ai-connector-secure-layer)
[![CI](https://github.com/jazzsequence/ai-connector-secure-layer/actions/workflows/ci.yml/badge.svg)](https://github.com/jazzsequence/ai-connector-secure-layer/actions/workflows/ci.yml)

Keeps LLM API keys out of the WordPress database. Works with WordPress 7.0 AI Connectors and Pantheon Secrets.

## The problem

WordPress 7.0 AI Connectors store API keys in `wp_options` by default. A database dump, SQL injection, or any plugin vulnerability that exposes the database immediately exposes every API key on the site — with no active session required.

## How this plugin works

Instead of storing keys in the database, the plugin fetches them on-demand from Pantheon Secrets (or environment variables) at the exact moment an LLM HTTP request is made — and only then.

```
init:15  wp_connectors_init fires
           → plugin registers pre_update_option hooks to block DB writes for all AI connector options

init:20  _wp_connectors_pass_default_keys_to_ai_client() runs
           → finds empty DB options (writes blocked) → skips all providers

init:21  plugin injects Lazy_Auth into the AI client registry for each configured provider
           → Lazy_Auth stores only the provider ID, not the key

LLM request fires (e.g. Gutenberg AI feature)
           → model calls getApiKey() on Lazy_Auth
           → pantheon_get_secret() or getenv() is called HERE
           → real key injected into request headers
           → key exists in PHP memory only for this call
```

**What this protects against:**
- Database dump — no key is ever written to `wp_options`
- Broad PHP execution — no PHP constant is defined; the key is not in any global
- Idle requests — key only exists in memory during an active LLM API call

**What this does not protect against:**
- An attacker with PHP code execution who knows to call `pantheon_get_secret()` directly can still retrieve the key. No server-side architecture can prevent this: the key must be available to PHP when the LLM call is made.

See the detailed threat model notes in the [Security Model](#security-model) section.

## Requirements

- WordPress 7.0+
- PHP 8.1+
- A WordPress AI provider plugin (`ai-provider-for-anthropic`, `ai-provider-for-google`, etc.)
- Pantheon Secrets or environment variables for key storage

## Installation

```bash
composer require jazzsequence/ai-connector-secure-layer
```

Activate the plugin, then install an AI provider plugin. **Do not enter API keys in Settings → Connectors** — use Terminus instead.

## Configuration (Pantheon)

For each provider you want to connect, set the key via Terminus:

```bash
terminus secret:site:set your-site-name anthropic_api_key sk-ant-YOUR_KEY --type=runtime --scope=web,user
terminus secret:site:set your-site-name google_api_key YOUR_GOOGLE_KEY --type=runtime --scope=web,user
```

**Secret name convention:** `{provider_id}_api_key`

| Provider ID | Secret name |
|-------------|-------------|
| `anthropic` | `anthropic_api_key` |
| `google` | `google_api_key` |
| `openai` | `openai_api_key` |

Once set, reload Settings → Connectors — the provider shows "This API key is configured as a constant." with a green Connected badge. No key entry form is shown.

## Configuration (non-Pantheon)

Set an environment variable at the server level:

```bash
ANTHROPIC_API_KEY=sk-ant-YOUR_KEY
GOOGLE_API_KEY=YOUR_GOOGLE_KEY
```

**Convention:** `strtoupper({provider_id}) . '_API_KEY'`

## User experience

### Before configuring a key

Settings → Connectors shows the provider with a "Set up" button and an admin notice:

> **AI keys managed via Pantheon Secrets**
> This site manages AI provider API keys through Pantheon Secrets — not through this form.
> Keys entered here cannot be saved. To connect a provider, run:
> `terminus secret:site:set your-site anthropic_api_key YOUR_KEY`

### After configuring via Terminus

The provider shows:
- Read-only field with "This API key is configured as a constant."
- Green "Connected" badge
- No input field — nothing to save

### AI features

WordPress AI features (Gutenberg AI blocks, etc.) work normally once a key is configured. The key is fetched at request time — no restart or cache flush needed.

## Security model

**Key is never in:**
- `wp_options` (writes are blocked)
- A PHP constant (`ANTHROPIC_API_KEY` is never defined)
- The AI client registry at init time (lazy auth holds only the provider ID)

**Key is in PHP memory only during:**
- The `getApiKey()` call inside `AnthropicTextGenerationModel::getRequestAuthentication()`
- The subsequent HTTP request to the LLM API

**Remaining attack surface:**

An attacker with arbitrary PHP code execution can still call `pantheon_get_secret('anthropic_api_key')` directly. The key must be available to PHP at request time — this cannot be eliminated at the plugin level for server-side LLM calls. This is meaningfully narrower than the default `wp_options` approach, where DB read access alone is sufficient to retrieve the key.

For more on the threat model and Oliver Sild's analysis that motivated this plugin, see the [WordPress.org readme](readme.txt).

## Development

```bash
cd web/app/plugins/ai-connector-secure-layer
composer install

# Unit tests (no WordPress required)
composer test

# Integration tests (requires WordPress test suite)
composer test:integration

# Linting
composer lint
```

## License

MIT
