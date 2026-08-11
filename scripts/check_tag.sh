#!/usr/bin/env bash

set -eo pipefail

existing_version=$(grep -oP " * Version: \K(.+)" wp-piwik.php)

if [[ -n "$VERSION_TO_RELEASE" ]]
then
  version="$VERSION_TO_RELEASE"

  if [[ "$existing_version" != "$version" ]]; then
    echo "Version in wp-piwik.php not set correctly, aborting."
    exit 1
  fi
else
  version=$existing_version
fi
echo "Version to build: '$version'"

# the same invariant tests/phpunit/ReleaseTest.php asserts
class_version=$(grep -oP "\\\$version\s*=\s*'\K([^']+)" classes/WP_Piwik.php)
if [[ "$class_version" != "$version" ]]; then
  echo "Version in classes/WP_Piwik.php is '$class_version', expected '$version'. Aborting."
  exit 1
fi

stable_tag=$(grep -oP "^Stable tag: \K(.+)" readme.txt)
if [[ "$stable_tag" != "$version" ]]; then
  echo "Stable tag in readme.txt is '$stable_tag', expected '$version'. Aborting."
  exit 1
fi

echo "Check tag does not exist"
git fetch --all -q 2>/dev/null
tag_exists=$( git tag --list "$version" )
if [[ -n "$tag_exists" ]]
then
  echo "A tag for $tag_exists already exists."
  exit 1
fi

echo "version=$version" >> $GITHUB_ENV
