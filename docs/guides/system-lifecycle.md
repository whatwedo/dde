---
title: "System Lifecycle"
---

# System Lifecycle

dde provides five `system:*` commands. This guide explains when each one
applies.

## stop vs. down — preserve or clean up

`system:stop` halts every dde container (global services and versioned
service containers) but does not remove them. A subsequent `system:up`
starts the same containers again — **without** `docker run` having to
create new ones.

`system:down` stops the containers **and removes** them. `system:up` must
then create fresh containers from the images.

Rule of thumb: if you just want to put your laptop aside and resume later,
use `system:stop`. If you want to clean up (or the containers are broken),
use `system:down`.

## install vs. update — one-off vs. per-version

`system:install` is the one-off setup step:

- Install the mkcert root CA (requires sudo / admin trust).
- Write the DNS resolver file (`/etc/resolver/test` on macOS, systemd-resolved
  or NetworkManager configuration on Linux).
- Create the default wildcard certificate for `*.test`.
- Build and start the global services.
- Install shell completion.
- Install the Claude skill (when Claude Code is detected).

You call `system:install` **once**, the first time you use dde. For package
manager installations (APT / Arch / Alpine / RPM) it runs automatically via
the post-install hook. On Homebrew it shows up as a caveats message.

`system:update` is the refresh step for a new dde release:

- Remove all dde containers.
- Rebuild the global service images with `docker build --pull` (picks up the
  latest upstream base image).
- Start the global services again.
- Refresh everything that is bound to the dde binary version
  (shell completion, Claude skill).

`system:update` does **not** touch the root CA, DNS configuration, or the
default certificate — those are one-off setup steps, not per-version
refresh steps. Package manager upgrades run `system:update` automatically.

## Privilege handling

`dde system:install` is invoked **without** `sudo`. That is the contract,
and `sudo dde …` is rejected up-front: the entrypoint exits with code 1 and
the message `dde must not be run with sudo. It escalates internally where
required.` Running dde under `sudo` would leave files in `$DDE_DATA_DIR`
(default `~/.dde/data`) owned by `root:root`, so every subsequent
unprivileged invocation — `dde project:up`, `dde system:update`, anything —
would fail to write into the same directory. A prior incident produced
exactly that breakage; the guard exists so it cannot recur.

For the few host-level Linux paths that genuinely require root
(`/etc/systemd/resolved.conf.d/`, `/etc/NetworkManager/dnsmasq.d/`), dde
escalates internally. Each write is attempted first as the current user; only
on a permission error does dde retry the same operation through `sudo`. Files
under `$DDE_CONFIG_DIR` and `$DDE_DATA_DIR` are never escalated — they stay
owned by the invoking user. On macOS the `/etc/resolver/test` write is not
escalated; when it needs root, dde prints the exact `sudo tee` command to run
manually.

Behaviour by environment:

- **Interactive shell, no passwordless sudo.** dde forwards the `sudo`
  password prompt to your TTY when an `/etc/**` write needs elevation. You
  type your password once and the install continues.
- **CI runner with passwordless sudo, no TTY.** dde escalates silently and
  finishes without user interaction.
- **No TTY and no passwordless sudo.** dde fails loudly with a non-zero
  exit code; stderr names the operation that needed root.

For the implementation contract (the `PrivilegeEscalator` API, the
`bin/console` root-guard predicate, and the failure modes), see
[system:install internals](../internals/system-install.md).

## Quick reference

| Situation                                    | Command            |
|----------------------------------------------|--------------------|
| Closing the laptop, resume later             | `system:stop`      |
| Tearing down, cleaning up                    | `system:down`      |
| After first-time installation                | `system:install`   |
| After `brew upgrade dde` or similar          | `system:update`    |
| Bring services back up (after stop or down)  | `system:up`        |

See also: [Commands](../getting-started/commands.md), [Installation](../getting-started/installation.md).
