detect() {
    command -v nginx >/dev/null 2>&1
}

configure() {
    dde_user="${DDE_USER_NAME:-dde}"
    # Resolve the actual primary group: when DDE_GID maps onto a pre-existing
    # group (e.g. gid 20), the entrypoint reuses that group instead of creating
    # one named `dde`, so hardcoding `dde` here would reference a missing group.
    dde_group="$(id -gn "$dde_user" 2>/dev/null || echo "$dde_user")"

    # The `user` directive must sit in the main context. It may live in
    # nginx.conf or in an included main-context file — the whatwedo base image
    # ships it as /etc/nginx/directive.d/05-user.conf. Replace it wherever it is.
    nginx_root="${DDE_NGINX_CONF_ROOT:-/etc/nginx}"
    for conf in "$nginx_root"/nginx.conf "$nginx_root"/directive.d/*.conf; do
        [ -f "$conf" ] || continue
        sed -i "s/^[[:space:]]*user[[:space:]].*/user ${dde_user} ${dde_group};/" "$conf"
    done

    # Fix cache/log directories
    for dir in /var/cache/nginx /var/log/nginx /var/run; do
        [ -d "$dir" ] && chown -R "${dde_user}:${dde_group}" "$dir" 2>/dev/null || true
    done
}
