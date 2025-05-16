#!/bin/bash

export TERMINUS_SITE=${TERMINUS_SITE:-"communitycodedev"}

# Get the last commit message and store it in a variable
LAST_COMMIT_MESSAGE=$(git log -1 --pretty=%B)

# if the last command ended in error, bail with a warning.
if ! terminus env:deploy "$TERMINUS_SITE".test --note="Deploy to Test: ${LAST_COMMIT_MESSAGE}"; then
  echo "⚠️ Deploy to test failed. Skipping live deploy."
  exit 1
fi
terminus env:deploy "$TERMINUS_SITE".live --note="Deploy to Live: ${LAST_COMMIT_MESSAGE}"
