#!/bin/bash

export TERMINUS_SITE=${TERMINUS_SITE:-"communitycodedev"}

# Get the last commit message and store it in a variable
LAST_COMMIT_MESSAGE=$(git log -1 --pretty=%B)

# Check for currently running workflows.
ran_wait=false
while true; do
  running_workflow=$(terminus workflow:list "$TERMINUS_SITE.dev" --format=json | jq -r '.[] | select(.finished_at == null) | (.workflow | gsub("\""; ""))' 2> /dev/null)

  if [ -z "$running_workflow" ]; then
    if [ "$ran_wait" = true ]; then
      echo "Workflows completed on dev."
    else
      echo "No workflows currently running on dev."
    fi
    break
  fi

  echo "Currently running workflow:"
  echo "$running_workflow"
  echo "Waiting for $running_workflow to complete..."
  echo "Use ^ + C to bail if the workflow we're waiting for is not the workflow that's running."

  # Wait for each running workflow to complete before checking again.
  if ! terminus workflow:wait "$TERMINUS_SITE.dev" "$running_workflow" --max=200; then
    echo "⚠️ Workflow timed out or failed. Exiting."
  fi

  # Reset running_workflow to ensure the workflow we're waiting for is correct.
  running_workflow=""
  ran_wait=true
done

# if the last command ended in error, bail with a warning.
if ! terminus env:deploy "$TERMINUS_SITE.test" --note="Deploy to Test: ${LAST_COMMIT_MESSAGE}"; then
  echo "⚠️ Deploy to test failed. Skipping live deploy."
  exit 1
fi

terminus env:deploy "$TERMINUS_SITE.live" --note="Deploy to Live: ${LAST_COMMIT_MESSAGE}"

# Sync ElasticPress indexes after deploy to live.
echo "Syncing ElasticPress indexes on live..."
composer ep:sync
