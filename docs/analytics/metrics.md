# Canonical metrics — HOLD site phase

Only metrics with a real, verified data source in this repository. Anything Commerce/vendor/event-shaped is deferred — see [future-roadmap.md](future-roadmap.md).

| Metric | Origin |
|---|---|
| Visitors, page views, search terms, 404s, sign-ups, logins (marketing/conversion view) | GA4 (client-side, via `myeventlane_analytics/ga4`) — the only source for visitor-level analytics; this module does not duplicate visitor counting server-side |
| Waitlist subscribers by status (pending/confirmed/unsubscribed) | `WaitlistAnalyticsService::getSubscriberCountsByStatus()` → `myeventlane_waitlist_subscriber.status` |
| Waitlist lifecycle events (created, duplicate, resubscribe, confirmed, expired, unsubscribed) | `WaitlistAnalyticsService::getEventTypeCounts()` → `myeventlane_waitlist_event.event_type` |
| Waitlist subscribers by UTM source / campaign | `WaitlistAnalyticsService::getSubscriberCountsByUtmSource()` / `getSubscriberCountsByUtmCampaign()` → the `utm_source`/`utm_campaign` columns `WaitlistAttributionManager` already populates at signup (via `WaitlistManager`) — no attribution parsing is re-implemented here; `WaitlistAttributionManager` is the sole, canonical attribution service |
| Accounts (registered users) | Core `user` entity — no wrapper needed; use `\Drupal::entityQuery('user')` directly if/when a report is built |
| Email deliveries, bounces, opens, clicks, complaints, suppressions | `EmailAnalyticsService` → Postmark Server API |
| Cron health (last run) | `PlatformHealthService::getCronLastRun()` → `system.cron_last` state |
| Queue depth (waitlist mail) | `PlatformHealthService::getWaitlistMailQueueSize()` → the existing `myeventlane_waitlist_mail` queue |
| Recent error volume | `PlatformHealthService::getRecentErrorCount()` → `watchdog` table (dblog) |
| Search Console (impressions, clicks, indexing) | Not owned by this module — lives in Google Search Console itself; this module only owns the verification tag. Metatag owns title/description/canonical/OG/Twitter/Schema; Simple Sitemap owns the sitemap (see [docs/architecture/seo.md](../architecture/seo.md)) |

## Explicitly out of scope this phase

Vendors, published events, orders, revenue, platform fees — no such entities exist yet (see [docs/audits/analytics-audit.md](../audits/analytics-audit.md)). No metric is fabricated in their absence.
