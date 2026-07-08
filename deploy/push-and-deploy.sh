#!/usr/bin/env bash
#
# RETIRED — DO NOT USE.
#
# This script previously ran `rsync -avz --delete` from the repository root
# into the shared home directory /home/mel. On 2026-07-07 that deleted the
# Staging site (/home/mel/staging) and overwrote Hold in place. See
# docs/audits/deployment-architecture-audit.md for the full postmortem.
#
# It has been replaced by three isolated, hardcoded, git-based scripts that
# can only ever touch their own application directory:
#
#   deploy/deploy-hold.sh
#   deploy/deploy-staging.sh
#   deploy/deploy-production.sh
#
# This stub refuses to run so the unsafe behaviour cannot recur.
#
set -euo pipefail

cat >&2 <<'MSG'
ERROR: deploy/push-and-deploy.sh is RETIRED and will not run.

It used `rsync --delete` into a shared parent directory and destroyed a
sibling site. Use the per-application script instead:

  bash deploy/deploy-hold.sh        [--dry-run] [--yes]
  bash deploy/deploy-staging.sh     [--dry-run] [--yes]
  bash deploy/deploy-production.sh  [--dry-run]

See docs/deployment/deployment-flow.md and
docs/audits/deployment-architecture-audit.md.
MSG
exit 1
