# Deployment Architecture

`mel-deployment` documents a release-based deployment architecture for independent Drupal application repositories. Phase 2A adds a local validation framework only. It does not implement deployment behaviour.

## Core Model

Deployments create a new immutable release and only switch live traffic after validation succeeds.

```text
Application Repository
        |
        v
Validated Manifest
        |
        v
New Immutable Release
        |
        v
Validation and Health Checks
        |
        v
current -> successful release
```

If validation fails, the active `current` link remains unchanged.

```text
Validation Failure
        |
        v
Abort Deployment
        |
        v
current remains unchanged
```

## Core Framework

The Phase 2A framework provides reusable local validation components for future deployment stages. It validates manifests and schemas only. It does not connect to servers, resolve target filesystems, execute Composer or Drush, run SSH or rsync, create releases, switch `current`, or perform rollback.

```text
CLI
 |
 v
Library
 |
 v
Schema
 |
 v
Manifest
 |
 v
Validation
 |
 v
PASS / FAIL
```

The command wrappers in `deploy/bin/` are intentionally thin:

- `mel-validate` validates one or more deployment manifests.
- `mel-info` reports local framework metadata.
- `mel-schema` validates or prints the local schema path.

The reusable libraries in `deploy/lib/` each have one responsibility:

- `common.sh` provides project metadata and shared utility checks.
- `errors.sh` defines common exit codes, categories, and structured error output.
- `output.sh` formats text and JSON status output.
- `files.sh` performs local file readability and repository path checks.
- `schema.sh` validates manifest structure against the local JSON schema.
- `manifest.sh` coordinates manifest validation and duplicate deployment ID checks.

All framework validation is local and fail-closed. Invalid input returns a non-zero status with structured error output. Machine-readable JSON output is available for CLI consumers with `--format json`.

## Release-Based Deployments

Each deployment creates a distinct release directory identified by a deployment ID or release identifier. Future deployment tooling must never build directly inside the live directory.

The expected release structure is conceptual only:

```text
release_root/
    releases/
        release-a/
        release-b/
    shared/
    current -> releases/release-b
```

Concrete server paths must come from validated deployment manifests or environment-specific configuration. They are not defined in this repository.

## Immutable Releases

Once a release has passed validation, it must not be modified in place. A new deployment must create a new release. This keeps rollback simple and auditable.

## Current Symlink

The `current` link represents the active release. Future tooling must update this link only after:

- the manifest is valid;
- the repository state is clean and deployable;
- dependencies are installed in the release;
- shared runtime links are correct;
- database and cache operations have completed successfully where required;
- health checks pass.

## Shared Runtime Directories

Runtime directories that must survive release replacement belong under a shared root. Examples may include uploaded files, private files, and other writable runtime assets when confirmed by the application contract.

This repository does not define server-specific shared paths.

## Rollback Strategy

Rollback means repointing `current` to a previous known-good immutable release. Future tooling must fail closed if:

- no previous release is available;
- the requested rollback target is not allowlisted;
- the rollback target fails health checks;
- the deployment lock cannot be acquired.

## Deployment Lifecycle

```text
Repository
    |
    v
Manifest validation
    |
    v
Repository validation
    |
    v
Create release
    |
    v
Install dependencies
    |
    v
Link shared runtime directories
    |
    v
Run application update steps
    |
    v
Run health checks
    |
    v
Switch current
    |
    v
Record success
```

## Validation Lifecycle

Validation must happen before any live switch.

```text
Validate manifest
    |
    v
Validate repository state
    |
    v
Validate target paths
    |
    v
Validate release build
    |
    v
Validate application health
```

Any failed validation must abort the deployment and leave `current` unchanged.

## Unresolved Assumptions

- Concrete server paths are unknown.
- Health check endpoints are unknown.
- The exact shared runtime directory list must be confirmed per application.
- Database update behaviour must be confirmed per application before implementation.
