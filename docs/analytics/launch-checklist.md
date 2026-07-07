# Launch checklist — analytics foundation (HOLD site)

## Environment variables (set before deploying this change)

| Variable | Purpose | Required now? |
|---|---|---|
| `POSTMARK_API_KEY` | Postmark Server API token — sending **and** the new read-only reporting service both use this. | **Yes** — sending breaks without it, since `config/sync/postmark.settings.yml` no longer carries a real key. |
| `MEL_GA4_MEASUREMENT_ID` | GA4 measurement ID (`G-XXXXXXXXXX`) for this environment. | No — analytics simply won't load until set (empty measurement ID short-circuits `AnalyticsService::buildDrupalSettings()`). |
| `MEL_SEARCH_CONSOLE_VERIFICATION` | Search Console HTML-tag verification value. | No — meta tag simply isn't emitted until set. |
| `MEL_ENVIRONMENT` | Already existed. Must be `production` for GA4/Search Console to ever load for real visitors — analytics never loads when this is `local`, regardless of the other variables. | Already in use. |

## Migrating an existing environment (Postmark key)

1. Look up (or generate) the current Server API token in the Postmark dashboard.
2. Set `POSTMARK_API_KEY` in that environment's variables **before** deploying this branch.
3. Deploy. Confirm outbound mail still works (waitlist confirmation, contact form).
4. Rotate the token in the Postmark dashboard (the previously-committed value must be considered compromised) and update `POSTMARK_API_KEY` to the new value.

## Permissions

Assign `administer myeventlane analytics` to whichever role(s) should reach `/admin/config/myeventlane/analytics`. Not granted to any role by default.

## Pre-production review (not built in this phase — flagged, not silently skipped)

- **No cookie-consent/CMP gating exists.** GA4 will load for anonymous and authenticated visitors (excluding administrators) as soon as `MEL_GA4_MEASUREMENT_ID` is set and `MEL_ENVIRONMENT=production`, with no consent banner in front of it. This needs a legal/privacy review before going live in production — it was out of scope for this phase because no consent module exists in the repository and none was requested.
- Confirm the Search Console property is verified using the deployed `MEL_SEARCH_CONSOLE_VERIFICATION` value before relying on Search Console data.
- phpstan/phpcs/eslint/stylelint/CI are not configured anywhere in this repository (confirmed in the audit) and were not added as part of this phase — a separate, explicit decision if wanted.
