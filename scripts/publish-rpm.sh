#!/usr/bin/env bash
set -euo pipefail

# Build .rpm packages and publish to S3 DNF/YUM repository
# Usage: ./scripts/publish-rpm.sh <version> <dist-dir> <s3-bucket>

VERSION="${1:?Usage: publish-rpm.sh <version> <dist-dir> <s3-bucket>}"
DIST_DIR="${2:?Usage: publish-rpm.sh <version> <dist-dir> <s3-bucket>}"
S3_BUCKET="${3:?Usage: publish-rpm.sh <version> <dist-dir> <s3-bucket>}"
VERSION="${VERSION#v}"
# RPM version must not contain hyphens
RPMVER="${VERSION//-/_}"

REPO=$(mktemp -d)
aws s3 sync "s3://${S3_BUCKET}/rpm/" "${REPO}/" --quiet 2>/dev/null || true

# Configure GPG agent for non-interactive signing (RPM 4.16+ uses GPGME)
mkdir -p ~/.gnupg && chmod 700 ~/.gnupg
printf 'allow-loopback-pinentry\nallow-preset-passphrase\n' >> ~/.gnupg/gpg-agent.conf
gpg-connect-agent reloadagent /bye 2>/dev/null || true

GPG_KEY_ID=$(gpg --list-secret-keys --with-colons 2>/dev/null | awk -F: '/^sec/{print $5; exit}')
KEYGRIP=$(gpg --list-secret-keys --with-keygrip --with-colons 2>/dev/null | awk -F: '/^grp/{print $10; exit}')
PASSPHRASE_HEX=$(printf '%s' "${GPG_PASSPHRASE:-}" | hexdump -v -e '/1 "%02X"')
gpg-connect-agent "PRESET_PASSPHRASE ${KEYGRIP} -1 ${PASSPHRASE_HEX}" /bye

printf '%%_gpg_name %s\n' "${GPG_KEY_ID}" >> ~/.rpmmacros

for pair in "x86_64:dde-linux-amd64" "aarch64:dde-linux-arm64"; do
    ARCH="${pair%%:*}" BINARY="${pair##*:}"
    [ -f "${DIST_DIR}/${BINARY}" ] || continue

    BUILD_DIR=$(mktemp -d)
    mkdir -p "${BUILD_DIR}/rpmbuild/"{SPECS,BUILD,RPMS,SRPMS,SOURCES,BUILDROOT}

    BINARY_PATH="$(realpath "${DIST_DIR}/${BINARY}")"

    cat > "${BUILD_DIR}/rpmbuild/SPECS/dde.spec" <<SPEC
Name:           dde
Version:        ${RPMVER}
Release:        1
Summary:        Docker Development Environment
License:        AGPL-3.0-or-later
URL:            https://github.com/whatwedo/dde
AutoReqProv:    no

%description
Docker Development Environment by whatwedo.
Manage local Docker development environments with ease.

%install
mkdir -p %{buildroot}/usr/bin
install -m 755 ${BINARY_PATH} %{buildroot}/usr/bin/dde

%post
if command -v dde >/dev/null 2>&1 && command -v docker >/dev/null 2>&1; then
    dde system:install --no-interaction || true
fi

%files
/usr/bin/dde
SPEC

    rpmbuild --define "_topdir ${BUILD_DIR}/rpmbuild" \
             --target "${ARCH}" \
             -bb "${BUILD_DIR}/rpmbuild/SPECS/dde.spec"

    ARCH_DIR="${REPO}/${ARCH}"
    mkdir -p "${ARCH_DIR}"

    # Sign and copy each RPM
    find "${BUILD_DIR}/rpmbuild/RPMS" -name "*.rpm" | while read -r RPM_FILE; do
        rpm --addsign "${RPM_FILE}"
        cp "${RPM_FILE}" "${ARCH_DIR}/"
    done
    rm -rf "${BUILD_DIR}"

    # Create repository metadata
    createrepo_c "${ARCH_DIR}"

    # Sign the repomd.xml
    gpg --batch --yes --pinentry-mode loopback \
        --passphrase "${GPG_PASSPHRASE:-}" \
        --armor --detach-sign "${ARCH_DIR}/repodata/repomd.xml"
done

gpg --armor --export > "${REPO}/key.gpg"

cat > "${REPO}/dde.repo" <<'REPO'
[dde]
name=dde - Docker Development Environment
baseurl=https://packages.dde.sh/rpm/$basearch
enabled=1
gpgcheck=1
gpgkey=https://packages.dde.sh/rpm/key.gpg
REPO

aws s3 sync "${REPO}/" "s3://${S3_BUCKET}/rpm/" --delete --quiet
rm -rf "${REPO}"
echo "[ok] RPM repo published to s3://${S3_BUCKET}/rpm/"
