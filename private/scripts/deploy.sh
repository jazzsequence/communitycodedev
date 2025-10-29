#!/bin/bash

export TERMINUS_SITE=${TERMINUS_SITE:-"communitycodedev"}

# Get the last commit message and store it in a variable
LAST_COMMIT_MESSAGE=$(git log -1 --pretty=%B)

# Check for currently running workflows.
running_workflows=$(terminus workflow:list "$TERMINUS_SITE.dev" --format=json | jq -r '.[] | select(.finished_at == null) | .workflow' 2> /dev/null)

if [ -z "$running_workflows" ]; then
  echo "No workflows currently running on dev."
else
  echo "Currently running workflows:"
  echo "$running_workflows"
  echo "Waiting for workflows to complete..."

  # Wait for each running workflow to complete
  terminus workflow:wait "$TERMINUS_SITE.dev" --max=300 || {
    echo "⚠️ Worflow timed out or failed. Exiting."
    exit 1
  }
fi

# if the last command ended in error, bail with a warning.
if ! terminus env:deploy "$TERMINUS_SITE.test" --note="Deploy to Test: ${LAST_COMMIT_MESSAGE}"; then
  echo "⚠️ Deploy to test failed. Skipping live deploy."
  exit 1
fi

terminus env:deploy "$TERMINUS_SITE.live" --note="Deploy to Live: ${LAST_COMMIT_MESSAGE}"
