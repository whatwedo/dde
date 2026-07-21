#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
CACHE_DIR="${PROJECT_DIR}/var/build"
BINARY_OUTPUT="${PROJECT_DIR}/bin/dde"

# Read PHP version from composer.json
PHP_VERSION=$(grep '"php":' "$PROJECT_DIR/composer.json" | grep -o '[0-9]\+\.[0-9]\+\.[0-9]\+')
if [[ -z "$PHP_VERSION" ]]; then
    echo "Error: could not read PHP version from composer.json"
    exit 1
fi

# Detect platform
PLATFORM="${PLATFORM:-$(uname -s | tr '[:upper:]' '[:lower:]')}"
case "$PLATFORM" in
    darwin|macos) PLATFORM="macos" ;;
    linux) PLATFORM="linux" ;;
    *) echo "Error: unsupported platform: $PLATFORM"; exit 1 ;;
esac

# Detect architecture
ARCH="${ARCH:-$(uname -m)}"
case "$ARCH" in
    x86_64|amd64) ARCH="x86_64" ;;
    arm64|aarch64) ARCH="aarch64" ;;
    *) echo "Error: unsupported architecture: $ARCH"; exit 1 ;;
esac

echo "==> Platform: ${PLATFORM}/${ARCH}, PHP: ${PHP_VERSION}"

MICRO_SFX_FILE="php-${PHP_VERSION}-micro-${PLATFORM}-${ARCH}.tar.gz"
MICRO_SFX_URL="https://dl.static-php.dev/static-php-cli/common/${MICRO_SFX_FILE}"
MICRO_SFX_CACHED="${CACHE_DIR}/micro-${PHP_VERSION}-${PLATFORM}-${ARCH}.sfx"

# SOURCE_DATE_EPOCH: use the committer date of HEAD for reproducible builds.
# An externally set value (e.g. from CI) takes precedence; fall back to
# current time when git metadata is unavailable (e.g. source tarball).
if [[ -z "${SOURCE_DATE_EPOCH:-}" ]]; then
    SOURCE_DATE_EPOCH="$(git -C "$PROJECT_DIR" log -1 --format=%ct 2>/dev/null || date +%s)"
fi
export SOURCE_DATE_EPOCH
echo "==> SOURCE_DATE_EPOCH=${SOURCE_DATE_EPOCH}"

# Step 1: Build PHAR
echo "==> Building PHAR..."
cd "$PROJECT_DIR"
composer install --no-dev --optimize-autoloader --quiet
rm -rf var/cache/*
APP_ENV=prod php bin/console cache:warmup --quiet
curl -fsSL https://github.com/box-project/box/releases/download/4.7.0/box.phar -o /tmp/box.phar
php /tmp/box.phar compile
rm -rf var/cache/*
composer install --quiet

# Step 2: Get micro.sfx
mkdir -p "$CACHE_DIR"

if [[ -f "$MICRO_SFX_CACHED" ]]; then
    echo "==> Using cached micro.sfx"
else
    echo "==> Downloading micro.sfx from ${MICRO_SFX_URL}"
    TEMP_ARCHIVE="${CACHE_DIR}/${MICRO_SFX_FILE}"
    if ! curl -fSL -o "$TEMP_ARCHIVE" "$MICRO_SFX_URL"; then
        echo "Error: failed to download micro.sfx"
        rm -f "$TEMP_ARCHIVE"
        exit 1
    fi
    if [ ! -s "$TEMP_ARCHIVE" ]; then
        echo "Error: micro.sfx download failed or file is empty"
        rm -f "$TEMP_ARCHIVE"
        exit 1
    fi
    tar xzf "$TEMP_ARCHIVE" -C "$CACHE_DIR"
    mv "${CACHE_DIR}/micro.sfx" "$MICRO_SFX_CACHED"
    rm -f "$TEMP_ARCHIVE"
fi

# Step 3: Combine micro.sfx + PHAR
echo "==> Building standalone binary..."
rm -f "$BINARY_OUTPUT"
cat "$MICRO_SFX_CACHED" "${PROJECT_DIR}/bin/dde.phar" > "$BINARY_OUTPUT"
chmod +x "$BINARY_OUTPUT"

# Step 4: Verify
VERSION_OUTPUT="$("$BINARY_OUTPUT" --version 2>&1)" || {
    echo "Error: binary verification failed"
    exit 1
}
echo "==> Build complete: bin/dde (${VERSION_OUTPUT})"
