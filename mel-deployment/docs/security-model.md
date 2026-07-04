# Security Model

This repository documents a fail-closed deployment model. It does not implement security controls yet.

## Fail Closed

Future deployment tooling must stop when required information is missing, invalid, ambiguous, or unverifiable. A failed validation must leave the active release unchanged.

## Allowlisted Paths

Deployment paths must be allowlisted before use. Tooling must not infer, guess, or construct server paths from application names.

Required path values must come from validated deployment manifests or approved environment configuration.

## Immutable Releases

Releases must be immutable after creation. Tooling must create a new release for each deployment and must not patch an active release in place.

## No Live Deployment

Future deployment tooling must never deploy into the live `current` directory. Build, dependency installation, shared links, database updates, cache rebuilds, and health checks must happen against a candidate release before `current` is switched.

## No Dirty Repository Deployment

Deployment from a dirty repository must be rejected. The deployed source must be traceable to a clean repository state and an explicit branch or revision.

## No Deployment Without Validation

Deployment must not continue unless manifest validation, repository validation, path validation, release validation, and application validation all pass.

## No Deployment Without Health Checks

Every deployment manifest must define health checks. Future tooling must refuse to switch `current` if health checks are absent, incomplete, or failing.

## Rollback Before Retry

If a deployment fails after any release-side mutation, the system must confirm that `current` is healthy or roll back to the previous known-good release before retrying.

## Least Privilege

Deployment credentials must have the minimum permissions required for the target environment. Application secrets, server administration credentials, and source control credentials must be isolated from each other.

## Secret Isolation

Secrets must not be stored in this repository, manifests, examples, logs, or generated output. Secret resolution belongs to a future approved secret-management design.

## Unresolved Assumptions

- Secret storage and retrieval mechanisms are not defined.
- Deployment user permissions are not defined.
- Approved allowlisted server paths are not defined.
- Health check authentication requirements are not defined.
