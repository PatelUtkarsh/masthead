#!/usr/bin/env bash
# Set the version in the plugin header, the VERSION constant, package.json and
# the release zip URL in the Playground blueprint.
# Usage: bin/bump-version.sh 1.2.0
set -euo pipefail

version="${1:-}"
if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]]; then
	echo "Give a semantic version, for example 1.2.0." >&2
	exit 1
fi

cd "$(dirname "$0")/.."

perl -pi -e "s/^( \\* Version:\\s*).*\$/\${1}$version/" masthead.php
perl -pi -e "s/^const VERSION(\\s*)= '.*';\$/const VERSION\${1}= '$version';/" masthead.php
npm version "$version" --no-git-tag-version --allow-same-version > /dev/null
perl -pi -e "s#/releases/download/v[^/]+/masthead-[^\"]+\\.zip\"#/releases/download/v$version/masthead-$version.zip\"#" .github/blueprint.json

bin/check-version.sh "$version"
echo "Version set to $version."
