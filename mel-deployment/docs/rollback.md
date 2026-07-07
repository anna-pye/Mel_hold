# Rollback

Rollback restores service by switching `current` to a previous known-good immutable release. This document describes the intended model only.

## Immutable Releases

Each release must be created as a separate immutable directory. A release that has been activated must not be edited in place.

## Previous Release Retention

Future deployment tooling must retain enough previous releases to support rollback. The exact retention count is not defined in this repository and must be confirmed before implementation.

Release cleanup must never remove:

- the active release;
- the immediate previous known-good release;
- any release currently targeted by a deployment or rollback lock.

## Rollback Process

The intended rollback process is:

```text
Acquire deployment lock
        |
        v
Identify previous known-good release
        |
        v
Validate rollback target
        |
        v
Switch current
        |
        v
Run health checks
        |
        v
Record rollback result
        |
        v
Release deployment lock
```

Rollback must fail closed if the target release cannot be validated.

## Failed Deployment Recovery

If deployment fails before `current` is switched, the active release must remain unchanged.

If deployment fails after any operation that could affect runtime state, future tooling must confirm the active release is healthy or roll back before another deployment attempt.

## Deployment Lock

Deployment and rollback must be protected by a lock so that two operations cannot modify release state at the same time.

Future tooling must fail closed if it cannot acquire the lock safely.

## Release Cleanup

Release cleanup must happen only after deployment or rollback has completed successfully. Cleanup must be conservative and must preserve rollback capability.

## Unresolved Assumptions

- Release retention count is not defined.
- Lock implementation is not defined.
- Rollback health checks are not defined.
- Operational approval rules for production rollback are not defined.
