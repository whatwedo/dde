# Detection keys off the pool config that configure rewrites, not the binary
# name, which is unreliable (Debian ships it as php-fpm8.4, not php-fpm).
detect() {
    php_root="${DDE_PHP_FPM_POOL_ROOT:-/etc/php}"
    # Layouts: Debian (/etc/php/<ver>/fpm/pool.d), Alpine / whatwedo base image
    # (/etc/php<ver>/php-fpm.d), Docker-official (/usr/local/etc/php-fpm.d).
    for conf in "$php_root"/*/fpm/pool.d/www.conf "$php_root"*/php-fpm.d/www.conf /usr/local/etc/php-fpm.d/www.conf; do
        [ -f "$conf" ] && return 0
    done
    return 1
}

configure() {
    dde_user="${DDE_USER_NAME:-dde}"
    # Resolve the actual primary group (see nginx adapter for the gid-reuse case).
    dde_group="$(id -gn "$dde_user" 2>/dev/null || echo "$dde_user")"

    php_root="${DDE_PHP_FPM_POOL_ROOT:-/etc/php}"
    # Layouts: Debian (/etc/php/<ver>/fpm/pool.d), Alpine / whatwedo base image
    # (/etc/php<ver>/php-fpm.d), Docker-official (/usr/local/etc/php-fpm.d).
    for conf in "$php_root"/*/fpm/pool.d/www.conf "$php_root"*/php-fpm.d/www.conf /usr/local/etc/php-fpm.d/www.conf; do
        [ -f "$conf" ] || continue
        sed -i "s/^user = .*/user = ${dde_user}/" "$conf"
        sed -i "s/^group = .*/group = ${dde_group}/" "$conf"
        sed -i "s/^listen.owner = .*/listen.owner = ${dde_user}/" "$conf"
        sed -i "s/^listen.group = .*/listen.group = ${dde_group}/" "$conf"
    done
}
