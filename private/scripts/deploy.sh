#!/bin/bash

export TERMINUS_SITE=${TERMINUS_SITE:-"communitycodedev"}

# Maybe wait for previous actions.
STEPS_TO_WAIT=(
  "Sync code on dev"
  "Build a slim image for test/live environment"
  "Deploy code to test"
  "Deploy code to live"
)

# Get the last commit message and store it in a variable
LAST_COMMIT_MESSAGE=$(git log -1 --pretty=%B)

# Get the currently running workflow
current_workflow_output=$(terminus workflow:wait "$TERMINUS_SITE.dev" --max=1 2>&1 || true)
current_workflow=""

if echo "$current_workflow_output" | grep -q "Workflow .* running"; then
  # Parse format: [notice] Workflow 'Sync code on dev' running.
  current_workflow=$(echo "$current_workflow_output" | sed -n "s/.*Workflow '\([^']*\)' running.*/\1/p")
  echo "Currently running workflow: $current_workflow"
else
  # If we see "Current workflow is X; waiting for X", it means X has completed
  # If we see "Current workflow is X; waiting for Y", it means X is running but we're waiting for Y
  echo "No workflows currently running."
fi

# Only wait for workflows if they match the currently running workflow
for step in "${STEPS_TO_WAIT[@]}"; do
  if [ "$current_workflow" = "$step" ]; then
    echo "Waiting for $step to complete..."
    if ! terminus workflow:wait "$TERMINUS_SITE.dev" "$step" --max=220; then
      echo "⚠️ Workflow '$step' failed. Exiting."
      exit 1
    fi
  else
    echo "Skipping wait for '$step' - not currently running."
  fi
done

# if the last command ended in error, bail with a warning.
if ! terminus env:deploy "$TERMINUS_SITE.test" --note="Deploy to Test: ${LAST_COMMIT_MESSAGE}"; then
  echo "⚠️ Deploy to test failed. Skipping live deploy."
  exit 1
fi

terminus env:deploy "$TERMINUS_SITE.live" --note="Deploy to Live: ${LAST_COMMIT_MESSAGE}"
