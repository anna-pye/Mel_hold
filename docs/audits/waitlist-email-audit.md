# Waitlist & Email Architecture Audit

**Date:** 2026-07-09 · **Scope:** `myeventlane_waitlist`, mail transport (Postmark), environment wiring · **Method:** full code read + empirical reproduction in DDEV. Nothing below is assumed; every finding was reproduced or read directly from source.

## 1. Verified-facts check

| Claimed fact | Audit result |
|---|---|
| Postmark works; credentials valid | **Confirmed** (out of scope to re-test; consistent with code paths). |
| Queue `op=confirmation` → `sendConfirmationForSubscriber()` | **Confirmed** ([WaitlistMailQueue.php](../../web/modules/custom/myeventlane_waitlist/src/Plugin/QueueWorker/WaitlistMailQueue.php)). |
| Queue empty after cron ⇒ everything sent | **Refuted — dangerous.** The worker never throws, so a FAILED send still deletes the queue item. Reproduced: queue 1 → 0 with the send failing. "Queue empty" proves nothing. |
| Emails contain `http://default` links | **Confirmed and reproduced.** Bare `drush cron` (no `--uri`): `http://default/waitlist/confirm/T`. DDEV only works because DDEV exports `DRUSH_OPTIONS_URI`. |
| Browser shows "Unable to send email…" though mail succeeds | **Origin found — not the waitlist module.** See §2.2. |
| `Url::fromRoute(..., ['absolute' => TRUE])` during cron is the URL culprit | **Confirmed** ([WaitlistEmailManager.php](../../web/modules/custom/myeventlane_waitlist/src/Service/WaitlistEmailManager.php), `sendConfirmationForSubscriber()`). |

## 2. Root Cause Analysis

### 2.1 `http://default` links

CLI processes (drush cron, queue workers) have no HTTP request; Drupal's router context falls back to host `default`, scheme `http`. Three compounding facts:

1. **The repo's existing "fix" is dead code.** `settings.php:322` sets `$base_url` from `DRUPAL_BASE_URL` — a **Drupal 7 global, ignored since Drupal 8**. The intent existed; the mechanism does nothing.
2. **DDEV masks the bug**: it exports `DRUSH_OPTIONS_URI=https://myeventlane-hold.ddev.site`, so local testing always produced correct links.
3. The Hold server's cron runs bare drush → `http://default`.

**Rejected approaches (tested, not guessed):**
- *Repo `drush/drush.yml` with `uri: '${env.DRUPAL_BASE_URL}'`* — works when the var is set, but an unset var makes **every** drush command fail with `Invalid URI: Host is malformed` (including `ddev drush`). Too fragile; removed after testing.
- *Populating `$_SERVER` in settings.php for CLI* — drush builds its Request from `--uri` **before** settings.php can influence it; ineffective for drush.
- *Hardcoding URLs in code* — forbidden by requirements, and rightly so.

**Adopted architecture (two layers, both env-driven, no code per environment):**
- **Configuration layer:** `DRUSH_OPTIONS_URI` per environment — drush's native canonical-URI mechanism. Already documented in `deploy/production.env.example`; DDEV provides it natively; docker-compose/local set it in `.env`.
- **Enforcement layer (new):** `WaitlistEmailManager` refuses to send any email whose generated links resolve to host `default`, throwing a dedicated exception; the queue worker maps it to `SuspendQueueException` so **items are retained** and watchdog says exactly what to fix. An email containing `http://default` becomes impossible to send, in any environment, by construction.

### 2.2 The false "Unable to send email" browser message

The string exists in exactly one reachable place: **core `MailManager::doMail()`** (`core/lib/Drupal/Core/Mail/MailManager.php:318`). When a mail plugin returns `result: FALSE` it calls `messenger()->addError(...)` — *whatever context it runs in*.

- The signup form **sends no mail synchronously** (it only queues), so it can never legitimately produce this message.
- The confirmation send happens in **cron**. When cron is triggered through a web request — `automated_cron` at request end, the admin "Run cron" button, or `/cron/{key}` — the messenger error is written into **that browser session** and displayed on the next rendered page. Reproduced: running cron with a failing transport prints exactly `Unable to send email. Contact the site administrator if the problem persists.` from the queue send.
- Messenger messages persist in the session until displayed, so the error can surface long after the failed send, on an unrelated page — which is why it looked "impossible".

**Fix:** background sends pass `'_error_message' => ''` (the core-supported switch at that exact line), so queue/cron failures log — never message a browser. `ContactForm` (a genuine synchronous send) keeps its own specific error and also suppresses core's generic one, ending the double-message.

### 2.3 Silent queue loss (new finding)

`processItem()` returns normally on failure ⇒ core deletes the item. A transient Postmark outage silently loses confirmation emails; the subscriber stays `pending` forever and `last_sent_at` is set **even when nothing was sent**. Reproduced end-to-end. **Fix:** the manager throws on a FALSE mail result; the worker logs rich context and rethrows, so core **requeues** the item; `last_sent_at` is only written after a successful send.

## 3. Architecture review — other findings

