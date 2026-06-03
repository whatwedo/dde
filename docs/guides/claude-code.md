---
title: "Guide: Claude Code"
---

`dde project:claude` (alias: `dde claude`) starts an isolated [Claude Code](https://claude.ai/code) container for the current project. Each project gets its own ephemeral container with the project directory mounted as the workspace. Your existing Claude Code credentials and settings are shared from the host, so no separate login is required after the first run.

## Usage

```bash
cd ~/projects/my-app
dde project:claude    # or: dde claude
```

dde pulls `ghcr.io/whatwedo/claude-code-container:latest` on first run (if not already present) and starts it with:

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
  enabled: true                                          # set to false to disable the command entirely
  image: ghcr.io/whatwedo/claude-code-container:latest  # override to pin a version or use a fork
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
  image: ghcr.io/myorg/my-claude:latest
```

The image must provide a `developer` user at UID 1000 with home at `/home/developer`, Claude Code installed globally, and `/workspace` as the working directory. See [ghcr.io/whatwedo/claude-code-container](https://github.com/whatwedo/claude-code-container) for the reference Dockerfile.

## Worktree Support

When run from a Git worktree, dde connects to the worktree's own Docker network (`dde-services-<project>-<suffix>`) rather than the main project network. This matches the same worktree isolation used by `dde project:up`.

See [Git Worktrees](./worktrees.md) for background on how dde handles worktrees.
