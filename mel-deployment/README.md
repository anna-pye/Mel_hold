# mel-deployment

`mel-deployment` is the deployment infrastructure repository for MyEventLane Drupal applications.

This repository does not contain Drupal.

This repository does not contain application code.

Its purpose is to define the deployment architecture, repository contract, manifest schema, security model, rollback model, and future operating principles for release-based deployments.

## Responsibilities

- Define the deployment model used by independent Drupal application repositories.
- Define the contract application repositories must satisfy before they can be deployed.
- Define validated deployment manifest structure.
- Document security, rollback, validation, and release principles.
- Provide non-secret example manifests for future implementation.

## Non-Responsibilities

- No Drupal code.
- No application code.
- No Composer project.
- No server provisioning.
- No Apache, cPanel, DNS, backup, monitoring, or operating system automation.
- No executable deployment scripts.
- No GitHub Actions that perform deployments.
- No SSH, rsync, Composer, or Drush execution.

## Supported Deployment Model

The supported model is release-based deployment:

1. Validate the deployment manifest and application repository.
2. Build a new immutable release outside the live directory.
3. Link shared runtime directories.
4. Run required validation and health checks.
5. Switch `current` only after validation passes.
6. Leave the existing `current` release unchanged if validation fails.

## Supported Applications

This repository is intended to orchestrate deployments for independent Drupal application repositories, including:

- `Mel_hold`
- `myeventlane-platform`

Application repositories remain responsible for their own Drupal code, Composer configuration, configuration export, tests, and release readiness.

## Release Philosophy

Releases must be immutable once created. A deployment must never write directly into the live `current` directory. Future deployment behaviour must consume validated manifests, fail closed, and avoid hardcoded infrastructure values.

## Rollback Philosophy

Rollback must switch `current` back to a known previous immutable release. Failed deployments must not modify the active release. A failed release should be diagnosed, removed or retained according to documented retention policy, and replaced by a new deployment attempt only after rollback or confirmation that `current` remains healthy.

## Unresolved Assumptions

- The final software licence has not been confirmed.
- Concrete server paths are intentionally undefined.
- Deployment users, permissions, health check URLs, and secret storage are intentionally undefined.
- Release retention counts are intentionally undefined.
