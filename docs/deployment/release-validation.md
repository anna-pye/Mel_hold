# Release Validation

## Purpose

This document specifies the validation a future deployment repository must run before and after preparing a MyEventLane Hold release.

This is documentation only. It does not implement validation, change deployment scripts, change Drupal runtime behaviour, change Commerce, change themes, or change modules.

## Validation Principles

Release validation must fail closed. A failed required validation must abort the release before activation.

Production validation must not be skipped by default. If a non-production workflow allows optional checks, that exception must be explicit in the future deployment repository.

Validation must not print secrets.

## Repository Validation

Required repository checks:

- The working tree is clean.
- The selected branch is recorded.
- The selected commit SHA is recorded.
- `composer.json` exists.
- `composer.lock` exists.
- The Composer lock hash is recorded.

The future deployment runner must not activate a release from an unrecorded or dirty source state.

## Composer Validation

Required Composer checks:

- `composer validate` passes.
- Dependencies are installed using the release deployment policy.
- `vendor/autoload.php` exists after dependency installation.

This repository does not define whether `vendor/` is built locally, in CI, or on the target server. That decision belongs to the future deployment repository.

## Drupal Validation

Required Drupal file checks:

- `web/index.php` exists.
- `web/.htaccess` exists.
- `web/sites` exists.

Required Drush checks:

- `drush status` succeeds for the prepared release.
- `drush updatedb --no` succeeds before database updates are approved.
- `drush cr` succeeds during release verification.

Database updates must not be run without the backup or backup-confirmation policy defined by the future deployment repository.

## Vendor Validation

Required vendor checks:

- `vendor/autoload.php` exists.
- The Drush executable required by the deployment process is available after Composer install.

The current repository excludes `vendor/` from rsync deployment. A future release deployment must account for dependency installation as an explicit release step.

## Runtime Validation

Required runtime checks:

- The target environment is recorded.
- Required non-secret environment identifiers are present.
- Required secret names or secret references are present without exposing values.
- Drupal can bootstrap through Drush.
- Maintenance mode is off after deployment.

## Health Validation

Required health checks:

- Public HTTP health check returns HTTP 200.
- Maintenance mode is off.
- The active release identity matches the release selected for activation.

The exact health check URL is not confirmed by this repository and must be supplied by the future deployment repository.

## Activation Gate

A future deployment runner must not switch the active release until required pre-activation checks pass.

If any required validation fails after the release is prepared but before activation, deployment must abort and leave the previous active release unchanged.

## Validation Outcomes

Each release validation must record:

- Deployment ID.
- Release ID or release timestamp.
- Commit SHA.
- Composer lock hash.
- Target environment.
- Validation result.
- Failure reason, when validation fails.

Validation logs must avoid secret values.

## Unresolved Assumptions

The following items are intentionally not assumed by this repository:

- The final release path.
- The active `current` symlink path.
- The public health check URL.
- Whether validation runs locally, in CI, on the server, or across multiple stages.
- The backup checkpoint implementation before database updates.
