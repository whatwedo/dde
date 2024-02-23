#!/usr/bin/env sh
# Use a generic shell instead of bash for compatibility across different containers

set -ex
# -e: Exit immediately if a command exits with a non-zero status
# -x: Print commands and their arguments as they are executed

# Check if necessary environment variables are set
[ -z "$DDE_UID" ] && echo "DDE_UID is not set" && exit 1
[ -z "$DDE_GID" ] && echo "DDE_GID is not set" && exit 1

# Function to check if a command exists in the PATH
commandExists() {
  command -v "$1" >/dev/null 2>&1
}

DEFAULT_SHELL="sh"
# Set default shell to /bin/sh, can be overridden by setting DDE_SHELL
SHELL_TO_INSTALL="/bin/${DDE_CONTAINER_SHELL:-$DEFAULT_SHELL}"
# Determine the package manager to use based on the available commands
if commandExists apt-get; then
    PACKAGE_MANAGER="apt-get"
elif commandExists apk; then
    PACKAGE_MANAGER="apk"
else
    echo "Not supported package manager"
    exit 1
fi

# Determine additional dependencies based on the value of DDE_CONTAINER_SHELL
if [ "$DDE_CONTAINER_SHELL" = "zsh" ]; then
    ADDITIONAL_DEPS="$DDE_CONTAINER_SHELL"
else
    ADDITIONAL_DEPS=""
fi

# Install dependencies using the determined package manager
case "$PACKAGE_MANAGER" in
    apt-get)
        # Update package lists
        apt-get update
        # Install curl and any additional dependencies, if specified
        apt-get install -qq curl $ADDITIONAL_DEPS
        ;;
    apk)
        # Add curl and shadow, along with any additional dependencies, if specified
        apk add --update-cache --upgrade --virtual .temp-dde-deps curl shadow $ADDITIONAL_DEPS
        ;;
esac

# Install gosu if not already installed
if [ ! -x /usr/local/bin/gosu ]; then
    curl -L https://github.com/tianon/gosu/releases/download/1.11/gosu-amd64 -o /usr/local/bin/gosu
    chmod +x /usr/local/bin/gosu
    gosu --version
fi

# Create a group and user for dde with specified IDs
groupadd -g $DDE_GID -o dde
useradd -d /home/dde -u $DDE_UID -g $DDE_GID -c "dde" -s "$SHELL_TO_INSTALL" -N -o -m dde -r

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

# Cleanup installed packages and temporary files
case "$PACKAGE_MANAGER" in
    apt-get)
        rm -rf /var/lib/apt/lists/*
        ;;
    apk)
        rm -rf /var/cache/apk/*
        ;;
esac
rm -- "$0"  # Remove the script itself after execution
