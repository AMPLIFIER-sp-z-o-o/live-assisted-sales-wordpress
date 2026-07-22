#!/bin/sh
# Builds the installable WordPress plugin ZIP (dist/amper-live-assisted-sales.zip).
# Used locally and by the Jenkins pipeline. Requires `zip`.
set -eu
cd "$(dirname "$0")"
mkdir -p dist
rm -f dist/amper-live-assisted-sales.zip
(cd plugin && zip -rq ../dist/amper-live-assisted-sales.zip amper-live-assisted-sales)
echo "Built: dist/amper-live-assisted-sales.zip ($(wc -c < dist/amper-live-assisted-sales.zip) bytes)"
