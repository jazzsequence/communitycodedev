# P1 form endpoints (contact + newsletter)

Receivers for the headless Next.js (P1) frontend's native forms. The P1 server POSTs here because
Ninja Forms' reCAPTCHA is bound to communitycode.dev and its AJAX endpoint needs page-specific
nonces, and MailPoet has no safe public subscribe endpoint. Rate limiting and bot checks are the
P1 side's job; these endpoints only authenticate, validate and act.

Each route has its **own** bearer secret, so a leak of one cannot be used against the other.

Code: `web/app/mu-plugins/cc-p1-forms/` (loaded by the Bedrock autoloader like the other
mu-plugin directories). Check scripts: `tests/cc-p1-forms/check-contact-endpoint.php` and
`tests/cc-p1-forms/check-newsletter-endpoint.php`.

# Contact: `POST /wp-json/community-code/v1/contact`

## Contact contract

`POST https://communitycode.dev/wp-json/community-code/v1/contact`

```
Authorization: Bearer <CC_P1_CONTACT_SECRET>
Content-Type: application/json

{ "name": "…", "email": "…", "message": "…" }
```

| Field     | Rule                                  | Sanitised with            |
|-----------|---------------------------------------|---------------------------|
| `name`    | text, 1-100 chars                     | `sanitize_text_field`     |
| `email`   | `is_email()`, max 254 chars           | `sanitize_email`          |
| `message` | text, 1-5000 chars (newlines kept)    | `sanitize_textarea_field` |

| Status | Body                    | When                                                    |
|--------|-------------------------|---------------------------------------------------------|
| 200    | `{"ok": true}`          | Recorded (if possible) and emailed                      |
| 400    | `{"error": "<message>"}`| Invalid or missing field                                |
| 401    | `{"error": "…"}`        | Missing or wrong secret                                 |
| 500    | `{"error": "…"}`        | `wp_mail()` failed (no details leaked)                  |
| 503    | `{"error": "…"}`        | Secret not configured on this environment (fails closed)|

Every response carries `Cache-Control: no-store`. Auth is evaluated before validation, so an
unauthenticated caller always sees 401/503, never 400.

## What happens on a valid request

1. **Ninja Forms entry** on form 1 ("Contact Me"), via the same model calls Ninja Forms' own
   "Record Submission" action uses. Skipped silently if Ninja Forms is inactive, the form no longer
   has `name`/`email`/`message` field keys, or `ninja_forms_save_submission` returns false.
2. **Email** via `wp_mail()` to the recipients of form 1's first active Email action with a fixed
   address (`{wp:admin_email}` resolves to the admin address; `{field:…}` autoresponders are
   skipped), falling back to `admin_email`. No `From` header is set, so the site's normal sender
   is used (SPF/DKIM/DMARC); the visitor is in `Reply-To`. Subject:
   `[Community + Code] Message from <name>`. Plain text.

The entry is stored before the email is sent so a mail failure cannot lose the message. The
flip side: a 500 can leave a recorded entry, and a retry would create a second one.

## The contact secret

The plugin reads the constant `CC_P1_CONTACT_SECRET`, defined in `config/application.php` from the
environment variable of the same name first (local), then from the Pantheon secret `cc_p1_contact_secret`
via `pantheon_get_secret()`. Pantheon secret names on this site are lowercase snake_case (`ep_host`,
`ep_token`, ...); the PHP constants stay uppercase.
Generate a long random value, e.g. `openssl rand -base64 48`.

Pantheon (per environment; repeat for dev/test/live as needed):

```bash
terminus secret:site:set communitycodedev cc_p1_contact_secret <value> --scope=user,web
```

Local (Lando), either of:

- `.env.local` in the repo root: `CC_P1_CONTACT_SECRET=<value>` (gitignored)
- `secrets.json` in the repo root: `{ "cc_p1_contact_secret": "<value>" }` (lowercase, like the Pantheon secret) (gitignored; merge with
  the existing keys, or use the `Secrets.<key>.Value` structure Terminus writes)

