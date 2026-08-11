#!/usr/bin/env bash

set -eo pipefail

# the section between "= <version> =" and the next version heading
notes=$(awk -v ver="$version" '
  $0 == "= " ver " =" { capture = 1; next }
  capture && /^= [0-9]+\.[0-9]+/ { exit }
  capture { print }
' readme.txt | awk 'NF { seen = 1 } seen' | tac | awk 'NF { seen = 1 } seen' | tac) || true

if [[ -z "$notes" ]]; then
  echo "::warning::No changelog section found for $version in readme.txt"
  notes="[View changes](https://github.com/matomo-org/wp-matomo/blob/main/readme.txt)"
fi

{
  echo "notes<<CHANGELOG_EOF"
  echo "$notes"
  echo "CHANGELOG_EOF"
} >> "$GITHUB_OUTPUT"
