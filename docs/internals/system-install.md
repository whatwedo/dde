---
title: "system:install privilege handling"
---

`dde system:install` writes to root-owned host paths but is invoked **without** a
`sudo` prefix. Two mechanisms enforce that contract: the `PrivilegeEscalator`
util elevates only the operations that need it, and the `bin/console` root guard
rejects `sudo dde …` up-front. Both trace back to a production incident where a
sudo'ed install left `$DDE_DATA_DIR` owned by `root:root` and broke every
subsequent unprivileged invocation (#142), plus a macOS v1→v2 upgrade failure on
a leftover resolver file (#205).

## Privileged host paths

| Platform                 | Operations                                                                         |
| ------------------------ | ---------------------------------------------------------------------------------- |
| Linux / systemd-resolved | `/etc/systemd/resolved.conf.d/dde-test.conf`, `systemctl restart systemd-resolved` |
| Linux / NetworkManager   | `/etc/NetworkManager/dnsmasq.d/dde-test.conf`, `systemctl restart NetworkManager`  |
| macOS                    | `/etc/resolver/test`                                                               |

All three run through `App\Service\DnsmasqService::configureDns*()` and are the
**only** escalated call sites. `DnsmasqService::ensureConfig()` (writes under
`$DDE_DATA_DIR`) deliberately stays on the bare `Filesystem`: those files must
remain owned by the invoking user, locked in by a negative regression test.
`mkcert -install` handles its own elevation and is untouched.

## `App\Util\PrivilegeEscalator`

Optimistic-then-sudo: each public method first attempts the operation as the
current user; only on failure does it retry exactly once through `sudo`. There
is no upfront capability probe — the optimistic path is the fast path.

- `ensureDir(string $path): void` — `Filesystem::mkdir()`, then `sudo mkdir -p`.
- `writeFile(string $path, string $content, string $mode = '0644'): void` —
  `Filesystem::dumpFile()` + `chmod()`, then tempfile + `sudo install -m`. The
  tempfile keeps the payload out of the elevated argv; `install` applies the
  final mode atomically.
- `run(array $command): void` — direct process, then `sudo <command>`.

Behaviour details:

- **Announcement:** before a sudo retry, one line goes to the configured
  announcer (default STDERR): `<operation> requires root — you may be asked for
  your sudo password.` STDERR keeps `--output=json` stdout parseable.
- **TTY forwarding:** the sudo process gets `setTty(true)` only when
  `Process::isTtySupported()` and `stream_isatty(STDIN)` hold, so interactive
  password prompts reach the user while CI (passwordless sudo, no TTY) succeeds
  silently.
- **Already root:** when the effective UID is 0, no sudo retry is attempted —
  sudo is often absent on minimal container images — and the original failure is
  rethrown wrapped.
- **Final failure:** a `\RuntimeException` naming the operation and the sudo
  failure, with the original exception as `previous`.

## Root guard — `bin/console`

Directly after `declare(strict_types=1);`, before any `$_SERVER`/`$_ENV`
mutation and before the autoloader:

```php
if (function_exists('posix_geteuid') && posix_geteuid() === 0 && getenv('SUDO_USER') !== false) {
    fwrite(STDERR, "dde must not be run with sudo. It escalates internally where required.\n");
    exit(1);
}
```

EUID 0 **plus** a set `SUDO_USER` is the unique signature of `sudo dde …`.
Real-root sessions without `SUDO_USER` (containers, provisioning) pass through:
they have no unprivileged home directory to poison. There is no extracted
`RootGuard` class — one call site, two conditions, and it must run before the
kernel exists.

## v1 leftovers

dde v1 wrote `/etc/resolver/test` without a trailing newline. The idempotency
check in `DnsmasqService` compares **trimmed** content, so a content-equivalent
v1 file short-circuits the whole DNS step — upgrades never escalate.
