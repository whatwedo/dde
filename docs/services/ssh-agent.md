---
title: "SSH-Agent"
---


The SSH-Agent service shares SSH keys from the host into project containers, enabling git operations, SSH connections, and other key-based authentication inside containers.

The service container runs only in `managed` agent mode (`ssh.agent.mode: managed` in `~/.dde/config.yml`). By default (`host` mode) dde forwards your existing host SSH agent into project containers and runs no agent container — see the [SSH agent guide](../guides/ssh-agent.md).

## How It Works

1. The SSH-Agent container runs an `ssh-agent` process.
2. Private keys from the host are mounted read-only into the container.
3. The agent socket is shared with project containers via a Docker volume.

## Key Detection

dde automatically scans `~/.ssh/` for private key files. It includes files that:

- Contain the string `PRIVATE KEY` in their content
- Are at the root level of `~/.ssh/` (not in subdirectories)

It excludes: `*.pub`, `known_hosts`, `known_hosts.old`, `config`, `authorized_keys`.

### Configured Keys

Keys can be explicitly configured in the global config (`ssh.keys`). When configured keys are present, automatic detection is skipped and only the configured keys are mounted.

## Using SSH in Containers

Once the SSH-Agent is running, SSH operations inside containers work transparently:

```bash
# Inside a project container
git clone git@github.com:org/repo.git
ssh user@server.example.com
```

The `SSH_AUTH_SOCK` environment variable points to the shared agent socket automatically.
