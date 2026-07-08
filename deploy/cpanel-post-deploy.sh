#!/usr/bin/env bash
#
# RETIRED — DO NOT USE.
#
# This was the remote post-deploy step for the retired push-and-deploy.sh
# rsync flow. It accepted an arbitrary path argument (default /home/mel) and
# ran composer/drush there with no isolation. Its behaviour is now built into
# the per-application scripts, which hardcode their own path:
#
#   deploy/deploy-hold.sh / deploy-staging.sh / deploy-production.sh
#
# See docs/audits/deployment-architecture-audit.md.
#
set -euo pipefail

echo "ERROR: deploy/cpanel-post-deploy.sh is RETIRED. Use deploy/deploy-<app>.sh." >&2
exit 1
