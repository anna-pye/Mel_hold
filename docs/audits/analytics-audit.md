# MyEventLane Analytics Foundation — Repository Audit (Phase 1, Deliverable 1)

**Date:** 2026-07-07
**Repository:** `myeventlane/hold` (DDEV project `myeventlane-hold`)
**Scope:** Read-only audit. No code changes were made while producing this document.

## 0. Headline finding

**This repository is the pre-launch public entry point for MyEventLane. It intentionally contains only the marketing, waitlist, and customer-acquisition experience. The Commerce marketplace is planned as the subsequent application, not present here.**

There is no Drupal Commerce installed, no vendor entity, no event entity, no checkout flow, no orders, and no revenue data anywhere in this codebase. The only application logic is a single custom module, `myeventlane_waitlist`, that runs the waitlist/contact experience: email waitlist signup with double opt-in, a contact form, and a CSV export admin screen. This is the correct, intended shape of this repository at this stage — it changes the achievable scope of Phase 1 accordingly, not because anything is missing or incomplete. See [Section 6](#6-risks) and the decision point at the end of this document, and [Future Integration](#7-future-integration) below for how the analytics foundation stays compatible with the Commerce platform once it exists.

## 1. Current architecture

### 1.1 Platform
- Drupal 11.3, PHP 8.3, MariaDB 10.11, DDEV project `myeventlane-hold`, docroot `web/`.
- Install profile: `standard` (config/sync/core.extension.yml).
- Base theme: `stable9`; active theme: `myeventlane_hold_theme` ("Holding page theme for MyEventLane (pastel glass layout)"), which declares a hard dependency on `myeventlane_waitlist`.

### 1.2 Modules enabled (config/sync/core.extension.yml)
Core: `automated_cron`, `big_pipe`, `block`, `block_content`, `ckeditor5`, `comment`, `datetime`, `dblog`, `dynamic_page_cache`, `field`, `field_ui`, `file`, `history`, `image`, `link`, `menu_link_content`, `menu_ui`, `node`, `options`, `page_cache`, `path`, `path_alias`, `search`, `search_help`, `search_node`, `shortcut`, `system`, `taxonomy`, `text`, `toolbar`, `update`, `user`, `views`, `views_ui`.
Contrib: `gin_toolbar`, `mailsystem`, `metatag`, `metatag_open_graph`, `metatag_twitter_cards`, `pathauto`, `postmark`, `redirect`, `schema_metatag`, `schema_organization`, `schema_web_site`, `simple_sitemap`, `token`.
Custom: `myeventlane_waitlist`.

**Not present:** `drupal/commerce` or any `commerce_*` submodule, `google_analytics`, `google_tag`, `matomo`, any cookie-consent/GDPR module, any custom analytics/tracking module, any custom vendor or event content entity module.

### 1.3 Content model
Only two node types exist: `page` and `article` (standard profile defaults — config/sync/node.type.*.yml). There is no `event` content type/entity, no `vendor` entity, no commerce product/order entity.

### 1.4 Roles (config/sync/user.role.*.yml)
`anonymous`, `authenticated`, `content_editor`, `administrator`. There is no `vendor` or `pro_vendor` role. The brief's requirement to differentiate tracking for "authenticated customers / vendors / Pro vendors" cannot be implemented today because those roles do not exist.

### 1.5 `myeventlane_waitlist` (the only custom module)
Location: [web/modules/custom/myeventlane_waitlist](web/modules/custom/myeventlane_waitlist)

- **Controllers:** `HoldingPageController`, `PrivacyController`, `WaitlistAdminController` (dashboard + CSV export), `WaitlistController`, `WaitlistExportController`.
- **Forms:** `ContactForm`, `WaitlistSettingsForm`, `WaitlistSignupForm`.
- **Services** ([myeventlane_waitlist.services.yml](web/modules/custom/myeventlane_waitlist/myeventlane_waitlist.services.yml)), all constructor-injected, no static calls:
  - `myeventlane_waitlist.token_manager` → `WaitlistTokenManager`
  - `myeventlane_waitlist.attribution_manager` → `WaitlistAttributionManager` (this is the closest thing to existing "analytics" — it appears to capture UTM/referrer attribution on signup)
  - `myeventlane_waitlist.rate_limit_manager` → `WaitlistRateLimitManager` (uses core `flood` service)
  - `myeventlane_waitlist.email_manager` → `WaitlistEmailManager` (uses `plugin.manager.mail`, `queue`, `database`)
  - `myeventlane_waitlist.manager` → `WaitlistManager` (orchestrator)
- **Queue:** `WaitlistMailQueue` (`QueueWorker` plugin) — confirms cron-driven email dispatch already exists; a future `EmailAnalyticsService`/queue metrics should observe this queue rather than create a new one.
- **Custom schema table:** `myeventlane_waitlist_subscriber` (hook_schema in `.install`), storing hashed confirm/unsubscribe tokens, consent flag + consent text, interest type, status.
- **Mail:** `hook_mail()` / `hook_mail_alter()` set headers and From address from `myeventlane_waitlist.settings` config; this is layered under `mailsystem` + `postmark` (see §1.7).
- **Theming:** two Twig templates (`mel-waitlist-status`, `mel-privacy-page`) registered via `hook_theme()`.

This module is well-structured (typed, DI-only, PSR-12-looking, config-driven) and should be treated as the reference standard for coding style in this repo.

### 1.6 Metatag / SEO / Search Console readiness
- `drupal/metatag` (^2.2) + `schema_metatag`, `metatag_open_graph`, `metatag_twitter_cards`, `schema_organization`, `schema_web_site` are installed and configured (config/sync/metatag.*.yml covers global, front, node, taxonomy_term, user, 403, 404 defaults).
- `drupal/simple_sitemap` (^4.2) is installed and configured (default + index sitemap variants, hreflang, custom links).
- `drupal/redirect` (^1.12) and `drupal/pathauto` (^1.14) are installed.
- `web/robots.txt` exists (standard Drupal default; not yet audited for custom disallow rules — see risks).
- **No Search Console verification mechanism exists yet** (no meta tag config field, no HTML file verification route).
- Core `search` + `search_node` are enabled, but there is no custom internal-search tracking and nothing indexed beyond `page`/`article` nodes.

### 1.7 Mail / Postmark
- `drupal/postmark` (^1.5) + `drupal/mailsystem` (^4.5) are installed, with `config/sync/postmark.settings.yml`, `mailsystem.settings.yml`, `system.mail.yml`, `user.mail.yml` present. `mailsystem` is retained because `postmark` depends on it (`postmark.info.yml` → `mailsystem:mailsystem`, and `drupal/postmark` requires `drupal/mailsystem` in Composer). `drupal/mimemail` was removed (unused after the Postmark migration — no code, config, or filter-format referenced it).
- `$settings['myeventlane.mail_mode']` (settings.php:339, driven by `MEL_MAIL_MODE` env var) toggles between `mailhog`/`smtp`/`sendmail` for the custom mail helper used by the waitlist module.
- No custom code currently reads Postmark's API for deliveries/bounces/opens/clicks/complaints/suppressions — outbound sending only. This is a legitimate, additive integration point (see Deliverable 4 in the brief) and does not conflict with anything existing.

### 1.8 Environment configuration convention (must be reused, not duplicated)
`web/sites/default/settings.php` already establishes the pattern this project uses for env-aware settings — **any GA4/analytics environment config must follow this same convention**:
```php
$settings['mel.environment'] = getenv('MEL_ENVIRONMENT') ?: 'local';   // line 344
$settings['myeventlane.mail_mode'] = getenv('MEL_MAIL_MODE') ?: 'php'; // line 339
```
Related environment files: `web/sites/default/settings.ddev.php`, `settings.local.example.php`, `settings.production.example.php`. A GA4 Measurement ID per environment should be sourced the same way (env var → `$settings[]` → config, never hardcoded, never committed).

### 1.9 Front-end build
Theme has **no SCSS build pipeline today** — `myeventlane_hold_theme.libraries.yml` attaches a single plain `css/style.css`, and `package.json` explicitly states "No npm build is required... Optional: add sass and a build script to compile scss/style.scss later." Any GA4/analytics JS must be added as a new Drupal library (attached via `hook_page_attachments`/render array `#attached`, no inline `<script>`), consistent with the brief's "no inline JavaScript" requirement — this part of the brief is achievable as-is.

### 1.10 Tooling / CI (relevant to the brief's validation section)
- No `phpstan.neon`, no `phpcs.xml`, no `.eslintrc*`, no `.stylelintrc*`, no `phpunit.xml`, no `.github/workflows/*` exist in the repo today.
- `composer.json` has no `require-dev` and no `scripts` section.
- Practical effect: several of the brief's mandated validation steps (`phpstan`, `eslint`, `stylelint`, `phpunit` "affected tests", twig lint) have **no configuration to run against** — they are not "0 findings," they are "not wired up." This should be reported as a gap, not silently skipped or invented.

## 2. What does **not** exist (verified absence)

| Brief assumption | Status |
|---|---|
| Drupal Commerce 3 | **Not installed.** No `commerce/*` packages in composer.json, no commerce config, no order/product entities. |
| Vendor entity / vendor registration flow | **Does not exist.** No `vendor` role, no vendor content type/entity, no registration form. |
| Event entity / event_created / event_published | **Does not exist.** No `event` content type or entity of any kind. |
| Checkout / purchase flow | **Does not exist.** No cart, checkout, or payment code anywhere. |
| Pro vendor role/tier | **Does not exist.** Only 4 roles total, none vendor-related. |
| Existing Google Analytics / Tag Manager / Matomo | **Not present** — confirmed via composer.json and full-repo grep. |
| Existing cookie consent / GDPR module | **Not present.** |
| Existing custom analytics/tracking service | **Not present.** Closest analogue is `WaitlistAttributionManager` (UTM/referrer capture on signup only). |
| Existing admin dashboards/reports | Only `WaitlistAdminController` (waitlist subscriber list + CSV export). No platform-wide reporting. |
| Search Console verification | **Not present.** |
| PHPStan/PHPCS/ESLint/Stylelint/PHPUnit config | **Not present.** |

## 3. Problems

1. **Scope mismatch.** The brief is written for a live marketplace (vendors, events, checkout, revenue). The repository is a pre-launch waitlist page. Building `VendorAnalyticsService`, `EventAnalyticsService`, or `RevenueAnalyticsService` today, or wiring `vendor_registration` / `event_created` / `event_published` / `checkout_started` / `purchase_complete` GA4 events, would require inventing entities, roles, and workflows that don't exist — which the brief itself forbids ("Do not invent code," "Never introduce duplicate business logic").
2. **No quality gates wired up.** phpstan/phpcs/eslint/stylelint/phpunit are not configured, so "validation" for those tools cannot produce a real result until they're added — that's a Phase 1-adjacent gap worth flagging to the user, but adding a full CI/tooling suite is itself outside this module's scope unless requested.
3. **No SCSS pipeline.** If analytics settings need an admin UI with custom styling, it will inherit the theme's plain-CSS-only setup unless a build step is added.
4. **Search Console verification has no home.** No config field, meta tag, or route currently exists to hold a verification token.

## 4. Risks

- **Risk of fabricated tracking:** implementing GA4 events for commerce/vendor/event actions that have no underlying trigger would mean firing events on nothing, or attaching them to placeholder code — misleading data from day one of the "first-party analytics platform."
- **Risk of role/permission drift:** designing `AnalyticsService` logic around "vendor" or "Pro vendor" segmentation now, before those roles exist, risks a mismatched data model once Commerce/vendor features land later, requiring rework.
- **Risk of duplicating `WaitlistAttributionManager`:** any new `WaitlistAnalyticsService` must wrap/reuse this existing service (and the existing `myeventlane_waitlist_subscriber` schema) rather than re-implement UTM/referrer capture.
- **Risk of duplicating the mail queue:** `EmailAnalyticsService`/Postmark reporting must read the existing `WaitlistMailQueue` and Postmark config, not create a parallel queue or duplicate `hook_mail()` logic.
- **Environment config risk:** if GA4 config doesn't follow the existing `$settings['mel.environment']` convention, environment detection logic will be duplicated with a second, inconsistent mechanism.

## 5. Recommendations

1. **Descope Phase 1 to what the repository actually supports today:**
   - GA4 events achievable now, with real triggers: `page_view`, `404`, `search`/`internal_search` (core `search` module), `sign_up`/`login` (core `user` register/login), `waitlist_join` (real: `myeventlane_waitlist`).
   - GA4 events **not achievable without inventing business logic**: `vendor_registration`, `event_created`, `event_published`, `checkout_started`, `purchase_complete`. Recommend defining these as a documented contract/interface in `myeventlane_analytics` (so the *shape* is ready) but not implementing dispatch until the corresponding Commerce/vendor/event modules exist. This avoids both "invent code" and "block all progress."
   - Build `AnalyticsService`, `WaitlistAnalyticsService`, `EmailAnalyticsService`, `PlatformHealthService` now (real data sources exist). Hold `VendorAnalyticsService`, `EventAnalyticsService`, `RevenueAnalyticsService` for the phase where Commerce/vendor/event modules land — creating them now means empty shells with no queries to run, which is technical debt by definition.
2. Reuse `WaitlistAttributionManager` and the `myeventlane_waitlist_subscriber` table for `WaitlistAnalyticsService` rather than re-deriving attribution.
3. Reuse `$settings['mel.environment']` for all environment-aware analytics config (GA4 Measurement ID per environment, "never load locally," "never load for admins").
4. Add Search Console verification as a config field on a new `myeventlane_analytics.settings` (or `search_console.settings`) config object, output via `hook_page_attachments` — additive, no conflicts.
5. Flag the missing phpstan/phpcs/eslint/stylelint/phpunit/CI configuration to the user as a separate, explicit decision (in scope or out of scope for this work) rather than silently skipping those validation steps later.

## 6. Decision required before writing any code

Per this project's own rules ("Do not assume anything... If an assumption cannot be verified: STOP"), the following must be confirmed with the user before Deliverables 2, 5, 6, 7 proceed:
- Confirm the descoped GA4 event list above (drop or stub the four commerce/vendor/event events).
- Confirm whether to build only the 4 real services now (`AnalyticsService`, `WaitlistAnalyticsService`, `EmailAnalyticsService`, `PlatformHealthService`) and document the other 3 as future-phase interfaces, or take a different approach.
- Confirm whether adding phpstan/phpcs/eslint/stylelint/phpunit scaffolding (currently absent) is in scope for this work or a separate task.

**Resolved:** the user accepted this audit and confirmed the descoped Phase 1 approach below.

## 7. Future integration

The analytics architecture implemented in this repository must remain compatible with the future MyEventLane Commerce platform.

No assumptions are made about Commerce entities. No `VendorAnalyticsService`, `EventAnalyticsService`, or `RevenueAnalyticsService` — nor any other Commerce/vendor/event-shaped code — is created in this phase, including as empty stubs or placeholders. Only services with a real, verifiable data source in this repository today are built: `AnalyticsService`, `WaitlistAnalyticsService`, `EmailAnalyticsService`, `PlatformHealthService`.

The extension point is `AnalyticsService::track(string $eventName, array $params = []): void` — a single, stable, already-generic public method. When the Commerce platform lands, a new `myeventlane_commerce_analytics` submodule can depend on `myeventlane_analytics` and add `VendorAnalyticsService` / `EventAnalyticsService` / `RevenueAnalyticsService` (querying whatever the real Commerce/vendor/event schema turns out to be at that time), calling `track()` for `vendor_registration`, `event_created`, `event_published`, `checkout_started`, `purchase_complete`, and any other future event — without modifying this module's public API. Full detail: [docs/analytics/future-roadmap.md](../analytics/future-roadmap.md).

`WaitlistAttributionManager` (in `myeventlane_waitlist`) is treated as the canonical attribution service: it is the sole place UTM/referrer/attribution parsing happens, at signup time. `WaitlistAnalyticsService` never re-implements or duplicates that parsing — it only reads the columns `WaitlistAttributionManager`'s output already populated on `myeventlane_waitlist_subscriber` (`utm_source`, `utm_medium`, `utm_campaign`, etc.), via `getSubscriberCountsByUtmSource()` / `getSubscriberCountsByUtmCampaign()`. No second attribution implementation exists anywhere in this codebase.

No source code has been added or modified in producing the original version of this audit; the module described in Sections 6–7 has since been implemented under the user-approved rescoped plan.
