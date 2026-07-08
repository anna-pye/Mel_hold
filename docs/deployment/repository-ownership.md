# Repository Ownership

MyEventLane deployment is split across **two independent repositories**. This is
a hard boundary: each repository deploys only its own application, and neither
can touch the other's.

## Ownership map

| Application | Owned by repository | Deploy path | Web root | Identity marker |
|---|---|---|---|---|
| **Hold** (pre-launch waitlist site) | **Mel_hold** — github.com/anna-pye/Mel_hold (this repo) | `/home/mel/sites/myeventlane_hold` | `/home/mel/sites/myeventlane_hold/web` | `.mel-application` = `myeventlane_hold` |
| **Staging / main MyEventLane** | **mel-deployment** — github.com/anna-pye/mel-deployment | (owned & defined there) | (owned & defined there) | (owned & defined there) |

## Rules

- **Mel_hold deploys only Hold.** Its deploy library allowlists exactly one path
  (`/home/mel/sites/myeventlane_hold`) and explicitly **forbids** the staging and
  production paths. There is no `deploy-staging.sh` / `deploy-production.sh` in
  this repository — attempting to target a staging/production path fails closed
  with a message pointing to `mel-deployment`.
- **mel-deployment deploys staging / main MyEventLane.** Its deploy scripts,
  paths, identity markers, and documentation live in that repository.
- **No script in Mel_hold may deploy or modify the staging application.**
- **No script in mel-deployment may deploy or modify the Hold application.**
- Staging is **not** a clone of Mel_hold. Do not assume Hold and staging share a
  repository, branch, or codebase.

## The `mel-deployment/` directory in this repo

This repository contains a `mel-deployment/` directory that is a **vendored copy
of the deployment framework's specification/docs** (manifest schema, security
model, rollback model). It is documentation only — it contains no executable
deployment scripts and does not deploy anything. The **live** staging deployment
tooling lives in the separate `github.com/anna-pye/mel-deployment` repository,
not in this directory.

## Cross-repository changes

If a change is needed in both repositories:

1. State clearly **which repository owns which change**.
2. Make the Mel_hold change here.
3. **Stop and request confirmation** before making any change in `mel-deployment`
   — it is a separate repository with its own review and release process. Do not
   implement cross-repo changes speculatively.

## Operator commands

**Hold** (this repository):

```
cd /home/mel/sites/myeventlane_hold
./deploy/deploy-hold.sh
```

**Staging** (owned by mel-deployment — run its script, not anything here):

```
cd /home/mel/sites/myeventlane_staging
./deploy/deploy-staging.sh      # the REAL script comes from github.com/anna-pye/mel-deployment
```

Note: `Mel_hold` contains a `deploy/deploy-staging.sh` that is a
**documentation-only stub** — it deploys nothing and refuses to run, pointing
here. The working staging script with the same operator command lives in the
`mel-deployment` repository's checkout at that path.

Both scripts follow the same contract: refuse to run unless the current
directory, deployment path, web root, and `.mel-application` marker all match
their own application. The Hold guarantees are enforced in this repo; the staging
guarantees are the responsibility of `mel-deployment`.
