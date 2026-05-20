#!/usr/bin/env bash
set -euo pipefail

# Build .apk packages and publish to S3 Alpine repository
# Usage: ./scripts/publish-alpine.sh <version> <dist-dir> <s3-bucket> <rsa-key-path> <channel>
# <channel> is "stable" or "nightly".

VERSION="${1:?Usage: publish-alpine.sh <version> <dist-dir> <s3-bucket> <rsa-key-path> <channel>}"
DIST_DIR="${2:?Usage: publish-alpine.sh <version> <dist-dir> <s3-bucket> <rsa-key-path> <channel>}"
S3_BUCKET="${3:?Usage: publish-alpine.sh <version> <dist-dir> <s3-bucket> <rsa-key-path> <channel>}"
RSA_KEY="${4:?Usage: publish-alpine.sh <version> <dist-dir> <s3-bucket> <rsa-key-path> <channel>}"
CHANNEL="${5:?Usage: publish-alpine.sh <version> <dist-dir> <s3-bucket> <rsa-key-path> <channel>}"
VERSION="${VERSION#v}"

case "${CHANNEL}" in
    stable)
        PKG_NAME="dde"
        REPO_PATH="alpine"
        EXTRA_PKGINFO=""
        ;;
    nightly)
        PKG_NAME="dde-nightly"
        REPO_PATH="alpine-nightly"
        # `conflict = dde` keeps the "one channel at a time" guarantee
        # consistent with APT/RPM/Arch — apk refuses to install dde-nightly
        # while dde is present (and vice versa). `provides`/`replaces` let
        # apk re-route the explicit `apk add dde-nightly` to take over from
        # an existing dde install without manual removal.
        EXTRA_PKGINFO=$'conflict = dde\nprovides = dde\nreplaces = dde\n'
        ;;
    *)
        echo "Error: unknown channel '${CHANNEL}' (expected: stable, nightly)" >&2
        exit 1
        ;;
esac

REPO=$(mktemp -d)

for pair in "x86_64:dde-linux-amd64" "aarch64:dde-linux-arm64"; do
    ARCH="${pair%%:*}" BINARY="${pair##*:}"
    [ -f "${DIST_DIR}/${BINARY}" ] || continue

    # Build .apk
    WORK=$(mktemp -d)
    mkdir -p "${WORK}/usr/bin"
    cp "${DIST_DIR}/${BINARY}" "${WORK}/usr/bin/dde" && chmod 755 "${WORK}/usr/bin/dde"
    {
        cat <<EOF
pkgname = ${PKG_NAME}
pkgver = ${VERSION}-r0
arch = ${ARCH}
size = $(wc -c < "${WORK}/usr/bin/dde")
pkgdesc = Docker Development Environment
url = https://github.com/whatwedo/dde
maintainer = whatwedo GmbH <welove@whatwedo.ch>
license = AGPL-3.0-or-later
EOF
        printf '%s' "${EXTRA_PKGINFO}"
    } > "${WORK}/.PKGINFO"
    cat > "${WORK}/.post-install" <<'POSTINST'
#!/bin/sh
if command -v dde >/dev/null 2>&1 && command -v docker >/dev/null 2>&1; then
    dde system:install --no-interaction || true
fi
POSTINST
    chmod 755 "${WORK}/.post-install"

    cat > "${WORK}/.post-upgrade" <<'POSTUPGRADE'
#!/bin/sh
if command -v dde >/dev/null 2>&1 && command -v docker >/dev/null 2>&1; then
    dde system:update --no-interaction || true
fi
POSTUPGRADE
    chmod 755 "${WORK}/.post-upgrade"

    ARCH_DIR="${REPO}/${ARCH}"
    mkdir -p "${ARCH_DIR}"
    (cd "${WORK}" && tar -czf "${ARCH_DIR}/${PKG_NAME}-${VERSION}-r0-${ARCH}.apk" .PKGINFO .post-install .post-upgrade usr/)
    rm -rf "${WORK}"

    # Generate APKINDEX
    (cd "${ARCH_DIR}" && for apk in *.apk; do
        tar -xzf "$apk" .PKGINFO 2>/dev/null && cat .PKGINFO && echo "" && rm .PKGINFO
    done > APKINDEX && tar -czf APKINDEX.tar.gz APKINDEX && rm APKINDEX)

    # Sign APKINDEX
    (cd "${ARCH_DIR}" && \
        openssl dgst -sha1 -sign "${RSA_KEY}" -out .SIGN.RSA.dde.rsa.pub APKINDEX.tar.gz && \
        mv APKINDEX.tar.gz APKINDEX.unsigned.tar.gz && \
        tar -czf APKINDEX.tar.gz .SIGN.RSA.dde.rsa.pub && \
        cat APKINDEX.unsigned.tar.gz >> APKINDEX.tar.gz && \
        rm APKINDEX.unsigned.tar.gz .SIGN.RSA.dde.rsa.pub)
done

openssl rsa -in "${RSA_KEY}" -pubout -out "${REPO}/key.rsa.pub" 2>/dev/null
aws s3 sync "${REPO}/" "s3://${S3_BUCKET}/${REPO_PATH}/" --delete --quiet
rm -rf "${REPO}"
echo "[ok] Alpine repo published to s3://${S3_BUCKET}/${REPO_PATH}/ (${PKG_NAME} ${VERSION})"
