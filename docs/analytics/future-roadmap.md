# Future roadmap — after the Commerce platform merges

This phase deliberately built nothing that assumes Commerce, vendors, published events, checkout, or revenue exist — because they don't, in this repository, today (see [docs/audits/analytics-audit.md](../audits/analytics-audit.md)).

## Extension point already in place

`AnalyticsService::track(string $eventName, array $params = []): void` is the stable public API. It already handles gating (environment/role), the pending-event queue, and drupalSettings delivery for any event name — not just the ones this phase uses.

## What a future phase should do

Add a new submodule, e.g. `myeventlane_commerce_analytics`, that:

1. Depends on `myeventlane_analytics` (and the real Commerce/vendor/event modules once they exist).
2. Adds `VendorAnalyticsService`, `EventAnalyticsService`, `RevenueAnalyticsService` — read-only, querying whatever the real Commerce/vendor/event schema turns out to be at that time (do not guess the schema now).
3. Wires the real triggers for the events this phase explicitly did not build:
   - `vendor_registration` — on real vendor account/profile creation
   - `event_created` — on real event entity creation
   - `event_published` — on the real publish transition
   - `checkout_started` — on Commerce checkout flow entry
   - `purchase_complete` — on Commerce order completion
   Each calls `AnalyticsService::track()` with the appropriate event name and params — no change to `myeventlane_analytics` itself is required.
4. Extends `docs/analytics/metrics.md` with orders, revenue, fees, and vendor/event counts, sourced from the real Commerce entities at that time.

## Also deferred, not forgotten

- Cookie-consent/CMP gating in front of GA4 (see [launch-checklist.md](launch-checklist.md) — flagged as a pre-production legal/privacy risk).
- A dashboard/reporting UI over any of the services this phase built (`WaitlistAnalyticsService`, `EmailAnalyticsService`, `PlatformHealthService`) — explicitly out of scope per the brief ("Do NOT build dashboards").
- phpstan/phpcs/eslint/stylelint/CI scaffolding for the repository as a whole.
