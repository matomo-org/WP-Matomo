#!/usr/bin/env bash

set -eo pipefail

git archive --format=zip --prefix=wp-piwik/ -o "wp-piwik-$version.zip" HEAD
git archive --format=tar.gz --prefix=wp-piwik/ -o "wp-piwik-$version.tgz" HEAD
