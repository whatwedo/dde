#!/usr/bin/env sh
# Do not us bash. Not all containers have bash installed
set -e

# Set vars
DDE_UID=$1
DDE_GID=$2

###############################################################################

commandExists() {
  command -v "$1" >/dev/null 2>&1
}

log() {
    tput -T xterm setaf 3
	echo $HOSTNAME: $1
	tput -T xterm sgr0
}

###############################################################################

log "Check if platform is supported"
if [ ! -f /etc/lsb-release ]; then
    log "Platform not supported for autoconfiguration"
    exit
fi

log "Check if script has already run"
if [ -f /etc/dde/firstboot ]; then
    log "Container already configured"
    exit
fi

log "Set firstboot flag"
mkdir -p /etc/dde
touch /etc/dde/firstboot

log "Installing dependencies"
apt-get update
apt-get install -qq wget sed bash-completion

log "Add dde user and group"
groupadd -g $DDE_GID -o dde
useradd -d /home/dde -u $DDE_UID -g $DDE_GID -c "dde" -s /bin/bash -N -o -m dde

log "Add dde user to www-data if exists"
if [ $(getent group www-data) ]; then
    usermod -a -G www-data dde
fi

log "Install gosu"
wget -q -O /usr/local/bin/gosu "https://github.com/tianon/gosu/releases/download/1.10/gosu-amd64"
chmod +x /usr/local/bin/gosu

log "Check if nginx is installed"
if commandExists nginx; then
    log "Configuring nginx"
    sed -i "s/user www-data;/user dde;/" /etc/nginx/nginx.conf
    nginx -s reload
else
    log "nginx is not installed"
fi

log "Configure PHP-FPM if installed"
if commandExists php; then
    PHP_VERSION=`php -v | cut -d " " -f 2 | cut -c 1-3 | head -n 1`
    PHP_PATH=/etc/php/$PHP_VERSION
    log "PHP $PHP_VERSION found"

    if [ -f /usr/sbin/php-fpm$PHP_VERSION ]; then
        log "Configuring PHP-FPM"
        sed -i "s/user = www-data/user = dde/" $PHP_PATH/fpm/pool.d/www.conf
        sed -i "s/group = www-data/group = dde/" $PHP_PATH/fpm/pool.d/www.conf
        sed -i "s/listen\.owner.*/listen.owner = dde/" $PHP_PATH/fpm/pool.d/www.conf
        sed -i "s/listen\.group.*/listen.group = dde/" $PHP_PATH/fpm/pool.d/www.conf
        sed -i "s/listen\.mode.*/listen.mode = 0666/" $PHP_PATH/fpm/pool.d/www.conf
        service php$PHP_VERSION-fpm reload
    else
        log "PHP-FPM is not installed"
    fi
fi
