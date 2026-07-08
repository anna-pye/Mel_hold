#!/usr/bin/env bash
#
# RETIRED — DO NOT USE.
#
# This accepted an arbitrary path argument and, if drush failed to bootstrap,
# could run `drush site:install` — reinstalling Drupal over an existing site.
# That behaviour is unsafe and has been removed. Deployment is now handled by
# the isolated, hardcoded, git-based per-application scripts:
#
#   deploy/deploy-hold.sh / deploy-staging.sh / deploy-production.sh
#
# See docs/audits/deployment-architecture-audit.md.
#
set -euo pipefail

echo "ERROR: deploy/site-deploy.sh is RETIRED. Use deploy/deploy-<app>.sh." >&2
exit 1