Until it is set, every request returns 503. Never commit the value.

## P1 side (contact)

```
CONTACT_ENDPOINT=https://communitycode.dev/wp-json/community-code/v1/contact
CONTACT_SECRET=<same value as CC_P1_CONTACT_SECRET>
```

Send the secret only from the server (never to the browser) and over HTTPS.

## Verifying (contact)

No PHPUnit harness exists in this repo, so the check is a WP-CLI script run against a local site.
It uses `rest_do_request()` in-process and intercepts mail with `pre_wp_mail`, so nothing is sent:

```bash
lando wp eval-file tests/cc-p1-forms/check-contact-endpoint.php          # no DB writes
lando wp eval-file tests/cc-p1-forms/check-contact-endpoint.php record   # also exercises Ninja Forms recording, then deletes the entry
```

It covers 503 (no secret), 401 (wrong/missing), 400 (bad input), 200 (valid, mail shape), and 500
(mail failure).

## Adding another route

Add `includes/<name>.php`, require it in `cc-p1-forms.php`, call its register function from
`register_routes()`, and add the route and its own secret constant to `ROUTES`. Auth, error shape
and `no-store` come for free.

# Newsletter: `POST /wp-json/community-code/v1/newsletter`

MailPoet double opt-in signup. Written against the MailPoet public PHP API
(`\MailPoet\API\API::MP( 'v1' )`) as read from the installed plugin source, **5.38.0**.

```
Authorization: Bearer <CC_P1_NEWSLETTER_SECRET>
Content-Type: application/json

{ "email": "ada@example.com", "first_name": "Ada" }
```

| Field        | Rule                                                              |
|--------------|-------------------------------------------------------------------|
| `email`      | required, `is_email()`, max 254 chars; `sanitize_email`           |
| `first_name` | optional, max 80 chars after `sanitize_text_field` (collapses whitespace) |

| Status | Body                    | When                                                                  |
|--------|-------------------------|-----------------------------------------------------------------------|
| 200    | `{"ok": true}`          | Subscribed (confirmation emailed) **or** nothing to do; indistinguishable |
| 400    | `{"error": "<message>"}`| Invalid address or first name                                         |
| 401    | `{"error": "…"}`        | Missing or wrong secret                                               |
| 500    | `{"error": "…"}`        | MailPoet failure (generic text; only the exception class and code are logged) |
| 503    | `{"error": "…"}`        | Secret unset, MailPoet inactive, double opt-in disabled in MailPoet, or no unambiguous list |

Every response carries `Cache-Control: no-store`; auth runs before validation (401/503, never 400,
for an unauthenticated caller).

## Behaviour

| Address state in MailPoet             | Action                                              |
|---------------------------------------|-----------------------------------------------------|
| Not present                           | `addSubscriber()` to the list, confirmation email on, welcome email off |
| Present, `unconfirmed`                | `subscribeToLists()` re-sends the confirmation. MailPoet itself stops after 3 confirmation emails per subscriber for anonymous callers |
| Present, `subscribed`                 | No-op                                               |
| Present, `unsubscribed`               | No-op (see below)                                   |
| Present, `bounced` / `inactive`       | No-op                                               |
| Present, in the MailPoet trash        | No-op (an admin removed them; `subscribeToLists()` would restore them) |

All of those answer `200 {"ok": true}`, so the endpoint cannot be used to find out who is on the
list. Response *timing* is not equalised: a brand-new address also sends a confirmation email.

Why unsubscribed is a no-op: in 5.38.0 `subscribeToLists()` on an unsubscribed subscriber flips them
to `unconfirmed` and mails a confirmation. That does go through double opt-in, but it erases an
explicit opt-out on a request the subscriber may not have made. To change that, add
`'unsubscribed'` to `NEWSLETTER_RESEND_STATUSES` in `includes/newsletter.php`. A deliberate
unsubscriber can still re-join through MailPoet's own form.

