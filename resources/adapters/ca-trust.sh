detect() {
    [ -f /dde/mkcert-rootCA.crt ]
}

configure() {
    # Install the bind-mounted mkcert root CA into the container's trust store
    # so in-container HTTPS calls to local .test services work without --insecure.
    #
    # Which store is used is decided by the trust tooling, not by directory
    # existence: a minimal Debian image ships /usr/local/share/ca-certificates
    # without the ca-certificates package, so a directory-first branch would
    # copy the CA where nothing reads it. On minimal Debian/Ubuntu/Alpine the
    # package is therefore installed on demand — but only when no updater is
    # present, to avoid a network hit on every container start. The one
    # remaining directory check merely picks the anchor path for the two
    # distros that share update-ca-certificates (openSUSE reads
    # /etc/pki/trust/anchors, Debian/Alpine /usr/local/share/ca-certificates).
    #
    # State-changing commands drop stdout only (their success chatter fires on
    # every container start) but keep stderr: a failed trust install must be
    # visible in `docker logs`, otherwise it surfaces later as an opaque
    # certificate error. `command -v` probes keep 2>&1 — only the exit code
    # matters there. `|| true` keeps a failure from aborting container startup.
    ca_cert_src=/dde/mkcert-rootCA.crt

    if ! command -v update-ca-trust >/dev/null 2>&1 \
        && ! command -v update-ca-certificates >/dev/null 2>&1; then
        if command -v apk >/dev/null 2>&1; then
            apk add --no-cache ca-certificates >/dev/null || true
        elif command -v apt-get >/dev/null 2>&1; then
            apt-get update >/dev/null \
                && apt-get install -y --no-install-recommends ca-certificates >/dev/null || true
        fi
    fi

    if command -v update-ca-trust >/dev/null 2>&1; then
        # RHEL / Fedora / CentOS
        cp "$ca_cert_src" /etc/pki/ca-trust/source/anchors/mkcert-rootCA.crt
        update-ca-trust extract >/dev/null || true
    elif command -v update-ca-certificates >/dev/null 2>&1; then
        if [ -d /etc/pki/trust/anchors ]; then
            # openSUSE / SLES
            cp "$ca_cert_src" /etc/pki/trust/anchors/mkcert-rootCA.crt
        else
            # Debian / Ubuntu / Alpine
            mkdir -p /usr/local/share/ca-certificates
            cp "$ca_cert_src" /usr/local/share/ca-certificates/mkcert-rootCA.crt
        fi
        update-ca-certificates >/dev/null || true
    fi
}
