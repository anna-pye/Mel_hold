# Analytics architecture — HOLD site

Scope: `myeventlane_hold` (the pre-launch waitlist/marketing site) only. See [docs/audits/analytics-audit.md](../audits/analytics-audit.md) for why Commerce/Vendor/Event/Revenue analytics are explicitly out of scope for this phase.

## Module

`web/modules/custom/myeventlane_analytics`, depending on `myeventlane_waitlist` and `postmark`. Dependency direction is one-way: `myeventlane_analytics` reads/observes `myeventlane_waitlist`; `myeventlane_waitlist` is never modified and has no dependency back on analytics.

## Services

| Service | Responsibility | Data source |
|---|---|---|
| `myeventlane_analytics.service` (`AnalyticsService`) | Gates GA4 by environment/role, queues/dispatches events, builds `drupalSettings` | `myeventlane_analytics.settings` config, `mel.environment`, `PrivateTempStore` |
| `myeventlane_analytics.waitlist_analytics` (`WaitlistAnalyticsService`) | Read-only waitlist funnel metrics | `myeventlane_waitlist_subscriber`, `myeventlane_waitlist_event` tables (owned by `myeventlane_waitlist`) |
| `myeventlane_analytics.email_analytics` (`EmailAnalyticsService`) | Read-only Postmark reporting | Postmark Server API via `wildbit/postmark-php`, reusing `postmark.settings` |
| `myeventlane_analytics.platform_health` (`PlatformHealthService`) | Cron/queue/log health | `system.cron_last` state, `myeventlane_waitlist_mail` queue, `watchdog` table |

All services are constructor-injected, `final`, and typed — no static `\Drupal::` calls inside services (procedural hooks in `.module` still use `\Drupal::service()`, matching the convention already used in `myeventlane_waitlist.module`).

## Event flow

1. **Immediate events** (404, internal search) are derived purely from the current route/request in `hook_page_attachments()` — safe to compute for any visitor hitting that URL, no session required.
2. **Deferred events** (login, sign_up, waitlist_join, contact_form_submit) are queued via `AnalyticsService::track()` into `PrivateTempStore` from a form submit handler or `hook_user_login()`, then consumed on the *next* page load — necessary because Drupal forms redirect before a response can carry the event.
3. `hook_page_attachments()` merges both into a single `drupalSettings.myeventlaneAnalytics` payload and attaches the `myeventlane_analytics/ga4` library, which does all `gtag.js` loading and event firing client-side. No inline `<script>` exists anywhere in Twig.

See [ga4-events.md](ga4-events.md) for the exact trigger for every event.

## Cacheability

- `PrivateTempStore` is only read when the current request already has an active session (`$request->hasSession() && $request->getSession()->isStarted()`). Touching it unconditionally on every anonymous page view would start a session for every visitor and defeat Internal Page Cache; gating on an already-active session avoids that while still delivering the queued event on the one response that needs it (which already can't be cached anyway, because it carries a session cookie).
- The page-attachments cache metadata always varies on `user.roles` (the load/no-load decision is role-based) and additionally on `session` only when a session-sourced event was actually consumed.
- BigPipe is unaffected — nothing here uses `#lazy_builder` placeholders; attachment happens at the `html`/`page_top` level like the theme's existing `hook_page_attachments_alter()`.

## Extending for a future Commerce platform

When Commerce/Vendor/Event functionality is merged into this codebase, add a new `myeventlane_commerce_analytics` submodule that:
- depends on `myeventlane_analytics`
- adds `VendorAnalyticsService` / `EventAnalyticsService` / `RevenueAnalyticsService` reading the (then-existing) Commerce/vendor/event schema
- calls the existing `AnalyticsService::track(string $eventName, array $params = [])` for any new GA4 event (`vendor_registration`, `event_created`, `event_published`, `checkout_started`, `purchase_complete`, etc.)

No changes to `myeventlane_analytics`'s public API are required — `track()` already accepts an arbitrary event name. See [future-roadmap.md](future-roadmap.md).
