#!/usr/bin/env bash

# source: https://github.com/10up/action-wordpress-plugin-asset-update/blob/develop/entrypoint.sh
# https://github.com/10up/action-wordpress-plugin-asset-update/
# License: MIT see https://github.com/10up/action-wordpress-plugin-asset-update/blob/develop/LICENSE

# Modifications from the Matomo team for our use case

VERSION=$1
ARCHIVE=$2

# Note that this does not use pipefail because if the grep later
# doesn't match I want to be able to show an error first
set -e

die() {
  echo "$*" 1>&2 ;
  exit 1;
}

# Ensure SVN username and password are set
# IMPORTANT: while secrets are encrypted and not viewable in the GitHub UI,
# they are by necessity provided as plaintext in the context of the Action,
# so do not echo or use debug mode unless you want your secrets exposed!
if [[ -z "$VERSION" ]]; then
	echo "Set the VERSION number"
	exit 1
fi

if [[ -z "$SVN_USERNAME" ]]; then
	echo "Set the SVN_USERNAME secret"
	exit 1
fi

if [[ -z "$SVN_PASSWORD" ]]; then
	echo "Set the SVN_PASSWORD secret"
	exit 1
fi

if [[ -z "$ARCHIVE" ]] || [[ ! -f "$ARCHIVE" ]]; then
	echo "Set the ARCHIVE argument to the .tgz release archive built by the workflow"
	exit 1
fi

# Allow some ENV variables to be customized
SLUG=wp-piwik

echo "ℹ︎ SLUG is $SLUG"

ASSETS_DIR=".wordpress-org"
echo "ℹ︎ ASSETS_DIR is $ASSETS_DIR"

README_NAME="readme.txt"
echo "ℹ︎ README_NAME is $README_NAME"

# The plugin checkout this script lives in, used only to look for $ASSETS_DIR.
# The files that get deployed all come out of $ARCHIVE, never from here.
PLUGIN_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"

mkdir -p /tmp/github

SVN_URL="https://plugins.svn.wordpress.org/${SLUG}/"
SVN_DIR="/tmp/github/svn-${SLUG}"
rm -rf "$SVN_DIR"

TMP_DIR="/tmp/github/archivetmp"
rm -rf "$TMP_DIR"
mkdir -p "$TMP_DIR"

# Checkout just trunk for efficiency. assets is only pulled down when we
# actually have something to put in it, see below.
# Stable tag will come later, if applicable
echo "➤ Checking out svn .org repository..."
svn checkout --depth immediates "$SVN_URL" "$SVN_DIR"
cd "$SVN_DIR"
svn update --set-depth infinity trunk
svn update --set-depth immediates tags

if [[ -d "tags/$VERSION" ]] && [[ "$FORCE_DEPLOY" != "1" ]]; then
	echo "ℹ︎ Version $VERSION of plugin $SLUG was already published";
	exit
fi

echo "➤ Unpacking release archive..."
# --strip-components=1 drops the wp-piwik/ prefix the archive is built with
tar -xf "$ARCHIVE" --strip-components=1 --directory="$TMP_DIR"

archive_version=$(grep -oP "^Stable tag: \K(.+)" "$TMP_DIR/$README_NAME" | tr -d '\r')
if [[ "$archive_version" != "$VERSION" ]]; then
	die "➤ ERROR: archive contains version '$archive_version' but '$VERSION' was requested"
fi

cd "$SVN_DIR"

# Copy dotorg assets to /assets.
# This is deliberately conditional: the banners and icons currently live only in
# svn, not in git. Running the rsync unconditionally against a missing or empty
# source would delete them from the plugin directory page.
if [[ -d "$PLUGIN_DIR/$ASSETS_DIR" ]]; then
	echo "➤ Syncing $ASSETS_DIR to svn assets/..."
	svn update --set-depth infinity assets
	rsync -rc "$PLUGIN_DIR/$ASSETS_DIR/" assets/ --delete --delete-excluded
else
	echo "ℹ︎ no $ASSETS_DIR directory in the repository, leaving svn assets/ untouched"
fi

# Copy from the unpacked archive to /trunk.
echo "➤ existing trunk version: $(grep -oP "^Stable tag: \K(.+)" trunk/"$README_NAME")"

echo "➤ Copying files..."
rsync -rc "$TMP_DIR/" trunk --delete --delete-excluded

echo "➤ rsynced version: $(grep -oP "^Stable tag: \K(.+)" trunk/"$README_NAME")"

if [[ ! -d "trunk" ]]; then # sanity check
	echo "➤ ERROR: 'trunk' folder does not exist"
	exit 1;
fi

# The force flag ensures we recurse into subdirectories even if they are already added
# Suppress stdout in favor of svn status later for readability
echo "➤ Preparing files..."
svn add . --force > /dev/null

# SVN delete all deleted files
# Also suppress stdout here
svn status | grep '^\!' | sed 's/! *//' | xargs -I% svn rm %@ > /dev/null

# Fix screenshots getting force downloaded when clicking them
# https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/
if test -d "assets" && test -n "$(find "assets" -maxdepth 1 -name "*.png" -print -quit)"; then
    svn propset svn:mime-type image/png assets/*.png || true
fi
if test -d "assets" && test -n "$(find "assets" -maxdepth 1 -name "*.jpg" -print -quit)"; then
    svn propset svn:mime-type image/jpeg assets/*.jpg || true
fi
if test -d "assets" && test -n "$(find "assets" -maxdepth 1 -name "*.gif" -print -quit)"; then
    svn propset svn:mime-type image/gif assets/*.gif || true
fi
if test -d "assets" && test -n "$(find "assets" -maxdepth 1 -name "*.svg" -print -quit)"; then
    svn propset svn:mime-type image/svg+xml assets/*.svg || true
fi

echo "➤ svn status..."
svn status

echo "➤ Committing files..."
svn commit -m "Update to version $VERSION from GitHub" --no-auth-cache --non-interactive  --username "$SVN_USERNAME" --password "$SVN_PASSWORD"

# Copy tag locally in another commit
echo "➤ Copying tag..."

if [[ -d "tags/$VERSION" ]]; then
  svn rm "tags/$VERSION"

  echo "➤ Deleting existing tag..."
  svn commit -m "Deleting existing $VERSION for replacement" --no-auth-cache --non-interactive  --username "$SVN_USERNAME" --password "$SVN_PASSWORD"
fi

svn cp "trunk" "tags/$VERSION"

echo "➤ svn status..."
svn status

echo "➤ Committing files..."
svn commit -m "Create tag $VERSION from GitHub" --no-auth-cache --non-interactive  --username "$SVN_USERNAME" --password "$SVN_PASSWORD"

echo "✓ Plugin deployed!"
