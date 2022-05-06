#!/usr/bin/env sh
# Do not us bash. Not all containers have bash installed
set -ex

# Check configuration
[ -z "$DDE_UID" ] && echo "DDE_UID is not set" && exit 1;
[ -z "$DDE_GID" ] && echo "DDE_GID is not set" && exit 1;

###############################################################################

commandExists() {
  command -v "$1" >/dev/null 2>&1
}

###############################################################################

# Check plaform
if commandExists apt-get; then
    PACKAGE_MANAGER="apt-get"
elif commandExists apk; then
    PACKAGE_MANAGER="apk"
else
    echo "Not supported package manager"
    exit 1
fi

# Install package manager dependencies
case "$PACKAGE_MANAGER" in
        apt-get)
            apt-get update
            apt-get install -qq curl
            ;;
        apk)
            apk add --update-cache --upgrade --virtual .temp-dde-deps curl shadow
esac

# Install gosu
if [ ! -x /usr/local/bin/gosu ]; then
    curl -L https://github.com/tianon/gosu/releases/download/1.11/gosu-amd64 -o /usr/local/bin/gosu
    chmod +x /usr/local/bin/gosu
    gosu --version
fi

# Add ddde user and group
groupadd -g $DDE_GID -o dde || true
useradd -d /home/dde -u $DDE_UID -g $DDE_GID -c "dde" -s /bin/sb -N -o -m dde -r || true

# Add dde user to www-data if exists
if [ $(getent group www-data) ]; then
    usermod -a -G www-data dde
fi

# Add dde user to nginx if exists
if [ $(getent group nginx) ]; then
    usermod -a -G nginx dde
fi

# Configure nginx
if [ -d /etc/nginx ]; then
    find /etc/nginx -type f -name *.conf -print0 | xargs -r -0 sed -i "s/user .*;/user dde;/"
fi
if [ -d /var/cache/nginx ]; then
    chown -R dde:dde /var/cache/nginx
fi
if [ -d /var/tmp/nginx ]; then
    chown -R dde:dde /var/tmp/nginx
fi
if [ -d /var/lib/nginx/tmp ]; then
    chown -R dde:dde /var/lib/nginx/tmp
fi
if [ -d /var/www ]; then
    chown -R dde:dde /var/www
fi

# Configure PHP
phpConfigFiles=$(ls /etc/php* 2> /dev/null | wc -l)
if [ "$phpConfigFiles" != "0" ]; then
    find /etc/php* -type f -name www.conf -print0 | xargs -r -0 sed -i "s/user = .*/user = dde/"
    find /etc/php* -type f -name www.conf -print0 | xargs -r -0 sed -i "s/group = .*/group = dde/"
    find /etc/php* -type f -name www.conf -print0 | xargs -r -0 sed -i "s/listen\.owner.*/listen.owner = dde/"
    find /etc/php* -type f -name www.conf -print0 | xargs -r -0 sed -i "s/listen\.group.*/listen.group = dde/"
    find /etc/php* -type f -name www.conf -print0 | xargs -r -0 sed -i "s/listen\.mode.*/listen.mode = 0666/"
fi

# Cleanup
case "$PACKAGE_MANAGER" in
        apt-get)
            rm -rf /var/lib/apt/lists/*
            ;;
        apk)
            apk del --no-cache .temp-dde-deps
esac
rm -- "$0"
