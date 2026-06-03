---
title: "Guide: Claude Code"
---

`dde project:claude` (alias: `dde claude`) starts an isolated [Claude Code](https://claude.ai/code) container for the current project. Each project gets its own ephemeral container with the project directory mounted as the workspace. Your existing Claude Code credentials and settings are shared from the host, so no separate login is required after the first run.

## Prerequisites

The feature requires a Docker image named `dde-claude:local` to be available on your machine. Build it once from the bundled Dockerfile:

```bash
docker build -t dde-claude:local /path/to/dde/resources/docker/claude/
```

A pre-built image will be published to GHCR in a future release. Until then, build locally.

## Usage

```bash
cd ~/projects/my-app
dde project:claude    # or: dde claude
```

dde starts `docker run --rm -it` with:

- your project directory mounted at `/workspace` (the working directory)
- `~/.claude/` and `~/.claude.json` mounted from your host so credentials and settings are shared
- the project's Docker network attached automatically if `dde project:up` has been run

Claude Code opens directly. When you exit, the container is removed.

## Credentials and Settings

On first run Claude Code walks through its one-time setup (style preference and login). Both answers are written into the mounted `~/.claude/` and `~/.claude.json` on your host, so they persist across every future container run.

If `~/.claude.json` does not yet exist on the host, dde creates an empty one before starting the container so Docker has a file to bind-mount.

## Project Network

If the project is already running (`dde project:up`), dde detects the project's Docker network (`dde-services-<project>`) and connects the Claude container to it automatically. This gives Claude Code network access to your database, cache, and other services — useful for running migrations, tests, or any command that needs live services.

If the project is not running, the container starts without project network access. Run `dde project:up` in another terminal to bring services up; the Claude container will be reconnected on the next `dde project:claude` invocation.

## Configuration

Both options live in `~/.dde/config.yml` under the `claude_agent` key:

```yaml
claude_agent:
  enabled: true                  # set to false to disable the command entirely
  image: dde-claude:local        # override to use a custom or registry image
```

### Disabling the Agent

```yaml
claude_agent:
  enabled: false
```

`dde project:claude` will return an error instead of starting a container.

### Using a Custom Image

```yaml
claude_agent:
  image: ghcr.io/myorg/dde-claude:latest
```

The image must follow the same contract as the bundled Dockerfile: a `developer` user at UID 1000, Claude Code installed globally, and `/workspace` as the working directory.

## How the Image Works

The bundled image (`resources/docker/claude/Dockerfile`) is based on `node:22-slim`. The `node` user is renamed to `developer` and Claude Code is installed globally via npm:

```dockerfile
FROM node:22-slim

RUN usermod -l developer -d /home/developer -m node && \
    groupmod -n developer node && \
    npm install -g @anthropic-ai/claude-code && \
    rm -rf /var/lib/apt/lists/*

USER developer
WORKDIR /workspace
CMD ["sleep", "infinity"]
```

`docker run` always passes `-u developer` so the process runs as UID 1000 regardless of how the image was tagged or pulled.

## Worktree Support

When run from a Git worktree, dde connects to the worktree's own Docker network (`dde-services-<project>-<suffix>`) rather than the main project network. This matches the same worktree isolation used by `dde project:up`.

See [Git Worktrees](./worktrees.md) for background on how dde handles worktrees.
