#!/usr/bin/env bash
set -euo pipefail

# Build .pkg.tar.zst packages and publish to S3 Arch repository
# Usage: ./scripts/publish-arch.sh <version> <dist-dir> <s3-bucket>

VERSION="${1:?Usage: publish-arch.sh <version> <dist-dir> <s3-bucket>}"
DIST_DIR="${2:?Usage: publish-arch.sh <version> <dist-dir> <s3-bucket>}"
S3_BUCKET="${3:?Usage: publish-arch.sh <version> <dist-dir> <s3-bucket>}"
VERSION="${VERSION#v}"
# pkgver must not contain hyphens in Arch Linux packaging
PKGVER="${VERSION//-/_}"

REPO=$(mktemp -d)
aws s3 sync "s3://${S3_BUCKET}/arch/" "${REPO}/" --quiet 2>/dev/null || true

for pair in "x86_64:dde-linux-amd64" "aarch64:dde-linux-arm64"; do
    ARCH="${pair%%:*}" BINARY="${pair##*:}"
    [ -f "${DIST_DIR}/${BINARY}" ] || continue

    WORK=$(mktemp -d)
    mkdir -p "${WORK}/usr/bin"
    cp "${DIST_DIR}/${BINARY}" "${WORK}/usr/bin/dde" && chmod 755 "${WORK}/usr/bin/dde"

    INSTALLED_SIZE=$(wc -c < "${WORK}/usr/bin/dde")
    cat > "${WORK}/.PKGINFO" <<EOF
pkgname = dde
pkgver = ${PKGVER}-1
arch = ${ARCH}
size = ${INSTALLED_SIZE}
pkgdesc = Docker Development Environment
url = https://github.com/whatwedo/dde
builddate = $(date +%s)
packager = whatwedo GmbH <welove@whatwedo.ch>
license = AGPL-3.0-or-later
EOF

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
    PKG_FILE="${ARCH_DIR}/dde-${PKGVER}-1-${ARCH}.pkg.tar.zst"
    (cd "${WORK}" && tar --zstd -cf "${PKG_FILE}" .PKGINFO .INSTALL usr/)
    rm -rf "${WORK}"

    # Sign the package
    gpg --batch --yes --pinentry-mode loopback \
        --passphrase "${GPG_PASSPHRASE:-}" \
        --detach-sign "${PKG_FILE}"

    # Add to repo database
    repo-add "${ARCH_DIR}/dde.db.tar.gz" "${PKG_FILE}"

    # Sign the database
    gpg --batch --yes --pinentry-mode loopback \
        --passphrase "${GPG_PASSPHRASE:-}" \
        --detach-sign "${ARCH_DIR}/dde.db.tar.gz"
done

gpg --armor --export > "${REPO}/key.gpg"
aws s3 sync "${REPO}/" "s3://${S3_BUCKET}/arch/" --delete --quiet
rm -rf "${REPO}"
echo "[ok] Arch repo published to s3://${S3_BUCKET}/arch/"
