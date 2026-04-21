#!/usr/bin/env bash
set -euo pipefail

# Update Homebrew formula in whatwedo/homebrew-dde
# Usage: ./scripts/update-homebrew.sh <version> <dist-dir>
# Requires: SSH key configured for git access to whatwedo/homebrew-dde

VERSION="${1:?Usage: update-homebrew.sh <version> <dist-dir>}"
DIST_DIR="${2:?Usage: update-homebrew.sh <version> <dist-dir>}"
VERSION_CLEAN="${VERSION#v}"

sha256_of() { sha256sum "$1" | awk '{print $1}'; }

sha_darwin_arm64=$(sha256_of "${DIST_DIR}/dde-darwin-arm64")
sha_darwin_amd64=$(sha256_of "${DIST_DIR}/dde-darwin-amd64")
sha_linux_arm64=$(sha256_of "${DIST_DIR}/dde-linux-arm64")
sha_linux_amd64=$(sha256_of "${DIST_DIR}/dde-linux-amd64")

FORMULA=$(cat << RUBY
class Dde < Formula
  desc "Docker Development Environment"
  homepage "https://github.com/whatwedo/dde"
  version "${VERSION_CLEAN}"
  license "AGPL-3.0-or-later"

  depends_on "mkcert"

  on_macos do
    on_arm do
      url "https://packages.dde.sh/homebrew/${VERSION_CLEAN}/dde-darwin-arm64"
      sha256 "${sha_darwin_arm64}"
    end
    on_intel do
      url "https://packages.dde.sh/homebrew/${VERSION_CLEAN}/dde-darwin-amd64"
      sha256 "${sha_darwin_amd64}"
    end
  end

  on_linux do
    on_arm do
      url "https://packages.dde.sh/homebrew/${VERSION_CLEAN}/dde-linux-arm64"
      sha256 "${sha_linux_arm64}"
    end
    on_intel do
      url "https://packages.dde.sh/homebrew/${VERSION_CLEAN}/dde-linux-amd64"
      sha256 "${sha_linux_amd64}"
    end
  end

  def install
    bin.install version.to_s => "dde"
  end

  def caveats
    <<~EOS
      To finish installing dde, run:
        dde system:install
    EOS
  end

  test do
    assert_match "dde", shell_output("#{bin}/dde --version")
  end
end
RUBY
)

WORK_DIR=$(mktemp -d)
git clone "git@github.com:whatwedo/homebrew-dde.git" "${WORK_DIR}"
mkdir -p "${WORK_DIR}/Formula"
echo "${FORMULA}" > "${WORK_DIR}/Formula/dde.rb"

cd "${WORK_DIR}"
git add Formula/dde.rb
git commit -m "update dde formula for ${VERSION}" || echo "No changes"
git push
cd -

rm -rf "${WORK_DIR}"
echo "[ok] Homebrew formula updated to ${VERSION_CLEAN}"
