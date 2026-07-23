#!/usr/bin/env bash
set -euo pipefail

# Build .pkg.tar.zst packages and publish to S3 Arch repository
# Usage: ./scripts/publish-arch.sh <version> <dist-dir> <s3-bucket> <channel>
# <channel> is "stable" or "nightly".

VERSION="${1:?Usage: publish-arch.sh <version> <dist-dir> <s3-bucket> <channel>}"
DIST_DIR="${2:?Usage: publish-arch.sh <version> <dist-dir> <s3-bucket> <channel>}"
S3_BUCKET="${3:?Usage: publish-arch.sh <version> <dist-dir> <s3-bucket> <channel>}"
CHANNEL="${4:?Usage: publish-arch.sh <version> <dist-dir> <s3-bucket> <channel>}"
VERSION="${VERSION#v}"
# pkgver must not contain hyphens in Arch Linux packaging
PKGVER="${VERSION//-/_}"

case "${CHANNEL}" in
    stable)
        PKG_NAME="dde"
        REPO_PATH="arch"
        EXTRA_PKGINFO=""
        ;;
    nightly)
        PKG_NAME="dde-nightly"
        REPO_PATH="arch-nightly"
        # Only `conflict = dde` (+ provides=dde so anything depending on the
        # dde virtual name resolves). We intentionally omit `replaces = dde`
        # — pacman would silently auto-migrate stable installs on `pacman -Syu`
        # whenever both repos are active, which we do not want.
        EXTRA_PKGINFO=$'conflict = dde\nprovides = dde\n'
        ;;
    *)
        echo "Error: unknown channel '${CHANNEL}' (expected: stable, nightly)" >&2
        exit 1
        ;;
esac

REPO=$(mktemp -d)
aws s3 sync "s3://${S3_BUCKET}/${REPO_PATH}/" "${REPO}/" --quiet 2>/dev/null || true

for pair in "x86_64:dde-linux-amd64" "aarch64:dde-linux-arm64"; do
    ARCH="${pair%%:*}" BINARY="${pair##*:}"
    [ -f "${DIST_DIR}/${BINARY}" ] || continue

    WORK=$(mktemp -d)
    mkdir -p "${WORK}/usr/bin"
    cp "${DIST_DIR}/${BINARY}" "${WORK}/usr/bin/dde" && chmod 755 "${WORK}/usr/bin/dde"

    INSTALLED_SIZE=$(wc -c < "${WORK}/usr/bin/dde")
    {
        cat <<EOF
pkgname = ${PKG_NAME}
pkgver = ${PKGVER}-1
arch = ${ARCH}
size = ${INSTALLED_SIZE}
pkgdesc = Docker Development Environment
url = https://github.com/whatwedo/dde
builddate = ${SOURCE_DATE_EPOCH:-$(date +%s)}
packager = whatwedo GmbH <welove@whatwedo.ch>
license = AGPL-3.0-or-later
EOF
        printf '%s' "${EXTRA_PKGINFO}"
    } > "${WORK}/.PKGINFO"

    cat > "${WORK}/.INSTALL" <<'INSTALL'
post_install() {
    if command -v dde >/dev/null 2>&1 && command -v docker >/dev/null 2>&1; then
        dde system:install --no-interaction || true
    fi
}

post_upgrade() {
    if command -v dde >/dev/null 2>&1 && command -v docker >/dev/null 2>&1; then
        dde system:update --no-interaction || true
    fi
}
INSTALL

    ARCH_DIR="${REPO}/${ARCH}"
    mkdir -p "${ARCH_DIR}"
    PKG_FILE="${ARCH_DIR}/${PKG_NAME}-${PKGVER}-1-${ARCH}.pkg.tar.zst"
    (cd "${WORK}" && tar --zstd -cf "${PKG_FILE}" .PKGINFO .INSTALL usr/)
    rm -rf "${WORK}"

    # Sign the package
    gpg --batch --yes --pinentry-mode loopback \
        --passphrase "${GPG_PASSPHRASE:-}" \
        --detach-sign "${PKG_FILE}"

    # Add to repo database
    repo-add "${ARCH_DIR}/${PKG_NAME}.db.tar.gz" "${PKG_FILE}"

    # Sign the database
    gpg --batch --yes --pinentry-mode loopback \
        --passphrase "${GPG_PASSPHRASE:-}" \
        --detach-sign "${ARCH_DIR}/${PKG_NAME}.db.tar.gz"
done

gpg --armor --export > "${REPO}/key.gpg"
aws s3 sync "${REPO}/" "s3://${S3_BUCKET}/${REPO_PATH}/" --delete --quiet
rm -rf "${REPO}"
echo "[ok] Arch repo published to s3://${S3_BUCKET}/${REPO_PATH}/ (${PKG_NAME} ${PKGVER})"
