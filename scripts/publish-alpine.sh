#!/usr/bin/env bash
set -euo pipefail

# Build .apk packages and publish to S3 Alpine repository
# Usage: ./scripts/publish-alpine.sh <version> <dist-dir> <s3-bucket> <rsa-key-path> <channel>
# <channel> is "stable" or "nightly".
#
# Runs inside an alpine:3.x container with apk-tools 2.x (see release.yml).
# Packages are built with abuild and the repository index with `apk index`,
# producing the classic v2 APKINDEX format that both apk-tools 2.x and 3.x
# clients can read. The previous hand-rolled approach emitted the raw
# .PKGINFO as the index, which apk rejects ("file format is invalid").

VERSION="${1:?Usage: publish-alpine.sh <version> <dist-dir> <s3-bucket> <rsa-key-path> <channel>}"
DIST_DIR="${2:?Usage: publish-alpine.sh <version> <dist-dir> <s3-bucket> <rsa-key-path> <channel>}"
S3_BUCKET="${3:?Usage: publish-alpine.sh <version> <dist-dir> <s3-bucket> <rsa-key-path> <channel>}"
RSA_KEY="${4:?Usage: publish-alpine.sh <version> <dist-dir> <s3-bucket> <rsa-key-path> <channel>}"
CHANNEL="${5:?Usage: publish-alpine.sh <version> <dist-dir> <s3-bucket> <rsa-key-path> <channel>}"
VERSION="${VERSION#v}"

# Alpine pkgver must not contain hyphens; the suffix separator is '_' and the
# suffix itself carries no dots: 2.0.0-beta.2 -> 2.0.0_beta2 (nightly versions
# like 20260609.1943 have no suffix and pass through unchanged).
BASE="${VERSION%%-*}"
REST="${VERSION#*-}"
if [ "${REST}" != "${VERSION}" ]; then
    PKGVER="${BASE}_${REST//./}"
else
    PKGVER="${BASE}"
fi

case "${CHANNEL}" in
    stable)
        PKG_NAME="dde"
        REPO_PATH="alpine"
        EXTRA_FIELDS=""
        ;;
    nightly)
        PKG_NAME="dde-nightly"
        REPO_PATH="alpine-nightly"
        # `depends=!dde` keeps the "one channel at a time" guarantee consistent
        # with APT/RPM/Arch — apk refuses to install dde-nightly while dde is
        # present (and vice versa). `provides`/`replaces` let apk re-route the
        # explicit `apk add dde-nightly` to take over from an existing dde
        # install without manual removal.
        EXTRA_FIELDS=$'provides="dde"\nreplaces="dde"\ndepends="!dde"'
        ;;
    *)
        echo "Error: unknown channel '${CHANNEL}' (expected: stable, nightly)" >&2
        exit 1
        ;;
esac

# Everything lands under one work root that we always clean up — including the
# copy of the signing key, so the private key never lingers on disk if the
# script fails partway through.
WORKROOT=$(mktemp -d)
trap 'rm -rf "${WORKROOT}"' EXIT

# abuild derives the embedded signature name from the key's basename, so name
# the key `dde.rsa` to produce `.SIGN.RSA.dde.rsa.pub` — matching the public
# key consumers install as /etc/apk/keys/dde.rsa.pub.
SIGN_KEY="${WORKROOT}/dde.rsa"
cp "${RSA_KEY}" "${SIGN_KEY}"
chmod 600 "${SIGN_KEY}"
openssl rsa -in "${SIGN_KEY}" -pubout -out "${SIGN_KEY}.pub" 2>/dev/null
mkdir -p "${HOME}/.abuild"
cat > "${HOME}/.abuild/abuild.conf" <<EOF
PACKAGER_PRIVKEY=${SIGN_KEY}
PACKAGER="whatwedo GmbH <welove@whatwedo.ch>"
EOF
# Trust the key so `apk index` accepts the freshly signed packages.
cp "${SIGN_KEY}.pub" /etc/apk/keys/dde.rsa.pub

REPO="${WORKROOT}/repo"
mkdir -p "${REPO}"

for pair in "x86_64:dde-linux-amd64" "aarch64:dde-linux-arm64"; do
    ARCH="${pair%%:*}" BINARY="${pair##*:}"
    [ -f "${DIST_DIR}/${BINARY}" ] || continue

    # A dedicated REPODEST per arch keeps exactly one package per directory, so
    # the collection step below cannot pick up the other arch's package (the
    # .apk filename carries no arch, only the directory does).
    BUILD="${WORKROOT}/build-${ARCH}"
    REPODEST="${WORKROOT}/repodest-${ARCH}"
    mkdir -p "${BUILD}"
    cp "${DIST_DIR}/${BINARY}" "${BUILD}/dde"

    cat > "${BUILD}/${PKG_NAME}.post-install" <<'POSTINST'
#!/bin/sh
if command -v dde >/dev/null 2>&1 && command -v docker >/dev/null 2>&1; then
    dde system:install --no-interaction || true
fi
POSTINST

    cat > "${BUILD}/${PKG_NAME}.post-upgrade" <<'POSTUPGRADE'
#!/bin/sh
if command -v dde >/dev/null 2>&1 && command -v docker >/dev/null 2>&1; then
    dde system:update --no-interaction || true
fi
POSTUPGRADE

    cat > "${BUILD}/APKBUILD" <<EOF
pkgname=${PKG_NAME}
pkgver=${PKGVER}
pkgrel=0
pkgdesc="Docker Development Environment"
url="https://github.com/whatwedo/dde"
arch="${ARCH}"
license="AGPL-3.0-or-later"
options="!check !strip !tracedeps !fhs"
install="\$pkgname.post-install \$pkgname.post-upgrade"
source="dde \$pkgname.post-install \$pkgname.post-upgrade"
${EXTRA_FIELDS}
package() {
    install -Dm755 "\$srcdir/dde" "\$pkgdir/usr/bin/dde"
}
EOF

    # CARCH override lets the (x86_64) runner cross-build the aarch64 package —
    # we only repackage a prebuilt binary, so no actual cross-compilation runs.
    ( cd "${BUILD}" && \
        CARCH="${ARCH}" abuild -F checksum && \
        CARCH="${ARCH}" REPODEST="${REPODEST}" abuild -F rootpkg )

    # Copy via $() so a missing package fails loudly under set -e instead of
    # silently skipping the copy.
    ARCH_DIR="${REPO}/${ARCH}"
    mkdir -p "${ARCH_DIR}"
    cp "$(find "${REPODEST}" -name '*.apk')" "${ARCH_DIR}/"

    # Generate and sign the v2 APKINDEX
    ( cd "${ARCH_DIR}" && \
        apk index -o APKINDEX.tar.gz ./*.apk && \
        abuild-sign -k "${SIGN_KEY}" APKINDEX.tar.gz )
done

cp "${SIGN_KEY}.pub" "${REPO}/key.rsa.pub"
aws s3 sync "${REPO}/" "s3://${S3_BUCKET}/${REPO_PATH}/" --delete --quiet
echo "[ok] Alpine repo published to s3://${S3_BUCKET}/${REPO_PATH}/ (${PKG_NAME} ${PKGVER})"
