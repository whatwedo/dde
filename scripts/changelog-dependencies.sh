#!/usr/bin/env bash
# Prints a changelog entry for every dependency shipped in the binary — the PHP
# runtime and the non-dev Composer packages — that changed since the given ref.
set -euo pipefail

SINCE="${1:?Usage: changelog-dependencies.sh <ref>}"
cd "$(dirname "${BASH_SOURCE[0]}")/.."

jq -rn \
    --slurpfile new composer.lock --slurpfile newJson composer.json \
    --slurpfile old <(git show "${SINCE}:composer.lock") --slurpfile oldJson <(git show "${SINCE}:composer.json") '
    def versions($json):
        (.packages | map({key: .name, value: (.version | ltrimstr("v"))}) | from_entries)
        + {php: ($json.require.php | ltrimstr("^"))};
    ($old[0] | versions($oldJson[0])) as $from | ($new[0] | versions($newJson[0])) as $to
    | $to | keys[]
    | select($from[.] and $from[.] != $to[.])
    | "- Updated \(if . == "php" then "the PHP runtime" else "`\(.)`" end) from \($from[.]) to \($to[.])."
'
