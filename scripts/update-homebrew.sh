#!/usr/bin/env bash
set -euo pipefail

# Update Homebrew formula in whatwedo/homebrew-dde
# Usage: ./scripts/update-homebrew.sh <version> <dist-dir> <channel>
# <channel> is "stable" or "nightly".
# Requires: SSH key configured for git access to whatwedo/homebrew-dde

VERSION="${1:?Usage: update-homebrew.sh <version> <dist-dir> <channel>}"
DIST_DIR="${2:?Usage: update-homebrew.sh <version> <dist-dir> <channel>}"
CHANNEL="${3:?Usage: update-homebrew.sh <version> <dist-dir> <channel>}"
VERSION_CLEAN="${VERSION#v}"

case "${CHANNEL}" in
    stable)
        FORMULA_CLASS="Dde"
        FORMULA_FILE="dde.rb"
        URL_PREFIX="homebrew"
        CONFLICTS_BLOCK=""
        CAVEATS_BIN="dde"
        ;;
    nightly)
        FORMULA_CLASS="DdeNightly"
        FORMULA_FILE="dde-nightly.rb"
        URL_PREFIX="homebrew-nightly"
        CONFLICTS_BLOCK=$'  conflicts_with "dde", because: "both install /usr/local/bin/dde (or equivalent)"\n\n'
        CAVEATS_BIN="dde-nightly"
        ;;
    *)
        echo "Error: unknown channel '${CHANNEL}' (expected: stable, nightly)" >&2
        exit 1
        ;;
esac

sha256_of() { sha256sum "$1" | awk '{print $1}'; }

sha_darwin_arm64=$(sha256_of "${DIST_DIR}/dde-darwin-arm64")
sha_darwin_amd64=$(sha256_of "${DIST_DIR}/dde-darwin-amd64")
sha_linux_arm64=$(sha256_of "${DIST_DIR}/dde-linux-arm64")
sha_linux_amd64=$(sha256_of "${DIST_DIR}/dde-linux-amd64")

FORMULA=$(cat << RUBY
class ${FORMULA_CLASS} < Formula
  desc "Docker Development Environment"
  homepage "https://github.com/whatwedo/dde"
  version "${VERSION_CLEAN}"
  license "AGPL-3.0-or-later"

  depends_on "mkcert"

${CONFLICTS_BLOCK}  on_macos do
    on_arm do
      url "https://packages.dde.sh/${URL_PREFIX}/${VERSION_CLEAN}/dde-darwin-arm64"
      sha256 "${sha_darwin_arm64}"
    end
    on_intel do
      url "https://packages.dde.sh/${URL_PREFIX}/${VERSION_CLEAN}/dde-darwin-amd64"
      sha256 "${sha_darwin_amd64}"
    end
  end

  on_linux do
    on_arm do
      url "https://packages.dde.sh/${URL_PREFIX}/${VERSION_CLEAN}/dde-linux-arm64"
      sha256 "${sha_linux_arm64}"
    end
    on_intel do
      url "https://packages.dde.sh/${URL_PREFIX}/${VERSION_CLEAN}/dde-linux-amd64"
      sha256 "${sha_linux_amd64}"
    end
  end

  def install
    bin.install Dir["dde-*"].first => "dde"
  end

  def caveats
    <<~EOS
      After installing ${CAVEATS_BIN} for the first time, run:
        dde system:install

      After upgrading ${CAVEATS_BIN}, run:
        dde system:update
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
echo "${FORMULA}" > "${WORK_DIR}/Formula/${FORMULA_FILE}"

cd "${WORK_DIR}"
git add "Formula/${FORMULA_FILE}"
git commit -m "update ${FORMULA_FILE%.rb} formula for ${VERSION}" || echo "No changes"
git push
cd -

rm -rf "${WORK_DIR}"
echo "[ok] Homebrew formula ${FORMULA_FILE} updated to ${VERSION_CLEAN}"
