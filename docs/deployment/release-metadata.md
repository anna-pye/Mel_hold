# Release Metadata

## Purpose

This document specifies the future release metadata format for MyEventLane Hold release-based deployments.

This is documentation only. It does not generate metadata, change deployment scripts, change Drupal runtime behaviour, change Commerce, change themes, or change modules.

## Metadata Ownership

The future deployment repository must generate, persist, and validate release metadata.

This repository only defines the expected metadata shape so future deployment tooling can identify exactly what source, Composer state, and runtime context produced a release.

## Required Metadata Fields

Future release metadata must include:

- `deployment_id`: Unique deployment request identifier.
- `repository`: Source repository URL or repository identifier.
- `branch`: Source branch selected for deployment.
- `commit`: Full commit SHA selected for deployment.
- `release`: Release identifier or release timestamp.
- `composer_lock_sha256`: SHA-256 hash of `composer.lock`.
- `build_timestamp`: Timestamp when the release was built.
- `drupal_version`: Drupal core version resolved for the release.
- `php_version`: PHP version used for the release runtime or build.

## Example Format

```json
{
  "deployment_id": "20260702-001",
  "repository": "git@github.com:example/myeventlane_hold.git",
  "branch": "main",
  "commit": "0000000000000000000000000000000000000000",
  "release": "20260702T120000Z",
  "composer_lock_sha256": "0000000000000000000000000000000000000000000000000000000000000000",
  "build_timestamp": "2026-07-02T12:00:00Z",
  "drupal_version": "11.x",
  "php_version": "8.3"
}
```

The example values are placeholders. They are not generated from this repository state.

## Optional Metadata Fields

Future deployment tooling may add:

- `target_environment`.
- `validation_status`.
- `activated_at`.
- `previous_release`.
- `operator`.
- `deployment_runner_version`.
- `health_check_url`.
- `health_check_status`.

Optional fields must not contain secrets.

## Storage Expectations

Release metadata should be stored with the immutable release and copied or referenced by deployment logs.

The metadata must survive failed post-build validation long enough for diagnosis. Cleanup behaviour belongs to the future deployment repository.

## Security Requirements

Release metadata must not contain:

- Database passwords.
- Drupal hash salts.
- Waitlist token secrets.
- SSH private keys.
- API tokens.
- Environment file contents.

Secret presence may be recorded as a boolean or as a secret reference name, but secret values must never be recorded.

## Unresolved Assumptions

This repository does not confirm:

- The final metadata filename.
- The release directory layout.
- The deployment log storage location.
- The deployment ID format.
- The retention period for metadata from failed releases.
