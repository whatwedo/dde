#!/bin/sh

# Exit on error
set -e

# Cleanup old sockets
rm -f $SSH_AUTH_SOCK

# Add dde user/group
groupadd -g $DDE_GID -o dde
useradd -d /home/dde -u $DDE_UID -g $DDE_GID -c "dde" -s /bin/bash -N -o -m dde

# Create socket dir
mkdir $SOCKET_DIR && chown -R dde:dde $SOCKET_DIR

# Start ssh agent
gosu dde sh -c "ssh-agent -a $SSH_AUTH_SOCK -D"
