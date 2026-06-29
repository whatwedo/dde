#!/bin/sh
set -e

# dde runtime entrypoint
# Handles user creation, UID/GID remapping, shell detection, and service adapters
# Then execs the original entrypoint + CMD (passed as arguments)

DDE_UID="${DDE_UID:-1000}"
DDE_GID="${DDE_GID:-1000}"

# 1. Ensure dde group and user exist, then remap UID/GID
# Skip entirely when not running as root or when essential tools are missing (minimal images)
if command -v id >/dev/null 2>&1 && [ "$(id -u)" = "0" ]; then
    if ! id dde >/dev/null 2>&1; then
        # Detect the user/group tool dialect ONCE, then fire exactly one command
        # whose exit code is a real success/failure signal. `adduser`/`addgroup`
        # is the same command name but two different programs: BusyBox (Alpine,
        # short flags -u -G -D) and Debian's Perl adduser (long flags --uid
        # --ingroup --disabled-password) — only --help tells them apart, both exit
        # 0. shadow's useradd/groupadd take identical flags on every distro, so
        # prefer them; they are absent only on base Alpine. The old code fed
        # BusyBox flags to whatever `adduser` resolved to, so on Debian it dumped
        # the adduser usage text into the container log and left the user uncreated.
        if command -v useradd >/dev/null 2>&1; then
            DIALECT="shadow"
        elif command -v adduser >/dev/null 2>&1 && adduser --help 2>&1 | grep -qi busybox; then
            DIALECT="busybox"
        elif command -v adduser >/dev/null 2>&1; then
            DIALECT="debian"
        else
            DIALECT="none"
        fi

        # Create group: dedicated group at DDE_GID, unless that GID is already taken
        # (then the user reuses the existing group below).
        if command -v getent >/dev/null 2>&1 \
            && ! getent group dde >/dev/null 2>&1 \
            && ! getent group "$DDE_GID" >/dev/null 2>&1; then
            case "$DIALECT" in
                shadow)  groupadd -g "$DDE_GID" dde ;;
                busybox) addgroup -g "$DDE_GID" dde ;;
                debian)  addgroup --gid "$DDE_GID" dde ;;
            esac || echo "dde-entrypoint: warning: could not create group dde (gid $DDE_GID)" >&2
        fi

        # Resolve the group name to hand to user creation: 'dde' if it exists,
        # otherwise whatever already owns DDE_GID, else nogroup.
        DDE_GROUP="dde"
        if command -v getent >/dev/null 2>&1 && ! getent group dde >/dev/null 2>&1; then
            DDE_GROUP=$(getent group "$DDE_GID" 2>/dev/null | cut -d: -f1)
            DDE_GROUP="${DDE_GROUP:-nogroup}"
        fi

        # Create user: exactly one command for the detected dialect.
        case "$DIALECT" in
            shadow)  useradd -u "$DDE_UID" -g "$DDE_GROUP" -m -d /home/dde -s /bin/sh dde ;;
            busybox) adduser -u "$DDE_UID" -G "$DDE_GROUP" -D -h /home/dde -s /bin/sh dde ;;
            debian)  adduser --uid "$DDE_UID" --ingroup "$DDE_GROUP" --disabled-password \
                         --home /home/dde --shell /bin/sh --gecos "" dde ;;
        esac || echo "dde-entrypoint: warning: user creation via $DIALECT failed" >&2

        # Last resort: no usable tool (DIALECT=none) or the tool genuinely failed.
        # A real fallback now, not a routine sibling of a broken command.
        if ! id dde >/dev/null 2>&1 && [ -w /etc/passwd ]; then
            echo "dde:x:${DDE_UID}:${DDE_GID}:dde:/home/dde:/bin/sh" >> /etc/passwd
            mkdir -p /home/dde 2>/dev/null || true
            chown "$DDE_UID:$DDE_GID" /home/dde 2>/dev/null || true
        fi
    elif [ "$(id -u dde 2>/dev/null)" != "$DDE_UID" ]; then
        # User exists but UID/GID differs — remap
        if command -v usermod >/dev/null 2>&1; then
            groupmod -o -g "$DDE_GID" dde 2>/dev/null || true
            usermod -o -u "$DDE_UID" -g "$DDE_GID" dde 2>/dev/null || true
        elif [ -w /etc/passwd ]; then
            sed -i "s/^dde:x:[0-9]*:[0-9]*/dde:x:${DDE_UID}:${DDE_GID}/" /etc/passwd 2>/dev/null || true
            sed -i "s/^dde:x:[0-9]*/dde:x:${DDE_GID}/" /etc/group 2>/dev/null || true
        fi
        chown -R "$DDE_UID:$DDE_GID" /home/dde 2>/dev/null || true
    fi

    # 2. Shell detection and login shell
    if id dde >/dev/null 2>&1; then
        if [ -n "$DDE_SHELL" ]; then
            USER_SHELL="/bin/$DDE_SHELL"
        elif [ -x /bin/zsh ]; then
            USER_SHELL="/bin/zsh"
        elif [ -x /bin/bash ]; then
            USER_SHELL="/bin/bash"
        else
            USER_SHELL="/bin/sh"
        fi

        if command -v usermod >/dev/null 2>&1; then
            usermod -s "$USER_SHELL" dde 2>/dev/null || true
        elif [ -w /etc/passwd ]; then
            sed -i "s|dde:.*:/home/dde:.*|dde:x:${DDE_UID}:${DDE_GID}:dde:/home/dde:${USER_SHELL}|" /etc/passwd 2>/dev/null || true
        fi
    fi
fi

# 2b. In host mode the bind-mounted agent socket must be usable by the
# unprivileged dde user: on Docker Desktop / OrbStack it lands as root:root (the
# socket lives in the VM), so chown it while still root. On a Linux host the bind
# mount shares the inode with the real host socket, so this chown also retargets
# its ownership — a no-op under the dde invariant DDE_UID = posix_getuid() (the
# user already owns their own agent socket). In managed mode /tmp/ssh-agent is
# mounted read-only, so the chown fails and is swallowed by "|| true"; harmless,
# because the managed agent already owns the socket.
if [ "$(id -u 2>/dev/null)" = "0" ] && [ -S /tmp/ssh-agent/socket ]; then
    chown "$DDE_UID:$DDE_GID" /tmp/ssh-agent/socket 2>/dev/null || true
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
