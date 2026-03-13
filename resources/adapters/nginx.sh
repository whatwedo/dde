detect() {
    command -v nginx >/dev/null 2>&1
}

configure() {
    # Update nginx.conf user directive
    if [ -f /etc/nginx/nginx.conf ]; then
        sed -i 's/^user .*/user dde;/' /etc/nginx/nginx.conf
    fi
    # Fix cache/log directories
    for dir in /var/cache/nginx /var/log/nginx /var/run; do
        [ -d "$dir" ] && chown -R dde:dde "$dir" 2>/dev/null || true
    done
}
