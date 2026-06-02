#!/bin/sh
set -e

DDE_UID="${DDE_UID:-1000}"
DDE_GID="${DDE_GID:-1000}"

groupadd -g "$DDE_GID" dde 2>/dev/null || true
useradd -m -u "$DDE_UID" -g "$DDE_GID" -d /home/dde -s /bin/sh dde 2>/dev/null || true
chown "$DDE_UID:$DDE_GID" /home/dde /workspace 2>/dev/null || true

exec gosu "$DDE_UID:$DDE_GID" "$@"
