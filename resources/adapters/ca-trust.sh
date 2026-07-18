detect() {
    [ -f /dde/mkcert-rootCA.crt ]
}

configure() {
    if [ -d /usr/local/share/ca-certificates ]; then
        # Debian / Ubuntu
        cp /dde/mkcert-rootCA.crt /usr/local/share/ca-certificates/mkcert-rootCA.crt
        update-ca-certificates >/dev/null 2>&1 || true
    elif [ -d /usr/share/pki/trust/anchors ]; then
        # openSUSE / SLES
        cp /dde/mkcert-rootCA.crt /usr/share/pki/trust/anchors/mkcert-rootCA.crt
        update-ca-certificates >/dev/null 2>&1 || true
    elif [ -d /etc/pki/ca-trust/source/anchors ]; then
        # RHEL / Fedora / CentOS
        cp /dde/mkcert-rootCA.crt /etc/pki/ca-trust/source/anchors/mkcert-rootCA.crt
        update-ca-trust extract >/dev/null 2>&1 || true
    elif command -v apk >/dev/null 2>&1; then
        # Alpine — ca-certificates may not be installed yet
        apk add --no-cache ca-certificates >/dev/null 2>&1 || true
        cp /dde/mkcert-rootCA.crt /usr/local/share/ca-certificates/mkcert-rootCA.crt
        update-ca-certificates >/dev/null 2>&1 || true
    fi
}
