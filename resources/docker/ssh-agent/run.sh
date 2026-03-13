#!/bin/sh
set -e

# Create dde user with host UID/GID
DDE_UID=${DDE_UID:-1000}
DDE_GID=${DDE_GID:-1000}

# Create group — if GID is taken, use existing group
if ! getent group "$DDE_GID" >/dev/null 2>&1; then
    addgroup -g "$DDE_GID" dde 2>/dev/null || true
fi
DDE_GROUP=$(getent group "$DDE_GID" | cut -d: -f1)

# Create user with the resolved group
if ! getent passwd "$DDE_UID" >/dev/null 2>&1; then
    adduser -D -u "$DDE_UID" -G "$DDE_GROUP" -h /home/dde dde 2>/dev/null || true
fi
DDE_USER=$(getent passwd "$DDE_UID" | cut -d: -f1)

# Ensure socket directory and clean stale socket
mkdir -p "$SOCKET_DIR"
rm -f "$SSH_AUTH_SOCK"
chown "$DDE_UID:$DDE_GID" "$SOCKET_DIR"

# Start ssh-agent in foreground as dde user
exec su-exec "$DDE_USER" ssh-agent -D -a "$SSH_AUTH_SOCK"
