#!/usr/bin/env bash
#
# Push merged code to GitHub and deploy to https://myeventlane.com.au
#
# Prerequisites:
#   - SSH key access to the production server (often root@myeventlane.com.au)
#   - Deploy commands run as MEL_DEPLOY_RUN_AS (default mel) via sudo when SSH user is root
#   - You have merged your branch into MEL_DEPLOY_BRANCH locally
#
# Deploy methods (MEL_DEPLOY_METHOD in deploy/deploy.env):
#   rsync — cPanel / shared hosting: /home/mel is NOT a git repo (rsync from your Mac)
#   git   — VPS: server has a git clone at MEL_DEPLOY_PATH (disabled by default; see below)
#
# Future Git deployment (comments only — do not enable without a deliberate migration):
#   1. Create a separate git clone at MEL_DEPLOY_PATH on the VPS (holding site only).
#   2. Never point MEL_DEPLOY_PATH at /home/mel/staging or /home/mel/staging-repo.
#   3. Set MEL_DEPLOY_METHOD=git in deploy/deploy.env after the server clone is verified.
#   4. Run one manual deploy and confirm post-deploy-verify passes before relying on CI.
#   Default remains rsync for cPanel / non-git production layout.
#
# Setup (once):
#   cp deploy/deploy.env.example deploy/deploy.env
#
# Usage:
#   bash deploy/push-and-deploy.sh
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
# shellcheck source=deploy-common.sh
source "${SCRIPT_DIR}/deploy-common.sh"

if [[ -f "${SCRIPT_DIR}/deploy.env" ]]; then
  set -a
  # shellcheck source=/dev/null
  . "${SCRIPT_DIR}/deploy.env"
  set +a
fi

BRANCH="${MEL_DEPLOY_BRANCH:-main}"
REMOTE="${MEL_GIT_REMOTE:-origin}"
SSH_USER="${MEL_SSH_USER:-${MEL_DEPLOY_USER:-root}}"
RUN_AS="${MEL_DEPLOY_RUN_AS:-mel}"
DEPLOY_PATH="${MEL_DEPLOY_PATH:-/home/mel}"
DEPLOY_METHOD="${MEL_DEPLOY_METHOD:-rsync}"
SKIP_PUSH="${MEL_DEPLOY_SKIP_PUSH:-0}"
SKIP_VERIFY="${MEL_DEPLOY_SKIP_VERIFY:-0}"

if [[ -z "${MEL_DEPLOY_HOST:-}" ]]; then
  echo "ERROR: Set MEL_DEPLOY_HOST in deploy/deploy.env (copy from deploy/deploy.env.example)." >&2
  exit 1
fi

mel_assert_not_staging_path "${DEPLOY_PATH}"

SSH_TARGET="${SSH_USER}@${MEL_DEPLOY_HOST}"

cd "${ROOT}"

if [[ ! -f "${ROOT}/composer.json" ]]; then
  echo "ERROR: Run from the project root (composer.json not found)." >&2
  exit 1
fi

echo "==> [Fetching] ${REMOTE}"
git fetch "${REMOTE}"

echo "==> [Fetching] checkout ${BRANCH}"
git checkout "${BRANCH}"

echo "==> [Fetching] pull ${REMOTE}/${BRANCH}"
git pull "${REMOTE}" "${BRANCH}"

if [[ "${SKIP_PUSH}" != "1" ]]; then
  echo "==> [Fetching] push ${BRANCH} to ${REMOTE}"
  git push "${REMOTE}" "${BRANCH}"
else
  echo "==> [Fetching] skipping git push (MEL_DEPLOY_SKIP_PUSH=1)"
fi

if [[ "${SSH_USER}" == "${RUN_AS}" ]]; then
  RUNNER=(bash -s)
else
  echo "==> [Deploying] SSH as ${SSH_USER}, remote commands as ${RUN_AS}"
  RUNNER=(sudo -u "${RUN_AS}" bash -s)
fi

if [[ "${DEPLOY_METHOD}" == "rsync" ]]; then
  echo "==> [Deploying] rsync ${BRANCH} to ${SSH_TARGET}:${DEPLOY_PATH} (rsync mode)"
  rsync -avz --delete --no-owner --no-group \
    --exclude-from="${SCRIPT_DIR}/rsync-exclude.txt" \
    -e "ssh -o BatchMode=yes" \
    "${ROOT}/" "${SSH_TARGET}:${DEPLOY_PATH}/"
  echo "==> [Deploying] fixing ownership for ${RUN_AS}"
  ssh -o BatchMode=yes "${SSH_TARGET}" "chown -R ${RUN_AS}:${RUN_AS} '${DEPLOY_PATH}'"
  echo "==> [Deploying] running post-deploy on server"
  ssh -o BatchMode=yes "${SSH_TARGET}" "${RUNNER[@]}" -- "${DEPLOY_PATH}" "${SKIP_VERIFY}" <<'REMOTE_SCRIPT'
set -euo pipefail
DEPLOY_PATH="$1"
SKIP_VERIFY="$2"
export MEL_DEPLOY_SKIP_VERIFY="${SKIP_VERIFY}"
cd "${DEPLOY_PATH}"
bash deploy/cpanel-post-deploy.sh "${DEPLOY_PATH}"
REMOTE_SCRIPT
elif [[ "${DEPLOY_METHOD}" == "git" ]]; then
  echo "==> [Deploying] git pull on ${SSH_TARGET}:${DEPLOY_PATH} (git mode)"
  ssh -o BatchMode=yes "${SSH_TARGET}" "${RUNNER[@]}" -- "${DEPLOY_PATH}" "${BRANCH}" "${SKIP_VERIFY}" <<'REMOTE_SCRIPT'
set -euo pipefail
DEPLOY_PATH="$1"
BRANCH="$2"
SKIP_VERIFY="$3"
export MEL_DEPLOY_SKIP_VERIFY="${SKIP_VERIFY}"
cd "${DEPLOY_PATH}"
if [[ ! -d .git ]]; then
  echo "ERROR: ${DEPLOY_PATH} is not a git repository. Use MEL_DEPLOY_METHOD=rsync in deploy/deploy.env." >&2
  exit 1
fi
git fetch origin
git checkout "${BRANCH}"
git pull origin "${BRANCH}"
bash deploy/site-deploy.sh "${DEPLOY_PATH}"
REMOTE_SCRIPT
else
  echo "ERROR: MEL_DEPLOY_METHOD must be 'rsync' or 'git' (got '${DEPLOY_METHOD}')." >&2
  exit 1
fi

echo ""
echo "==> [Success] Done. https://myeventlane.com.au/"
