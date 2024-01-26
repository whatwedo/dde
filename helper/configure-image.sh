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

DEFAULT_SHELL="sb"
# Set default shell to /bin/sb, can be overridden by setting DDE_SHELL
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

# Install dependencies using the determined package manager
case "$PACKAGE_MANAGER" in
    apt-get)
        apt-get update
        apt-get install -qq curl
        [ "$SHELL_TO_INSTALL" = "/bin/zsh" ] && apt-get install -y zsh
        ;;
    apk)
        apk add --update-cache --upgrade --virtual .temp-dde-deps curl shadow
        [ "$SHELL_TO_INSTALL" = "/bin/zsh" ] && apk add zsh
        ;;
esac

# Install gosu if not already installed
if [ ! -x /usr/local/bin/gosu ]; then
    curl -L https://github.com/tianon/gosu/releases/download/1.11/gosu-amd64 -o /usr/local/bin/gosu
    chmod +x /usr/local/bin/gosu
    gosu --version
fi

# Create a group and user for dde with specified IDs
groupadd -g $DDE_GID -o dde || true
useradd -d /home/dde -u $DDE_UID -g $DDE_GID -c "dde" -s "$SHELL_TO_INSTALL" -N -o -m dde -r || true

# Install oh-my-zsh for the dde user if zsh is the selected shell
if [ "$SHELL_TO_INSTALL" = "/bin/zsh" ] && which zsh > /dev/null 2>&1; then
    doas -u dde sh -c "$(curl -fsSL https://raw.github.com/ohmyzsh/ohmyzsh/master/tools/install.sh)"
fi

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
        apk del --no-cache .temp-dde-deps
        ;;
esac
rm -- "$0"  # Remove the script itself after execution