| # | Severity | Finding |
|---|---|---|
| A1 | **Critical (out of scope, flagged)** | **`config/sync` was accidentally deleted from git by commit `5d8cd03`** (PR #29, a design-polish commit — 237 config files removed). Consequences: (a) the next Hold deploy pulls that deletion onto the server and `deploy-hold.sh`'s `drush config:import -y` then aborts every deploy (import refuses without `core.extension`); (b) `postmark.settings` / `myeventlane_waitlist.settings` now exist **only** in each environment's database. Restoring the pre-deletion baseline was tested and **rejected for this PR**: the baseline has drifted against live DBs in both directions (baseline: `claro` admin theme, no `mimemail`, `formatter: postmark_mail`; active DB: `gin`, `mimemail` still enabled, `formatter: php_mail`) — importing it via the deploy's `cim -y` would flip admin themes and uninstall modules on live. Config reconciliation needs its own deliberate export-review-commit cycle from the canonical environment. Until then the deploy's `config:import -y` step is a live hazard. |
| A2 | Medium | **Contrib bug (postmark 1.x):** `PostmarkHandler::sendMail()` has `catch (Exception $e)` — namespace-relative, i.e. `Drupal\postmark\Exception`, which doesn't exist. Network-level exceptions (Guzzle connect/timeouts) escape uncaught. Our worker's requeue-on-throw turns this into a safe retry rather than a loss. Documented; consider an upstream patch. |
| A3 | Low | `hook_mail_alter()` sets `headers['From']`, but `PostmarkMail` sends `postmark_sender_signature` config as From — the alter is **silently ignored** for Postmark. Harmless today (same address); misleading. Documented. |
| A4 | Low | `hook_mail()` key `waitlist_unsubscribe_notice` is dead — nothing sends it. Left in place (harmless), documented. |
| A5 | Low | Tokens are regenerated on every queued send — repeated pending signups invalidate earlier email links (last email wins). Acceptable by design; documented. |
| A6 | Info | Security hygiene is good: hashed tokens (`hash_equals`), hashed+peppered IPs, honeypots, flood control, neutral enumeration-safe responses, HTML escaping in both mail builders. |
| A7 | Info | Ops: watchdog shows a recurring warning that `config/sync/.htaccess` could not be written (container permissions) — unrelated to mail; worth an operator look. |

## 4. Remediation (implemented in small commits)

1. `chore(dev): add drupal/core-dev test tooling` (also records a pre-existing uncommitted `pathauto 1.x-dev` pin found on the dev machine, for lock consistency).
2. `docs(waitlist): this audit + RCA`.
3. `fix(mail): remove dead D7 $base_url; document DRUSH_OPTIONS_URI` (settings.php comment + `.env.example`; `deploy/production.env.example` already documented it).
4. `fix(waitlist): refuse placeholder-host emails; requeue failed sends; truthful last_sent_at; no browser messenger from background sends; richer logs`.
5. `test(waitlist): kernel coverage (5 tests / 27 assertions) + phpunit.xml`.

Each is independently revertable (`git revert <sha>`); no schema changes, no update hooks, no route/content changes. The config/sync restoration (A1) is intentionally **not** included — it needs its own reviewed reconciliation.

## 4a. Empirical validation results (DDEV)

| Scenario | Result |
|---|---|
| Bare CLI, no canonical URL (prod-cron conditions) | Send **blocked before any mutation**, queue **suspended**, item retained, log names the fix (`DRUSH_OPTIONS_URI`). No broken email exists. |
| Transport failure (empty API key) | Rich failure log (op, subscriber, recipient, mail id, result), worker rethrows → item **retained** for retry, `last_sent_at` untouched, **no "Unable to send email" messenger output** (baseline run showed it; `_error_message` suppression removes it). |
| Happy path (test collector) | Queue drains, captured email contains confirm + unsubscribe links on the canonical host, zero `http://default`, `last_sent_at` freshly recorded. |
| Kernel tests | 5 passed / 27 assertions: signup→queue, delivery+token round-trip (link tokens hash-match DB), placeholder-host guard (nothing sent, nothing mutated), worker `SuspendQueueException` mapping, transport failure throws with truthful state. |

## 5. Validation commands

```
ddev drush cr
ddev drush cron
ddev drush watchdog:show --count=20
ddev exec 'cd /var/www/html && env -u DRUSH_OPTIONS_URI vendor/bin/drush cron'   # guard: queue suspends, no broken email
ddev drush php:eval '...processSignupRequest(...)'                                # queue creation
ddev exec 'cd /var/www/html && vendor/bin/phpunit web/modules/custom/myeventlane_waitlist/tests/src/Kernel'
ddev drush sqlq "SELECT id,status,last_sent_at FROM myeventlane_waitlist_subscriber ORDER BY id DESC LIMIT 5"
```

## 6. Manual QA checklist (per environment)

1. Signup on `/` with a real address → neutral "Thanks…" message, **no error message**.
2. `drush queue:list` shows 1 item in `myeventlane_waitlist_mail`.
3. Run cron → queue 0 **and** watchdog shows no waitlist/mail/postmark errors.
4. Email received; confirm + unsubscribe links start with the canonical origin (never `http://default`).
5. Click confirm → "You are on the list" page; DB status `confirmed`.
6. Unsubscribe link → status page; DB `unsubscribed`.
7. Contact form: valid send → single success message. With transport broken → single specific error (no core generic duplicate).
8. Postmark activity page: message shows delivered with correct From (`info@myeventlane.com.au`).
9. With `DRUSH_OPTIONS_URI` unset (staging test only): cron logs "canonical base URL unavailable", queue retains items, no email goes out — then set the variable and re-run cron; the retained item sends correctly.

## 7. Rollback

All changes are additive/behavioural in the custom module + settings comments + one config export. `git revert` of any commit restores prior behaviour; no DB schema or content is touched. Reverting commit 2 restores silent-delete queue behaviour (not recommended). The exported `postmark.settings.yml` can be removed from config/sync to return it to active-only management.
