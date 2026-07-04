# Manifests

This directory is reserved for future deployment manifests.

No real environment manifests are included in Phase 1.

Future manifests must:

- validate against `schemas/deployment-manifest.schema.json`;
- avoid secrets;
- use approved repository URLs;
- use approved branches;
- use allowlisted paths;
- define health checks;
- identify the target environment clearly.

Example-only manifests live in `examples/`.

## Unresolved Assumptions

- Real deployment manifests have not been approved.
- Environment-specific path allowlists have not been defined.
- Health check contracts have not been confirmed.
