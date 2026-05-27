=== AI Connector Secure Layer ===
Contributors: jazzs3quence
Tags: ai, llm, api-key, security, pantheon
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Keeps LLM API keys out of the WordPress database. Fetches keys from Pantheon Secrets or environment variables at request time — never stored in wp_options.

== Description ==

WordPress 7.0 AI Connectors store API keys in `wp_options` by default. This plugin replaces that storage model: keys are fetched from Pantheon Secrets or environment variables only at the moment an LLM API call is made, and never written to the database.

**How you connect an AI provider:**

1. Install the AI provider plugin (e.g. ai-provider-for-anthropic)
2. Install and activate this plugin
3. Run a Terminus command to set your key: `terminus secret:site:set your-site anthropic_api_key YOUR_KEY`
4. Reload Settings → Connectors — the provider shows "configured" and "Connected"

No key entry form. No database storage.

**What this protects against:**

* Database dump attacks — no key is ever written to wp_options
* Broad server-side attacks — no PHP constant is defined; the key exists in memory only during an active LLM request

This plugin is significantly safer than the default wp_options storage. See the README on GitHub for a complete threat model and the attack surface that remains.

== Installation ==

1. Upload `ai-connector-secure-layer` to `/wp-content/plugins/`
2. Activate the plugin
3. Install an AI provider plugin
4. Set keys via Pantheon Secrets or environment variables (see Configuration below)

== Configuration ==

= Pantheon =

```
terminus secret:site:set your-site anthropic_api_key YOUR_KEY --type=runtime --scope=web,user
terminus secret:site:set your-site google_api_key YOUR_KEY --type=runtime --scope=web,user
```

= Environment variables =

```
ANTHROPIC_API_KEY=YOUR_KEY
GOOGLE_API_KEY=YOUR_KEY
```

= Secret name convention =

* `anthropic` → `anthropic_api_key` / `ANTHROPIC_API_KEY`
* `google` → `google_api_key` / `GOOGLE_API_KEY`
* `openai` → `openai_api_key` / `OPENAI_API_KEY`

== Frequently Asked Questions ==

= Why can't I enter a key in Settings → Connectors? =

This plugin intentionally blocks that form from saving keys to the database. The admin notice above the Connectors page shows the Terminus command to use instead.

= Does this work without Pantheon? =

Yes — set standard environment variables at the server level. The plugin checks Pantheon Secrets first, then falls back to env vars.

= Will AI features work normally? =

Yes. The key is fetched at the moment each LLM request is made. From WordPress's perspective, everything works the same — the provider appears "configured" in Settings → Connectors and AI features function normally.

= What if I rotate my key? =

Update the Pantheon Secret or env var. The next LLM request automatically picks up the new value — no WordPress cache flush or plugin deactivation needed.

== Changelog ==

= 1.0.0 =
* Initial public release
* CI pipeline with unit and integration test matrix (PHP 8.2–8.5)
* Dependabot for Composer and GitHub Actions updates

= 0.2.0 =
* Complete rewrite: removed browser-key crypto approach
* Now uses Lazy_Auth — extends ApiKeyRequestAuthentication, overrides getApiKey() to fetch from Pantheon Secrets or env vars at request time
* Hooks: wp_connectors_init (block DB writes), init:21 (inject lazy auth), script_module_data filter (update UI state), admin_notices (Terminus instructions)
* TDD: 24 unit tests, integration test suite

= 0.1.0 =
* Initial release (browser-key model — superseded)
