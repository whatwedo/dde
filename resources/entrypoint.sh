#!/bin/sh
set -e

# dde runtime entrypoint
# Handles user creation, UID/GID remapping, shell detection, and service adapters
# Then execs the original entrypoint + CMD (passed as arguments)

DDE_UID="${DDE_UID:-1000}"
DDE_GID="${DDE_GID:-1000}"

# 1. Ensure dde group and user exist, then remap UID/GID
if ! id dde >/dev/null 2>&1; then
    # Create group: try dedicated group first, fall back to reusing existing GID
    if ! getent group dde >/dev/null 2>&1; then
        addgroup -g "$DDE_GID" dde 2>/dev/null || addgroup dde 2>/dev/null || true
    fi

    # Determine group name for user creation
    DDE_GROUP="dde"
    if ! getent group dde >/dev/null 2>&1; then
        DDE_GROUP=$(getent group "$DDE_GID" 2>/dev/null | cut -d: -f1)
        DDE_GROUP="${DDE_GROUP:-nogroup}"
    fi

    # Create user
    if command -v adduser >/dev/null 2>&1; then
        adduser -u "$DDE_UID" -G "$DDE_GROUP" -D -h /home/dde -s /bin/sh dde 2>/dev/null || true
    elif command -v useradd >/dev/null 2>&1; then
        useradd -u "$DDE_UID" -g "$DDE_GROUP" -m -d /home/dde -s /bin/sh dde 2>/dev/null || true
    fi

    # Fallback: if user still doesn't exist, create manually
    if ! id dde >/dev/null 2>&1; then
        echo "dde:x:${DDE_UID}:${DDE_GID}:dde:/home/dde:/bin/sh" >> /etc/passwd
        mkdir -p /home/dde
        chown "$DDE_UID:$DDE_GID" /home/dde
    fi
elif [ "$(id -u dde 2>/dev/null)" != "$DDE_UID" ]; then
    # User exists but UID/GID differs — remap
    if command -v usermod >/dev/null 2>&1; then
        groupmod -o -g "$DDE_GID" dde 2>/dev/null || true
        usermod -o -u "$DDE_UID" -g "$DDE_GID" dde 2>/dev/null || true
    else
        sed -i "s/^dde:x:[0-9]*:[0-9]*/dde:x:${DDE_UID}:${DDE_GID}/" /etc/passwd
        sed -i "s/^dde:x:[0-9]*/dde:x:${DDE_GID}/" /etc/group
    fi
    chown -R "$DDE_UID:$DDE_GID" /home/dde 2>/dev/null || true
fi

# 2. Shell detection
if [ -n "$DDE_SHELL" ]; then
    USER_SHELL="/bin/$DDE_SHELL"
elif [ -x /bin/zsh ]; then
    USER_SHELL="/bin/zsh"
elif [ -x /bin/bash ]; then
    USER_SHELL="/bin/bash"
else
    USER_SHELL="/bin/sh"
fi

# Set login shell for dde user
if command -v usermod >/dev/null 2>&1; then
    usermod -s "$USER_SHELL" dde 2>/dev/null || true
else
    sed -i "s|dde:.*:/home/dde:.*|dde:x:${DDE_UID}:${DDE_GID}:dde:/home/dde:${USER_SHELL}|" /etc/passwd 2>/dev/null || true
fi

# 3. Run built-in service adapters
DDE_ADAPTERS_DIR="${DDE_ADAPTERS_DIR:-/dde/adapters}"
if [ -d "$DDE_ADAPTERS_DIR" ]; then
    for adapter in "$DDE_ADAPTERS_DIR"/*.sh; do
        [ -f "$adapter" ] || continue
        . "$adapter"
        if type detect >/dev/null 2>&1 && detect; then
            configure || true
        fi
        # Clean up functions
        unset -f detect configure 2>/dev/null || true
    done
fi

# 3b. Run project-specific adapters
if [ -d /dde/adapters-project ]; then
    for adapter in /dde/adapters-project/*.sh; do
        [ -f "$adapter" ] || continue
        . "$adapter"
        if type detect >/dev/null 2>&1 && detect; then
            configure || true
        fi
        unset -f detect configure 2>/dev/null || true
    done
fi

# 4. Exec the original entrypoint + CMD (passed as $@)
# The compose override sets command to [original_entrypoint, original_cmd...]
# so we just exec "$@" to chain to the original startup
exec "$@"
