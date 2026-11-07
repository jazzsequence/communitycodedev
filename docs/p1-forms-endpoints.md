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
| 500    | `{"error": "…"}`        | MailPoet failure (generic text; only the exception class and code are logged). See [Troubleshooting (newsletter)](#troubleshooting-newsletter) |
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

## Troubleshooting (newsletter)

### 500 and `APIException code 10` in the PHP log

```
cc-p1-forms: newsletter MailPoet failure (MailPoet\API\MP\v1\APIException code 10)
```

Code 10 is MailPoet's `CONFIRMATION_FAILED_TO_SEND`. The subscriber **was** saved (as `unconfirmed`,
so look under Subscribers > Unconfirmed, not in the list's own count) and the confirmation email
could not be sent. The secret, endpoint, list and double opt-in are fine, or you would see 401/503
instead. Retrying takes the resend path and fails the same way until the cause is fixed.

Only the class and code are logged on purpose (MailPoet messages can embed the address), and
MailPoet's own message here is generic, so the log will not say why. In MailPoet 5.39.0 the confirmation
send (`ConfirmationEmailMailer::sendConfirmationEmailMessage`) fails like this when:

| Cause | Result for the signup |
|---|---|
| The mailer is **paused** (`MailerLog::isSendingPaused()`) | Throws, so 500 / code 10 |
| The send throws or the Sending Service returns a failure | Throws, so 500 / code 10 |
| The sender address is flagged unauthorized while the Sending Service is on | Returns false **without throwing**: the signup answers `200` and no email is sent |
| The subscriber already received 3 confirmation emails (anonymous cap) | Same: `200`, no email |

So a success message does not prove an email went out.

**Tell MailPoet from this plugin or P1.** In MailPoet > Subscribers, use **Resend confirmation email**
on the saved subscriber. It runs entirely inside MailPoet. If that fails with "There was a problem with
your sending method", the cause is MailPoet's sending state, not the plugin or P1.

**Read the stored state** (read-only; prints no key and no address). `remote:wp` mangles quotes, so pass the PHP as base64:

```bash
cat > /tmp/mp-state.php <<'PHP'
$s=\MailPoet\Settings\SettingsController::getInstance();
$l=(array)$s->get("mta_log");
$e=isset($l["error"])?(array)$l["error"]:null;
echo json_encode(["status"=>$l["status"]??null,
  "error"=>$e?preg_replace("/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+/","[email]",(string)($e["error_message"]??"")):null,
  "method"=>$s->get("mta.method"),
  "sender_check_keys"=>array_keys((array)$s->get("authorized_emails_addresses_check"))])."\n";
PHP
terminus remote:wp communitycodedev.live -- eval "eval(base64_decode('$(base64 < /tmp/mp-state.php | tr -d '\n')'));"
```

`status: "paused"` with an `error` is the pause. The MailPoet admin did not show a notice for it.

### Mailer paused

Seen on 2026-11-06: `status: paused`, error `MailPoet API key is invalid!`, left by an earlier send
while the Sending Service key state was bad. The key later showed "valid" again, but
**re-verifying the key does not unpause the mailer**, and a test email from Settings > Send With
is not a reliable signal. A normal state after resuming is `status: null` and no error.

**Before resuming, check what is being held.** Resuming releases every held send on MailPoet's next
cron run. On that day 9 stale post-notification tasks (11 recipients each, never processed since
2026-06-29) were queued and would all have gone out. List them first:

```sql
SELECT t.id, t.scheduled_at, q.count_total, q.count_processed, n.type
FROM wp_mailpoet_scheduled_tasks t
JOIN wp_mailpoet_sending_queues q ON q.task_id = t.id
JOIN wp_mailpoet_newsletters n ON n.id = q.newsletter_id
WHERE t.type = 'sending' AND t.status IS NULL AND t.deleted_at IS NULL;
```

Set any you do not want sent to `paused` (reversible), or cancel or delete them in the MailPoet
admin, then resume with the admin's **Resume sending** action or `\MailPoet\Mailer\MailerLog::resumeSending()`
(via `wp eval`). Then retry the signup; the saved `unconfirmed` subscriber takes the resend path.

The status report (MailPoet > Help > System Status) shows `Sending Queue: Status: paused` and
`Last seen error: SendingQueue: Sending has been paused` for this. `Premium key state:
valid_underprivileged` next to a valid Sending Service key was reported on the free plan and is
not the problem.
