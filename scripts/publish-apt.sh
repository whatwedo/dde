#!/usr/bin/env bash
set -euo pipefail

# Build .deb packages and publish to S3 APT repository
# Usage: ./scripts/publish-apt.sh <version> <dist-dir> <s3-bucket>

VERSION="${1:?Usage: publish-apt.sh <version> <dist-dir> <s3-bucket>}"
DIST_DIR="${2:?Usage: publish-apt.sh <version> <dist-dir> <s3-bucket>}"
S3_BUCKET="${3:?Usage: publish-apt.sh <version> <dist-dir> <s3-bucket>}"
VERSION="${VERSION#v}"

# Build .deb for each architecture
for pair in "amd64:dde-linux-amd64" "arm64:dde-linux-arm64"; do
    ARCH="${pair%%:*}" BINARY="${pair##*:}"
    [ -f "${DIST_DIR}/${BINARY}" ] || continue

    PKG=$(mktemp -d)
    mkdir -p "${PKG}/usr/bin" "${PKG}/DEBIAN"
    cp "${DIST_DIR}/${BINARY}" "${PKG}/usr/bin/dde" && chmod 755 "${PKG}/usr/bin/dde"
    cat > "${PKG}/DEBIAN/control" <<EOF
Package: dde
Version: ${VERSION}
Architecture: ${ARCH}
Maintainer: whatwedo GmbH <welove@whatwedo.ch>
Description: Docker Development Environment
Homepage: https://github.com/whatwedo/dde
Priority: optional
Recommends: mkcert
EOF
    cat > "${PKG}/DEBIAN/postinst" <<'POSTINST'
#!/bin/sh
set -e
if command -v dde >/dev/null 2>&1 && command -v docker >/dev/null 2>&1; then
    if [ -n "$2" ]; then
        dde system:update --no-interaction || true
    else
        dde system:install --no-interaction || true
    fi
fi
POSTINST
    chmod 755 "${PKG}/DEBIAN/postinst"
    dpkg-deb --build "${PKG}" "${DIST_DIR}/dde_${VERSION}_${ARCH}.deb"
    rm -rf "${PKG}"
done

# Sync to S3 APT repo
REPO=$(mktemp -d)
aws s3 sync "s3://${S3_BUCKET}/apt/" "${REPO}/" --quiet 2>/dev/null || true
mkdir -p "${REPO}/pool/main"
cp "${DIST_DIR}"/dde_*.deb "${REPO}/pool/main/"

for arch in amd64 arm64; do
    mkdir -p "${REPO}/dists/stable/main/binary-${arch}"
    (cd "${REPO}" && dpkg-scanpackages --arch "${arch}" pool/ > "dists/stable/main/binary-${arch}/Packages")
    gzip -kf "${REPO}/dists/stable/main/binary-${arch}/Packages"
done

cat > "${REPO}/dists/stable/Release" <<EOF
Origin: whatwedo
Label: dde
Suite: stable
Codename: stable
Architectures: amd64 arm64
Components: main
Date: $(date -R -u)
EOF

(cd "${REPO}/dists/stable" && {
    echo "SHA256:"
    for f in main/binary-*/Packages main/binary-*/Packages.gz; do
        [ -f "$f" ] || continue
        HASH=$(sha256sum "$f" | awk '{print $1}')
        SIZE=$(stat -c%s "$f" 2>/dev/null || stat -f%z "$f")
        printf " %s %8s %s\n" "$HASH" "$SIZE" "$f"
    done
} >> Release)

(cd "${REPO}/dists/stable" && \
    gpg --batch --yes --pinentry-mode loopback --passphrase "${GPG_PASSPHRASE:-}" --armor --detach-sign -o Release.gpg Release && \
    gpg --batch --yes --pinentry-mode loopback --passphrase "${GPG_PASSPHRASE:-}" --armor --clearsign -o InRelease Release)

gpg --armor --export > "${REPO}/key.gpg"
aws s3 sync "${REPO}/" "s3://${S3_BUCKET}/apt/" --delete
rm -rf "${REPO}"
echo "[ok] APT repo published to s3://${S3_BUCKET}/apt/"
