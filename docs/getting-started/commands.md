---
title: "Commands"
---


## Global Options

Available on every command:

| Option | Description |
|--------|-------------|
| `-o, --output=OUTPUT` | Output format: `text` (default) or `json` |
| `-q, --quiet` | Only errors are displayed |
| `--silent` | Suppress all output |
| `-n, --no-interaction` | No interactive prompts |
| `-v\|vv\|vvv` | Increase verbosity |
| `-h, --help` | Display help for the given command |
| `-V, --version` | Display the application version |
| `--ansi\|--no-ansi` | Force (or disable) ANSI output |

## General

| Command | Description |
|---------|-------------|
| `about` | Show information about dde |

## Project Lifecycle

| Command | Alias | Description | Options |
|---------|-------|-------------|---------|
| `project:init` | | Initialize a project for dde | `--name=NAME` `--services=SERVICES` `--container=CONTAINER` `--shell=SHELL` `-f\|--force` `--no-docker` `--dry-run` |
| `project:up` | `up` | Start the project containers | `--skip-hooks` `--build` |
| `project:down` | `down` | Stop and remove containers | `--skip-hooks` `--remove-orphans` |
| `project:stop` | `stop` | Stop containers without removing | |
| `project:update` | `update` | Pull images, rebuild, restart | `--skip-hooks` |

## Project Information

| Command | Alias | Description | Options |
|---------|-------|-------------|---------|
| `project:status` | | Show project status | |
| `project:describe` | | Show detailed project info | |
| `project:open` | `open` | Open project in browser | `--url-only` |

## Execution

| Command | Alias | Description | Arguments / Options |
|---------|-------|-------------|---------------------|
| `project:exec` | `exec` | Run a command in a container | `<cmd>...` `-s\|--service=SERVICE` `--root` |
| `project:shell` | `shell` | Open interactive shell | `-s\|--service=SERVICE` `--root` |
| `project:logs` | `logs` | Show container logs | `-s\|--service=SERVICE` `-f\|--follow` `--no-follow` `--tail=TAIL` |
| `project:claude` | `claude` | Open Claude Code in an isolated agent container | |

See also: [Claude Code](../guides/claude-code.md) for setup and configuration.

## Database

| Command | Description | Arguments / Options |
|---------|-------------|---------------------|
| `project:db` | Open database shell | `-s\|--service=SERVICE` `-d\|--database=DATABASE` |
| `project:db:open` | Open DB in external client | `-s\|--service=SERVICE` `-d\|--database=DATABASE` |
| `project:db:export` | Export database to SQL file | `<file>` `-s\|--service=SERVICE` `-d\|--database=DATABASE` |
| `project:db:import` | Import SQL file into database | `<file>` `-s\|--service=SERVICE` `-d\|--database=DATABASE` |
| `project:db:snapshot:create` | Create database snapshot | `--name=NAME` `-s\|--service=SERVICE` `-d\|--database=DATABASE` |
| `project:db:snapshot:list` | List snapshots | `-s\|--service=SERVICE` |
| `project:db:snapshot:restore` | Restore a snapshot | `[name]` `-s\|--service=SERVICE` `-d\|--database=DATABASE` |

## Project Services

| Command | Description | Arguments / Options |
|---------|-------------|---------------------|
| `project:service:enable` | Enable a service | `<service>` `--service-version=SERVICE-VERSION` |
| `project:service:disable` | Disable a service | `<service>` `--service-version=SERVICE-VERSION` |
| `project:service:list` | List available and active services | |

## System

| Command | Description | Arguments / Options |
|---------|-------------|---------------------|
| `system:install` | Install and configure the dde system | |
| `system:up` | Start all global services | |
| `system:down` | Stop and remove all global services | |
| `system:stop` | Stop all dde containers without removing | |
| `system:update` | Rebuild global service images and refresh integrations | |
| `system:status` | Show status of global services | |
| `system:service:up` | Start a service manually | `<name>` `--service-version=SERVICE-VERSION` |
| `system:doctor` | Check the health of the dde system | |
| `system:cleanup` | Clean up dde-managed Docker resources | `--dry-run` `--force` |

See also: [System-Lifecycle](../guides/system-lifecycle.md) for when to use `system:install` vs. `system:update`, or `system:stop` vs. `system:down`.