MailPoet's global **Enable sign-up confirmation** must stay on. With it off `addSubscriber()` would
subscribe addresses immediately and send no confirmation, so the route answers 503 instead.

### Which list

1. `CC_P1_NEWSLETTER_LIST_ID` (secret `cc_p1_newsletter_list_id`), if set (the numeric MailPoet list id: MailPoet > Lists).
2. Otherwise the only list MailPoet has (`getLists()` returns default-type lists only; the WordPress
   Users and WooCommerce Customers segments are excluded).
3. Otherwise 503, with a one-line config error in the PHP log (no submitted data).

The site's current signup form is the MailPoet block `formId: 1` in
`web/app/themes/communitycode-theme/parts/footer.html`. The lists it adds people to are stored in
that form's settings (`segments`, see `FormEntity::getSettingsSegmentIds()`; MailPoet > Forms >
Edit > Form settings > "Add subscribers to"). That lives in the database, so check it there and set
`CC_P1_NEWSLETTER_LIST_ID` to match if the site has more than one list.

## The newsletter secret and list id

Same mechanism as the contact secret (env var first, then the lowercase Pantheon secret, via
`config/application.php`). Secrets are `runtime` type with scopes user+web, like the existing ones. Use a different value from the contact secret.

```bash
terminus secret:site:set communitycodedev cc_p1_newsletter_secret <value> --scope=user,web
# optional, only if getLists() is not exactly one list:
terminus secret:site:set communitycodedev cc_p1_newsletter_list_id <id> --scope=user,web
```

Local (Lando): `CC_P1_NEWSLETTER_SECRET=<value>` (and optionally `CC_P1_NEWSLETTER_LIST_ID=<id>`)
in `.env.local` (uppercase env names), or `cc_p1_newsletter_secret` / `cc_p1_newsletter_list_id` in
`secrets.json` (lowercase, like the Pantheon secrets). Until the secret is set every request returns 503.

## P1 side (newsletter)

```
NEWSLETTER_ENDPOINT=https://communitycode.dev/wp-json/community-code/v1/newsletter
NEWSLETTER_SECRET=<same value as CC_P1_NEWSLETTER_SECRET>
```

P1 maps our 400 to "address wasn't accepted" and everything else to a generic failure, which
matches this contract.

## Verifying (newsletter)

```bash
lando wp eval-file tests/cc-p1-forms/check-newsletter-endpoint.php
```

MailPoet is replaced by an in-memory fake through the `cc_p1_forms_mailpoet_api` filter, so no
subscriber is created and nothing is sent. It checks the route's logic against the API contract
above; it does not prove real MailPoet behaves that way, so do one manual signup on a local site
before relying on it.

## Deploying and checking each environment

This is a Bedrock mu-plugin in a directory, so the autoloader picks it up: there is nothing to
activate. It only exists on an environment once its code has synced, so merging to `main` is not
enough: dev syncs from `main`, and test and live only get it when you deploy.

Probe an environment without needing the secret. An empty POST is harmless:

```bash
curl -s -o /dev/null -w '%{http_code}\n' -X POST -H 'Content-Type: application/json' -d '{}' \
  https://<environment host>/wp-json/community-code/v1/contact
```

| Status | Meaning |
|---|---|
| 404 | The code is not on that environment (not synced or not deployed yet). |
| 503 | Deployed, but its `cc_p1_*_secret` is not set (the route refuses everything, never open). |
| 401 | Deployed and the secret is set; the probe just has no valid bearer token. |

Repeat with `/newsletter`. If dev still answers 404 after a merge, check whether the `Sync code on
"dev"` workflow failed (`terminus workflow:list communitycodedev`); the merged code builds with
`composer install --no-dev` locally, so a failed sync is a platform-side problem, not the plugin.
