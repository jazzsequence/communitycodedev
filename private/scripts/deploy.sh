#!/bin/bash

export TERMINUS_SITE=${TERMINUS_SITE:-"communitycodedev"}

echo "Waiting for sync_code to complete..."
terminus workflow:wait "$TERMINUS_SITE.dev" "Sync code on dev"
echo "Waiting for build_slim_image to complete..."
terminus workflow:wait "$TERMINUS_SITE.dev" "Build a slim image for test/live environment"

# Get the last commit message and store it in a variable
LAST_COMMIT_MESSAGE=$(git log -1 --pretty=%B)

# if the last command ended in error, bail with a warning.
if ! terminus env:deploy "$TERMINUS_SITE".test --note="Deploy to Test: ${LAST_COMMIT_MESSAGE}"; then
  echo "⚠️ Deploy to test failed. Skipping live deploy."
  exit 1
fi
terminus env:deploy "$TERMINUS_SITE".live --note="Deploy to Live: ${LAST_COMMIT_MESSAGE}"
