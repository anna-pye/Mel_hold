# Postmark reporting — architecture only (no dashboard)

## What already existed

`drupal/postmark` (installed, `^1.5`) provides only outbound sending: `PostmarkHandler::sendMail()`, used via the `mailsystem`-configured mail plugin. It has no reporting API. This work does not replace or duplicate any of that — `PostmarkHandler`, `PostmarkMail`, and `PostmarkSettingsForm` are untouched.

## What was added

`EmailAnalyticsService` (`myeventlane_analytics.email_analytics`), a read-only wrapper around `\Postmark\PostmarkClient` — the same `wildbit/postmark-php` client `drupal/postmark` already depends on (present at `vendor/wildbit/postmark-php`). It builds its own client instance from the same `postmark.settings:postmark_api_key` config `PostmarkHandler` sends with — no second credential, no duplicated HTTP/API code.

Methods (all return `NULL` on failure/missing key, logged via `logger.channel.myeventlane_analytics`, never throw into the caller):

- `getDeliveryStatistics()`
- `getBounces($count, $offset)`
- `getSuppressions()`
- `getOpenStatistics($count, $offset)`
- `getClickStatistics($count, $offset)`
- `getComplaintStatistics()`

No controller, route, or admin UI renders this data yet — that's an explicit non-goal for this phase ("Architecture only. No dashboard yet.").

## Security finding and remediation (this phase)

`config/sync/postmark.settings.yml` had a **live Postmark Server API token committed in plaintext** (found during the audit, pre-existing, not introduced by this work). Fix-forward applied in this phase:

- `config/sync/postmark.settings.yml` → `postmark_api_key: ''` (blanked; config export will never carry a real key again).
- `web/sites/default/settings.php` → `$config['postmark.settings']['postmark_api_key'] = getenv('POSTMARK_API_KEY') ?: '';`, following the exact style of the existing `mel.environment` / `myeventlane.mail_mode` overrides.
- Git history was **not** rewritten (explicit decision — disruptive for any collaborators/CI).

### Required action before/at deploy, per environment

Set `POSTMARK_API_KEY` in the environment **before** deploying this change, or outbound mail (waitlist confirmations, contact form, unsubscribe notices) will silently stop sending. This applies to local (DDEV), staging, and production.

### Required action after deploy

**Rotate the exposed key in the Postmark dashboard.** The token that was committed in `config/sync/postmark.settings.yml` (now removed from config/sync but still present in git history, so not reproduced in this document) must be treated as compromised and replaced with a new Server API token, which then becomes the value of `POSTMARK_API_KEY`. This rotation is the user's action to perform, not automated here.
