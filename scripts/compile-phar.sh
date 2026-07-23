#!/usr/bin/env bash
set -euo pipefail

# Compile bin/dde.phar reproducibly.
#
# Two independent sources of non-determinism have to be pinned to the same
# instant, otherwise two builds from the same commit differ byte-for-byte:
#
#   1. Symfony bakes `container.build_time` / `container.build_id` into the
#      compiled prod container (which ships inside the PHAR). Symfony honours
#      SOURCE_DATE_EPOCH during cache warmup, so we export it before warming.
#   2. humbug/box stamps every PHAR entry with the wall-clock build time and
#      does NOT read SOURCE_DATE_EPOCH. Its own `timestamp` config key is the
#      only knob, so we inject it into a generated config. `--sort-compiled-files`
#      pins the otherwise filesystem-dependent entry order.
#
# SOURCE_DATE_EPOCH defaults to the committer date of HEAD; an externally set
# value wins, and we fall back to the current time when git is unavailable
# (e.g. building from a source tarball).

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
BOX_PHAR="${1:-/tmp/box.phar}"

: "${SOURCE_DATE_EPOCH:=$(git -C "$PROJECT_DIR" log -1 --format=%ct 2>/dev/null || date +%s)}"
export SOURCE_DATE_EPOCH
echo "==> SOURCE_DATE_EPOCH=${SOURCE_DATE_EPOCH}"

cd "$PROJECT_DIR"

# Deterministic Symfony container (build_time/build_id from SOURCE_DATE_EPOCH).
rm -rf var/cache/prod
APP_ENV=prod php bin/console cache:warmup --quiet

# box ignores SOURCE_DATE_EPOCH; force the timestamp via a generated config.
GENERATED_CONFIG="${PROJECT_DIR}/box.compile.json"
trap 'rm -f "$GENERATED_CONFIG"' EXIT
php -r '
    $config = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $config["timestamp"] = "@" . $argv[2];
    file_put_contents($argv[3], json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
' "${PROJECT_DIR}/box.json" "$SOURCE_DATE_EPOCH" "$GENERATED_CONFIG"

php "$BOX_PHAR" compile --config "$GENERATED_CONFIG" --sort-compiled-files
