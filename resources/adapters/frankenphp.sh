# FrankenPHP has no run-as-user directive like nginx or php-fpm. The image runs
# runit as its unprivileged user, dde starts the container as root, so the runit
# run script has to drop to the dde user itself via chpst. HOME is set explicitly
# because chpst keeps root's environment.
detect() {
    command -v frankenphp >/dev/null 2>&1 && command -v chpst >/dev/null 2>&1
}

configure() {
    dde_user="${DDE_USER_NAME:-dde}"
    # Resolve the actual primary group (see nginx adapter for the gid-reuse case).
    dde_group="$(id -gn "$dde_user" 2>/dev/null || echo "$dde_user")"

    # Caddy state (XDG_CONFIG_HOME/XDG_DATA_HOME), prepared for the image's own user.
    chown -R "${dde_user}:${dde_group}" /var/lib/frankenphp 2>/dev/null || true

    for run in /etc/runit/runsvdir/default/*/run; do
        grep -q 'frankenphp run' "$run" 2>/dev/null || continue
        grep -q 'chpst' "$run" && continue
        sed -i "s|^\([[:space:]]*exec[[:space:]][[:space:]]*\)\(.*frankenphp run\)|\1chpst -u ${dde_user} env HOME=/home/${dde_user} \2|" "$run"
    done
}
