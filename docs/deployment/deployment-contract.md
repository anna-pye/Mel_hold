# Deployment Contract

## Purpose

This document defines the application repository contract required for future deterministic, release-based deployments of MyEventLane Hold.

This is documentation only. It does not create a deployment repository, change server infrastructure, alter deployment scripts, change Drupal runtime behaviour, change Commerce, change themes, or change modules.

## Repository Responsibilities

This repository owns:

- Drupal source.
- Composer metadata, including `composer.json` and `composer.lock`.
- Drupal configuration owned by the application.
- Deployment metadata specifications used by future release tooling.

This repository does not own:

- Production server infrastructure.
- Release management.
- Rollback execution.
- Infrastructure provisioning.
- Secrets.
- GitHub environments.
- SSH targets.
- Runtime server paths.
- Server-level backup policy.

## Required Deployment Inputs

A future deployment repository or deployment runner must provide these inputs before building or activating a release:

- Repository URL or repository identifier.
- Branch name.
- Commit SHA.
- Composer lock hash.
- Deployment ID.
- Release timestamp.
- Target environment.

The deployment runner must not infer these values from mutable server state. It must record the resolved values before release validation begins.

## Source Contract

This repository must provide:

- `composer.json`.
- `composer.lock`.
- Drupal web root at `web/`.
- Drupal entry point at `web/index.php`.
- Drupal Apache rules at `web/.htaccess`.
- Drupal site directory at `web/sites`.

The repository must not provide committed production secrets, generated runtime files, user-uploaded files, or built `vendor/` dependencies.

## Configuration Contract

Drupal configuration remains application-owned. Deployment tooling may validate and import configuration only as part of an agreed Drupal deployment lifecycle.

Deployment tooling must not:

- Edit Drupal configuration files in this repository.
- Create fields, routes, modules, themes, or Commerce configuration.
- Apply ad hoc runtime configuration changes outside the documented deployment process.

## Secret Contract

Secrets are external deployment inputs and must remain outside git.

Future deployment tooling may require secret names, secret presence checks, or environment file locations, but it must not require secret values to be committed or printed.

## Release Contract

The future deployment repository must own release creation, activation, rollback, retention, cleanup, and server path validation.

This repository may document the metadata and validation expectations for those releases, but it must not implement release switching or rollback in this phase.

## Unresolved Assumptions

The following assumptions are not confirmed by this repository and must be resolved by the future deployment repository:

- The production server release directory layout.
- The deploy user and SSH target.
- The final production environment file location.
- The backup checkpoint process before database updates.
- The rollback retention policy.
- The health check URL for each target environment.
