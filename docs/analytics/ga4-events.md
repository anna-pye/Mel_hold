# GA4 events — HOLD site

All events are gated by `AnalyticsService::shouldTrack()`: never fired when `mel.environment === 'local'`, never fired for the `administrator` role, never fired when `myeventlane_analytics.settings:enabled` is false or no measurement ID is configured.

| Event | Trigger | Source |
|---|---|---|
| `page_view` | Automatic — GA4's default behavior on `gtag('config', ...)`. Not manually dispatched. | `js/ga4.js` |
| `404` | Current route is `system.404` (core's real exception route; confirmed via `system.site.yml` having no custom 404 page configured). | `hook_page_attachments()` |
| `search` | Current route is `search.view_node_search` (the exact route Drupal core's `SearchPageRoutes` generates for the enabled default search page) and the `keys` query parameter is non-empty. `params.search_term` is the query, truncated to 100 chars. | `hook_page_attachments()` |
| `login` | `hook_user_login()` — fires for every successful login regardless of role. | `myeventlane_analytics.module` |
| `sign_up` | `user_register_form` submit, only when the current route is `user.register` (excludes admin-created accounts at `/admin/people/create`, which shares the same form ID). | `myeventlane_analytics.module` |
| `waitlist_join` | `waitlist_signup_form` submit, when the honeypot was **not** tripped (`$form_state->get('honeypot_tripped')`). See "Why waitlist_join fires unconditionally" below. | `myeventlane_analytics.module` |
| `contact_form_submit` | `myeventlane_contact_form` submit, when the honeypot was not tripped **and** `$form_state->getRedirect()` is set (only the genuine mail-accepted path calls `setRedirectUrl()`; the mail-failure path returns early with no redirect). | `myeventlane_analytics.module` |

## Why `waitlist_join` fires unconditionally on genuine submission

`WaitlistManager::processSignupRequest()` always returns the same neutral string (`MESSAGE_NEUTRAL`) by design, specifically to prevent email enumeration — a new signup, a duplicate, a resubscribe, and a rate-limited request all look identical from the HTTP response. Coupling a GA4 event to the *real* outcome would require exposing that outcome somewhere observable, undermining the anti-enumeration property.

Instead: `waitlist_join` is a **marketing conversion signal** — it fires whenever a real visitor (not the honeypot) completes the form. **Precise business metrics** — true new signups vs. duplicates vs. resubscribes — are a server-side concern, answered by `WaitlistAnalyticsService::getEventTypeCounts()`, which reads the `myeventlane_waitlist_event` audit table `WaitlistManager` already writes to (`signup_created`, `signup_duplicate_confirmed`, `signup_refresh_pending`, `resubscribe_after_unsub`, `confirmed`, `confirm_expired`, `unsubscribed`). This is the concrete split the brief calls for: "Google Analytics exists only for marketing. Business intelligence belongs inside Drupal."

## Delivery mechanism

`404` and `search` are computed from the current route and delivered in the same response. `login`, `sign_up`, `waitlist_join`, and `contact_form_submit` are queued via `AnalyticsService::track()` into `PrivateTempStore` (because the triggering request redirects before a response body exists to carry them) and delivered on the visitor's next page load. See [architecture.md](architecture.md#cacheability) for why this doesn't break Internal Page Cache for ordinary anonymous traffic.

## Not built in this phase

`vendor_registration`, `event_created`, `event_published`, `checkout_started`, `purchase_complete` — no vendor, event, or Commerce entities exist in this repository. See [future-roadmap.md](future-roadmap.md).
