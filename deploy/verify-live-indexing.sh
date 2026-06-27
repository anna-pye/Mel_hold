#!/usr/bin/env bash
#
# Read robots meta and X-Robots-Tag from the live holding page (verification only).
# Usage: bash deploy/verify-live-indexing.sh [URL]
#
# Expect index,follow only after MEL_ENVIRONMENT=production is set on the host.
#

set -euo pipefail

URL="${1:-https://myeventlane.com.au/}"

echo "Fetching: ${URL}"
echo ""

HEADERS="$(curl -sS -D - -o /dev/null "${URL}" 2>&1 || true)"
BODY="$(curl -sS "${URL}" 2>&1 || true)"

echo "--- X-Robots-Tag header ---"
echo "${HEADERS}" | grep -i '^x-robots-tag:' || echo "(none)"
echo ""

echo "--- meta name=\"robots\" ---"
echo "${BODY}" | grep -ioE '<meta[^>]+name=["'\'']robots["'\''][^>]*>' | head -5 || echo "(none found)"
echo ""

echo "Note: index,follow appears only after MEL_ENVIRONMENT=production is set on the host."
