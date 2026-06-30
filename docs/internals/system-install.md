---
title: "system:install Privilege Escalation"
---


`dde system:install` writes to root-owned Linux paths (`/etc/systemd/resolved.conf.d/`,
`/etc/NetworkManager/`) but is invoked **without** a `sudo` prefix. Two mechanisms enforce that
contract: the `PrivilegeEscalator` util elevates only the writes that need it, and the
`bin/console` root-guard rejects `sudo dde …` up-front.

The macOS `/etc/resolver/test` path is handled separately in `configureDnsMacOs()`, which writes
through a bare `Filesystem` and, when the file genuinely has to be created without root, prints the
exact `sudo tee` command for the user to run. It deliberately does **not** route through the
escalator — see `DnsmasqService::configureDnsMacOs()`.

Both surfaced from a single production incident on `sbaerlocher/savvy` PR #102 (CI Run 25183781831
and Run 25138709404), where running dde under `sudo` left `$DDE_DATA_DIR` owned by `root:root` and
broke every subsequent unprivileged invocation.

## 1. Privilege escalation contract — `PrivilegeEscalator`

`src/Util/PrivilegeEscalator.php` implements an
**optimistic-then-sudo** pattern: each public method first attempts the operation as the current
user; on a permission failure it retries the operation exactly once through `sudo`. There is no
upfront capability probe — the optimistic path is the fast path, and the sudo retry only fires when
the OS rejects the direct attempt.

### Methods

- `ensureDir(string $path): void` — `Filesystem::mkdir`, then `sudo mkdir -p $path`.
- `writeFile(string $path, string $content, string $mode = '0644'): void` — `Filesystem::dumpFile`
  followed by `Filesystem::chmod`, then tempfile + `sudo install -m $mode tmp $path`. The tempfile
  keeps the literal payload off the elevated argv.
- `run(array $command): void` — `ProcessFactory::create($command)->run()`, then `sudo $command`.

### TTY behaviour

`runWithSudo()` calls `Process::setTty(true)` only when
`Process::isTtySupported() && defined('STDIN') && stream_isatty(STDIN)` all hold. In a CI runner
without a TTY but with passwordless sudo the call still succeeds (no password prompt is needed). In
an interactive shell the TTY is forwarded so the user sees and answers the prompt.

### Failure modes

If both attempts fail, the original `ProcessFailedException` is wrapped in a `\RuntimeException`
whose message names the failed operation and the root-privilege requirement (e.g.
`mkdir /etc/systemd/resolved.conf.d requires root privileges; sudo escalation also failed.`). The original
exception is preserved as `previous`.

### Where it is used

The two Linux `configureDns*` methods of
`App\Service\DnsmasqService` (`src/Service/DnsmasqService.php`) — `configureDnsSystemdResolved()`
and `configureDnsNetworkManager()` — route every `/etc/**` write through the escalator.
`DnsmasqService::ensureConfig()`, which writes under `$DDE_DATA_DIR`,
deliberately does **not** — those files belong to the invoking user and must stay user-owned so
subsequent unprivileged invocations can still read and rewrite them. Routing them through sudo
would reproduce the original incident.

## 2. Root-guard — `bin/console`

`bin/console` (lines 10–17) rejects any invocation where dde itself was started
under `sudo`:

```php
if (
    function_exists('posix_geteuid')
    && posix_geteuid() === 0
    && getenv('SUDO_USER') !== false
) {
    fwrite(STDERR, "dde must not be run with sudo. It escalates internally where required.\n");
    exit(1);
}
```

The guard sits between `declare(strict_types=1);` and the PHAR-detection block. It runs before any
`$_SERVER`/`$_ENV` mutation, before the Symfony Runtime autoload, and before kernel boot — so no
filesystem or environment state has been touched as root by the time the process exits.

### Why both conditions are required

The predicate requires **both** `posix_geteuid() === 0` **and** `getenv('SUDO_USER') !== false`.
Real-root sessions without `SUDO_USER` (containers, provisioning systems) legitimately run dde as
root and must pass through. The combination of EUID 0 plus a set `SUDO_USER` is the unique
signature of `sudo dde …` on a multi-user system — the only case the guard rejects.

### Why no `RootGuard` utility class

There is exactly one call site (the entrypoint, before the kernel exists), the predicate is two
function calls, and the reject path is a single `fwrite` + `exit(1)`. Extracting a class would mean
booting the autoloader to reach it, defeating the point of running before kernel boot. YAGNI.
