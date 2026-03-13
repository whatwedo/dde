detect() {
    command -v php-fpm >/dev/null 2>&1
}

configure() {
    # Find and update www.conf pool configuration
    for conf in /etc/php/*/fpm/pool.d/www.conf /usr/local/etc/php-fpm.d/www.conf /etc/php*/fpm/pool.d/www.conf; do
        [ -f "$conf" ] || continue
        sed -i 's/^user = .*/user = dde/' "$conf"
        sed -i 's/^group = .*/group = dde/' "$conf"
        sed -i 's/^listen.owner = .*/listen.owner = dde/' "$conf"
        sed -i 's/^listen.group = .*/listen.group = dde/' "$conf"
    done
}
