# Repository Contract

Application repositories must provide enough information for future deployment tooling to build and validate a release without guessing infrastructure details.

This contract defines required inputs only. It does not define concrete server-specific values.

## Required Inputs

- `repository`: source repository URL.
- `branch`: branch to deploy.
- `deployment_id`: stable identifier for the deployment target.
- `environment`: target environment, such as `staging` or `production`.
- `composer_root`: path to the Composer project root relative to the repository checkout or defined release context.
- `web_root`: path to the public web root relative to the release.
- `release_root`: root directory containing immutable releases.
- `shared_root`: root directory containing runtime data shared between releases.

## Application Repository Responsibilities

Each application repository remains responsible for:

- valid Drupal application code;
- valid Composer configuration;
- committed configuration export where used;
- application-level tests and quality checks;
- documentation for required runtime directories;
- documentation for database update requirements;
- documentation for health check expectations.

## Deployment Repository Responsibilities

This repository may later provide tooling that consumes the contract, validates manifests, and orchestrates release lifecycle steps. It must not invent missing application behaviour.

## Path Rules

Future deployment tooling must treat all paths as untrusted until validated.

- Paths must be provided by validated manifests or approved environment configuration.
- Paths must be allowlisted before use.
- Paths must not be hardcoded in deployment scripts.
- Paths must not be guessed from application names.

## Fail-Closed Behaviour

Deployment must stop if any required input is missing, invalid, ambiguous, or inconsistent with the allowlisted target environment.

## Unresolved Assumptions

- The authoritative repository URLs for `Mel_hold` and `myeventlane-platform` are not defined here.
- Approved deployment environments are not finalised.
- Approved server path allowlists are not defined here.
- Health check paths and expected responses are application-specific and not yet confirmed.
