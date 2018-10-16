#!/usr/bin/env sh
# Do not us bash. Not all containers have bash installed
set -e

# Set vars
DDE_UID=$1
DDE_GID=$2

# Check if Ubuntu/Debian
if [ ! -f /etc/lsb-release ]; then
    echo Platform not supported for autoconfiguration
    exit
fi

# Check if script has already run
if [ -f /etc/dde/firstboot ]; then
    echo Container already configured
    exit
fi

# Set firstboot flag
mkdir -p /etc/dde
touch /etc/dde/firstboot

# Install dependencies
apt-get update
apt-get install -qq wget sed bash-completion

# Add dde user and group
groupadd -g $DDE_GID -o dde
useradd -d /home/dde -u $DDE_UID -g $DDE_GID -c "dde" -s /bin/bash -N -o -m dde

# Install gosu
wget -q -O /usr/local/bin/gosu "https://github.com/tianon/gosu/releases/download/1.10/gosu-amd64"
chmod +x /usr/local/bin/gosu

# Confiure nginx if installed
if [ -x `command -v nginx` ]; then
    usermod -a -G www-data dde
    sed -i "s/user www-data;/user dde;/" /etc/nginx/nginx.conf
    nginx -s reload
fi

# Configure PHP-FPM
if [ -x `command -v php` ]; then
    PHP_VERSION=`php -v | cut -d " " -f 2 | cut -c 1-3 | head -n 1`
    PHP_PATH=/etc/php/$PHP_VERSION

    if [ -f /usr/sbin/php-fpm$PHP_VERSION ]; then
        sed -i "s/user = www-data/user = dde/" $PHP_PATH/fpm/pool.d/www.conf
        sed -i "s/group = www-data/group = dde/" $PHP_PATH/fpm/pool.d/www.conf
        sed -i "s/listen\.owner.*/listen.owner = dde/" $PHP_PATH/fpm/pool.d/www.conf
        sed -i "s/listen\.group.*/listen.group = dde/" $PHP_PATH/fpm/pool.d/www.conf
        sed -i "s/listen\.mode.*/listen.mode = 0666/" $PHP_PATH/fpm/pool.d/www.conf;
    fi
fi
