#!/bin/bash

export TERMINUS_SITE=${TERMINUS_SITE:-"communitycodedev"}

STEPS_TO_WAIT=(
  "Sync code on dev"
  "Build a slim image for test/live environment"
)

# Wait for the specified workflows to complete
for step in "${STEPS_TO_WAIT[@]}"; do
  echo "Waiting for $step to complete..."
  if ! terminus workflow:wait "$TERMINUS_SITE.dev" "$step"; then
    echo "⚠️ Workflow '$step' failed. Exiting."
    exit 1
  fi
done

# Get the last commit message and store it in a variable
LAST_COMMIT_MESSAGE=$(git log -1 --pretty=%B)

# if the last command ended in error, bail with a warning.
if ! terminus env:deploy "$TERMINUS_SITE".test --note="Deploy to Test: ${LAST_COMMIT_MESSAGE}"; then
  echo "⚠️ Deploy to test failed. Skipping live deploy."
  exit 1
fi
terminus env:deploy "$TERMINUS_SITE".live --note="Deploy to Live: ${LAST_COMMIT_MESSAGE}"
