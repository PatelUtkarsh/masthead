#!/usr/bin/env bash
# Build the plugin and package it into dist/masthead/ and dist/masthead-<version>.zip.
# Files listed in .distignore are left out. Usage: bin/package.sh [version]
set -euo pipefail

cd "$(dirname "$0")/.."

version="${1:-$(node -p "require('./package.json').version")}"

npm ci --no-audit --no-fund
npm run build

rm -rf dist
mkdir -p dist/masthead
rsync -a --exclude-from=.distignore ./ dist/masthead/

if [[ ! -f dist/masthead/build/admin-app.js ]]; then
	echo "build/ is missing from the package." >&2
	exit 1
fi

(cd dist && zip -qr "masthead-${version}.zip" masthead)
echo "dist/masthead-${version}.zip"
