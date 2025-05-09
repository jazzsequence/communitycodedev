#!/bin/bash

export TERMINUS_SITE=${TERMINUS_SITE:-"communitycodedev"}

# if the last command ended in error, bail with a warning.
if ! terminus env:deploy "$TERMINUS_SITE".test; then
  echo "⚠️ Deploy to test failed. Skipping live deploy."
  exit 1
fi
terminus env:deploy "$TERMINUS_SITE".live
