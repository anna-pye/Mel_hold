# Deployment Model

This repository documents the intended deployment flow. It does not implement the flow.

## Successful Deployment Flow

```text
Repository
    |
    v
Validation
    |
    v
Release
    |
    v
Composer
    |
    v
Shared links
    |
    v
Database update
    |
    v
Cache rebuild
    |
    v
Health check
    |
    v
Switch current
    |
    v
Success
```

## Validation Failure Flow

```text
Validation fails
    |
    v
Abort
    |
    v
Leave current unchanged
```

## Required Behaviour

Future deployment tooling must:

- validate the deployment manifest before touching release state;
- validate the application repository before building a release;
- create a new release outside the live `current` directory;
- run Composer only within the candidate release;
- link shared runtime directories only from validated allowlisted paths;
- perform database updates only after the release is prepared;
- rebuild caches before health checks where required;
- switch `current` only after health checks pass.

## Failed Behaviour

Future deployment tooling must not:

- continue after validation failure;
- write into the active release;
- switch `current` before health checks pass;
- retry a failed deployment without confirming or restoring a healthy active release;
- use paths or secrets that are not explicitly provided by approved configuration.

## Unresolved Assumptions

- Exact Composer command policy is not defined.
- Exact Drupal database update command policy is not defined.
- Exact cache rebuild command policy is not defined.
- Health check endpoints and expected responses are not defined.
