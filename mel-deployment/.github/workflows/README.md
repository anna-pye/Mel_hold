# GitHub Workflows

This directory is reserved for future GitHub Actions documentation and workflow files.

There are no GitHub Actions that execute deployments in Phase 1.

Future workflows must:

- validate manifests before any deployment action;
- fail closed on missing or invalid inputs;
- avoid storing secrets in workflow files;
- avoid hardcoded server paths;
- require explicit environment approval for production where appropriate;
- avoid deployment logic that cannot be verified.

## Unresolved Assumptions

- GitHub environment names are not defined.
- Required approvals are not defined.
- CI validation commands are not defined.
- Deployment execution workflows are intentionally not implemented.
