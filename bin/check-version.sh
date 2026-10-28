#!/usr/bin/env bash
# Check that every version string matches. Usage: bin/check-version.sh 1.2.0
set -euo pipefail

version="${1:-}"
if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]]; then
	echo "Give a semantic version, for example 1.2.0." >&2
	exit 1
fi

cd "$(dirname "$0")/.."

header=$(sed -n 's/^ \* Version:[[:space:]]*\(.*\)$/\1/p' masthead.php)
constant=$(sed -n "s/^const VERSION[[:space:]]*=[[:space:]]*'\(.*\)';$/\1/p" masthead.php)
package=$(node -p "require('./package.json').version")
blueprint=$(sed -n 's#.*/releases/download/v\([^/]*\)/masthead-.*\.zip".*#\1#p' .github/blueprint.json)

status=0
for pair in "plugin header:$header" "VERSION constant:$constant" "package.json:$package" "blueprint zip:$blueprint"; do
	name="${pair%%:*}"
	value="${pair#*:}"
	if [[ "$value" != "$version" ]]; then
		echo "Version mismatch: $name is '$value', expected '$version'." >&2
		status=1
	fi
done

if [[ $status -ne 0 ]]; then
	echo "Bump the version on main first: bin/bump-version.sh $version" >&2
fi
exit $status
