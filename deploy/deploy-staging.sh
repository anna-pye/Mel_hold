#!/usr/bin/env bash
#
# deploy-staging.sh — DOCUMENTATION-ONLY EXAMPLE. Not a working deploy script.
#
# Staging / main MyEventLane is NOT deployed from Mel_hold. It is owned by a
# SEPARATE repository:
#
#     https://github.com/anna-pye/mel-deployment
#
# This file exists only so the command is discoverable and to record the
# contract the REAL staging script (in mel-deployment) must satisfy. It performs
# no deployment, sources no library, and refuses to run. See
# docs/deployment/repository-ownership.md.
#
# Operator command for staging (provided by the mel-deployment repository, run
# on the server):
#
#     cd /home/mel/sites/myeventlane_staging
#     ./deploy/deploy-staging.sh
#
# The staging script — owned and implemented in mel-deployment — must, exactly
# like Hold's, hardcode its own target and refuse to run unless ALL match:
#
#     deployment path      = /home/mel/sites/myeventlane_staging
#     public web root      = /home/mel/sites/myeventlane_staging/web
#     current directory    = /home/mel/sites/myeventlane_staging
#     .mel-application     = myeventlane_staging
#     source repository    = github.com/anna-pye/mel-deployment
#
# No variable may let staging target Hold, and no Mel_hold script may target
# staging (this repo's guard forbids the staging path outright).
#
set -euo pipefail

cat >&2 <<'MSG'
ERROR: deploy/deploy-staging.sh in Mel_hold is a DOCUMENTATION-ONLY example and
will not run. Mel_hold deploys ONLY the Hold site.

Staging / main MyEventLane is deployed from its own repository:
  https://github.com/anna-pye/mel-deployment

Run staging's deploy script from that repository's checkout, not from here.
See docs/deployment/repository-ownership.md.
MSG
exit 1
