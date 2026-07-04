# Deploy Directory

This directory is reserved for future deployment implementation.

There are no executable deployment scripts in Phase 1.

Future deployment scripts must:

- be idempotent;
- fail closed;
- never deploy into live directories;
- never modify `current` until validation passes;
- never deploy from dirty repositories;
- never hardcode paths;
- consume validated deployment manifests;
- support dry-run mode;
- support rollback.

Future deployment scripts must not:

- contain secrets;
- assume server paths;
- perform SSH or rsync operations without a verified design;
- run Composer or Drush without a verified deployment contract;
- bypass health checks;
- hide failures.

## Unresolved Assumptions

- No deployment command interface has been approved.
- No dry-run output contract has been approved.
- No rollback command interface has been approved.
