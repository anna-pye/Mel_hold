# Deployment Interface

## Purpose

This document defines the interface expected from a future deployment repository that deploys MyEventLane Hold.

This is documentation only. It does not create the deployment repository, change server infrastructure, alter deployment scripts, change Drupal runtime behaviour, change Commerce, change themes, or change modules.

## Boundary

The future deployment repository is expected to own deployment execution. This application repository provides source, Composer metadata, Drupal configuration, and release metadata specifications.

The future deployment repository must not require this repository to commit secrets, generated dependencies, runtime files, or server-specific production configuration.

## Required Inputs

The future deployment repository must accept or resolve these inputs before preparing a release:

- `repository`: Source repository URL or identifier.
- `branch`: Source branch to deploy.
- `release_id`: Immutable release identifier.
- `deployment_id`: Unique deployment request identifier.
- `target`: Target site and environment.
- `secrets`: Secret references or secret provider identifiers.

The future deployment repository may also require:

- Commit SHA.
- Composer lock hash.
- Public health check URL.
- Deployment operator or automation identity.
- Backup confirmation reference.

Secret values must not be passed through logs or committed files.

## Required Outputs

The future deployment repository must produce these outputs:

- `release_path`: Absolute path to the prepared immutable release.
- `release_metadata`: Metadata describing the repository, branch, commit, release, Composer lock hash, build timestamp, Drupal version, and PHP version.
- `deployment_result`: Success, failure, or aborted state with a failure reason.
- `rollback_state`: Previous release state and whether rollback remains available.

Outputs must avoid secret values.

## Expected Failure Behaviour

On validation failure before activation, the deployment repository must:

- Abort the deployment.
- Preserve the previous release.
- Never switch `current`.
- Record the failure reason.
- Preserve enough release metadata or logs for diagnosis.

On failure after activation, the deployment repository must:

- Record the active release state.
- Report whether rollback is available.
- Avoid deleting the previous known-good release.
- Require explicit rollback action rather than guessing.

## Activation Contract

The future deployment repository must activate releases only after required validation succeeds.

Activation must be explicit, auditable, and tied to release metadata. The current repository does not define the exact symlink path or activation command because those are server responsibilities.

## Rollback Contract

Rollback belongs to the future deployment repository.

Expected rollback interface:

- Select a previous known-good release for the same target.
- Confirm the release belongs to the same repository and environment.
- Switch the active release back to that release.
- Verify Drupal and HTTP health after rollback.
- Record rollback result.

Database rollback or restore is not implied by a code rollback and must be handled by the future deployment repository's backup and recovery process.

## Security Contract

The future deployment repository must:

- Validate target paths against an allowlist.
- Refuse empty, broad, root-like, or unregistered paths.
- Keep secrets outside git.
- Avoid printing secret values.
- Isolate production from staging.
- Isolate MyEventLane Hold from any other Drupal installation.
- Fail closed when validation is incomplete.

## Unresolved Assumptions

This repository does not confirm:

- The deployment repository name.
- The final target host names.
- The final target paths.
- The secret provider.
- The GitHub environment design.
- The release retention policy.
- The backup and database restore process.
