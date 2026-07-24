---
title: "SSH Agent"
---

# SSH Agent

dde forwards an SSH agent into project containers so tooling inside a container
(for example `git clone` over SSH) authenticates without copying private keys in.
The in-container contract is the same in every mode: the socket is at
`/tmp/ssh-agent/socket` and `SSH_AUTH_SOCK` points at it, so a container cannot
tell which mode is active.

## Agent mode

`ssh.agent` is a **machine-global** setting in `~/.dde/config.yml` — not
per-project, because the agent is a single global service shared by every project
on the host.

```yaml
ssh:
  agent:
    mode: host      # host (default) | managed
    source: ~       # unset | env (host SSH_AUTH_SOCK) | <socket path>
```

- `host` (**default**) — dde forwards your existing host agent (1Password,
  Bitwarden, a plain `ssh-agent`, or a hardware token such as a YubiKey) into
  containers and holds no keys itself.
- `managed` — dde runs its own `dde-ssh-agent` container,
  discovers private-key files (from `ssh.keys` or `~/.ssh`), and loads them.

`source` applies only in `host` mode:

- **unset / `env`** (default) — use the host `SSH_AUTH_SOCK`. On macOS this is
  the only option (see [How forwarding works](#how-forwarding-works)).
- **`<socket path>`** (**Linux only**) — an explicit socket, bind-mounted
  directly; use it when your agent's socket is not on `SSH_AUTH_SOCK`. It cannot
  work on macOS, where a bind-mounted host socket does not cross the Docker
  Desktop VM boundary (the container gets `Connection refused`).

dde ships no per-provider socket paths (they drift between versions): a Linux
password-manager user points `source` at the socket, a macOS user uses the
bridge.

## How forwarding works

Only the *host side* of the `/tmp/ssh-agent/socket` mount differs by mode:

- **`host`, macOS** — Docker Desktop's host-services bridge
  `/run/host-services/ssh-auth.sock`, which forwards whatever the host
  `SSH_AUTH_SOCK` points at (OrbStack exposes the same socket). This is Docker's
  standard mechanism — see the
  [Docker docs](https://docs.docker.com/desktop/features/networking/networking-how-tos/#ssh-agent-forwarding).
  dde cannot stat the bridge (it lives inside the VM), so `system:doctor`
  verifies it instead; an explicit `source` path cannot work here.
- **`host`, Linux** — the resolved socket (`SSH_AUTH_SOCK`, or the explicit
  `source` path) bind-mounted directly.
- **`managed`** — dde's `dde-ssh-agent` container, via the named volume
  `dde_ssh-agent_socket-dir` (`dde_ssh-agent_socket-dir:/tmp/ssh-agent:ro`).

In `host` mode the socket is mounted **read-write**: Docker Desktop lands it as
`root:root`, so the entrypoint chowns it to the `dde` user while still running as
root (a `:ro` mount would forbid that, and `:ro` protects nothing on a socket).

If no host agent can be resolved, `host` mode brings the project up **without**
forwarding rather than aborting — no `SSH_AUTH_SOCK`, no mount — and SSH tooling
then fails on its own. Linux warns at bring-up; macOS surfaces it via the doctor.

## Password-manager agents (1Password, Bitwarden)

Enable the agent integration on the host first, otherwise forwarding has nothing
to talk to:

- **1Password** → Settings → Developer → *Use the SSH agent* (keys must be
  marked usable by the agent).
- **Bitwarden** → Settings → *SSH agent* → enable.

Then set `source`:

- **macOS** — use the bridge: leave `source` unset (or `env`) and make sure
  Docker Desktop sees your `SSH_AUTH_SOCK` (see the
  [macOS caveat](#macos-host-environment-caveat)).
- **Linux, 1Password** — point at the documented socket:

  ```yaml
  ssh:
    agent:
      mode: host
      source: ~/.1password/agent.sock
  ```

- **Linux, Bitwarden** — the socket path is version-variable, so leave `source`
  unset and let Bitwarden set `SSH_AUTH_SOCK`, or pin the current path explicitly.

## Doctor checks

`dde system:doctor` checks the prerequisite per mode:

- **`host`, Linux** — the resolved socket exists and is a live socket (the
  reliable "agent responds" signal short of running `ssh-add`); on a miss it
  tells you to start your agent and point `SSH_AUTH_SOCK` at it.
- **`host`, macOS** — the host `SSH_AUTH_SOCK` is visible to the Docker Desktop /
  OrbStack runtime (see the caveat below). An explicit `source` path is reported
  as an **error**, since it cannot work on macOS.
- **`managed`** — the `dde-ssh-agent` container is running (fix: `dde system:up`).

### macOS host-environment caveat

The bridge forwards the `SSH_AUTH_SOCK` that the **Docker Desktop runtime** sees,
and Docker Desktop runs under launchd, which does **not** inherit your shell
environment. So a `SSH_AUTH_SOCK` that is set in your terminal can still be
invisible to Docker Desktop, silently disabling forwarding — the first symptom is
a failed `git clone` inside a container. (OrbStack usually picks up your shell
environment and needs no such step.)

Check the launchd session and, if empty, set it and restart Docker Desktop:

```bash
launchctl getenv SSH_AUTH_SOCK              # empty = not bridged
launchctl setenv SSH_AUTH_SOCK "$SSH_AUTH_SOCK"
```

## Troubleshooting `host` mode

The doctor confirms the socket exists and is live but never runs `ssh-add`, so
two states pass green yet still fail inside the container:

| Symptom in the container                        | Cause                                                                                                                                              | Fix                                                                                    |
| ----------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------- |
| `Permission denied (publickey)`                 | Agent has no usable identities — the vault is **locked** or its SSH integration is **off**                                                         | Unlock the vault and enable the integration, then retry (no re-up needed).             |
| `Error connecting to agent: Connection refused` | The host agent **restarted** since bring-up (Docker Desktop relaunched, Mac woke from sleep, vault re-locked); the bind-mounted socket is now dead | `dde down && dde up` — the mount captures the socket at bring-up and does not refresh. |
| `The agent has no identities`                   | Agent reachable but empty                                                                                                                          | Add/unlock keys in your agent (or in `managed` mode run `dde system:up`).              |

Because the socket is bind-mounted at bring-up, restarting the agent leaves
running containers on a dead socket while the doctor still reports green — the
`Connection refused` symptom, not the doctor, is your signal to re-up.

## Hardware tokens (YubiKey, FIDO2)

A hardware-token key needs no special handling — dde forwards the *socket*, never
the key. Set the token up on the host and use the default `source` (`env` /
unset), or on Linux an explicit socket path:

```yaml
ssh:
  agent:
    mode: host
    source: env   # your SSH_AUTH_SOCK (ssh-agent / gpg-agent)
```

- **FIDO2 / `sk-` keys** (`ssh-ed25519-sk`, recommended): `ssh-keygen -t
  ed25519-sk`, held by the normal `ssh-agent` — no PKCS#11 or gpg-agent needed.
- **PIV / PGP**: load via `ssh-add -s <pkcs11.so>`, or run `gpg-agent` with
  `enable-ssh-support`; either way `SSH_AUTH_SOCK` ends up pointing at the agent.

The key material never leaves the token — a container can only ask it to sign,
and whether a sign succeeds depends on the touch policy:

| Key / policy                              | Effect in the container                                                                                                |
| ----------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| `sk-` key, or PIV/PGP with touch required | Each signature needs a physical tap; a container can request one but cannot complete it unattended. Strongest posture. |
| PIV without a touch policy                | Signs silently while the token is plugged in — any socket access can sign.                                             |

Require touch (`ssh-keygen -t ed25519-sk -O verify-required`, or a PIV slot with
`--touch-policy=always`): a forwarded socket then at most raises a touch prompt on
the **host**, which you can decline. The stale-socket caveat applies here too —
restart the agent (or replug the token) and you must re-up.

## Security note — the forwarded agent is exposed to project code

In `host` mode every project container can list your identities and ask the agent
to sign with them while it runs — the same trust model as `ssh -A` agent
forwarding and as the `managed` agent. For projects whose container code you do
not trust, switch to `managed` mode with a restricted `ssh.keys` list — the
container then only ever sees the keys dde loads, not your personal agent.
