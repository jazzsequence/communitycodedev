#!/bin/bash

export TERMINUS_SITE=${TERMINUS_SITE:-"communitycodedev"}

STEPS_TO_WAIT=(
  "Sync code on dev"
  "Build a slim image for test/live environment"
)

# Get the last commit message and store it in a variable
LAST_COMMIT_MESSAGE=$(git log -1 --pretty=%B)

# Only wait for workflows if they are actually running
for step in "${STEPS_TO_WAIT[@]}"; do
  # Check if this workflow is currently running
  if terminus workflow:list "$TERMINUS_SITE.dev" --format=json | jq -r '.[].workflow' | grep -q "^$step$" && \
     terminus workflow:list "$TERMINUS_SITE.dev" --format=json | jq -r '.[] | select(.workflow == "'"$step"'") | .status' | grep -q "running"; then
    echo "Waiting for $step to complete..."
    if ! terminus workflow:wait "$TERMINUS_SITE.dev" "$step" --max=220; then
      echo "⚠️ Workflow '$step' failed. Exiting."
      exit 1
    fi
  else
    echo "Skipping wait for '$step' - workflow not currently running."
  fi
done

# if the last command ended in error, bail with a warning.
if ! terminus env:deploy "$TERMINUS_SITE.test" --note="Deploy to Test: ${LAST_COMMIT_MESSAGE}"; then
  echo "⚠️ Deploy to test failed. Skipping live deploy."
  exit 1
fi

terminus env:deploy "$TERMINUS_SITE.live" --note="Deploy to Live: ${LAST_COMMIT_MESSAGE}"
