detect() {
    command -v apache2 >/dev/null 2>&1 || command -v httpd >/dev/null 2>&1
}

configure() {
    # Debian/Ubuntu
    if [ -f /etc/apache2/envvars ]; then
        sed -i 's/^export APACHE_RUN_USER=.*/export APACHE_RUN_USER=dde/' /etc/apache2/envvars
        sed -i 's/^export APACHE_RUN_GROUP=.*/export APACHE_RUN_GROUP=dde/' /etc/apache2/envvars
    fi
    # Alpine/CentOS
    if [ -f /etc/httpd/conf/httpd.conf ]; then
        sed -i 's/^User .*/User dde/' /etc/httpd/conf/httpd.conf
        sed -i 's/^Group .*/Group dde/' /etc/httpd/conf/httpd.conf
    fi
}
