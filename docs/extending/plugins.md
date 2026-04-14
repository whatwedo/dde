---
title: "Plugins"
---


Plugins are shell scripts that register custom commands in dde. They allow you to create project-specific or globally available shortcuts without modifying the dde codebase.

## Plugin Directories

Plugins are loaded from two locations:

| Location | Scope |
|----------|-------|
| `~/.dde/plugins/` | Global -- available in all projects |
| `.dde/plugins/` | Project -- available only in the current project |

If a project plugin defines the same `@command` name as a global plugin, the **project plugin takes precedence**.

## Plugin Format

A plugin is a `*.sh` file with two required annotations in comments:

```bash
# @command <command-name>
# @description <human-readable description>
```

Everything after the annotations is the script body, executed when the command is invoked.

### Minimal Example

```bash
#!/usr/bin/env bash
# @command hello
# @description Say hello from the container
dde project:exec -s web echo "Hello from the web container"
```

This registers as `dde project:exec:hello`.

### With Arguments

Arguments passed to the plugin command are forwarded as positional parameters (`$1`, `$2`, etc.):

```bash
#!/usr/bin/env bash
# @command web:hash-pw
# @description Generate a password hash
dde project:exec -s web -- php bin/console security:hash-password "$1"
```

Invoke with:

```bash
dde project:exec:web:hash-pw "my-secret-password"
```

### Namespace Convention

Use colons in the `@command` name to create logical groupings:

```bash
# @command cache:clear
# @description Clear all application caches
```

This registers as `dde project:exec:cache:clear`.

## Command Registration

Plugins are registered under the `project:exec:` namespace. The full command name is `project:exec:<@command-value>`.

| `@command` value | Registered as |
|------------------|---------------|
| `hello` | `dde project:exec:hello` |
| `web:hash-pw` | `dde project:exec:web:hash-pw` |
| `cache:clear` | `dde project:exec:cache:clear` |

All plugin commands appear in `dde list` output with their `@description` text.

## Execution

Plugins run on the **host machine** via `PluginProxyCommand`. The plugin script is executed directly as a process with:

- A 300-second timeout
- TTY support when the terminal supports it (interactive commands work)
- Arguments appended to the script invocation
- Exit code forwarded to the caller

## File Discovery

- Only `*.sh` files are considered.
- Files are sorted alphabetically by name within each directory.
- Files without a `@command` annotation are silently ignored.
- The `@description` annotation is optional (defaults to an empty string).

## Examples

### Run tests

```bash
#!/usr/bin/env bash
# @command test
# @description Run the test suite
dde project:exec -s web php bin/phpunit
```

### Database reset

```bash
#!/usr/bin/env bash
# @command db:reset
# @description Drop and recreate database with fixtures
set -euo pipefail
dde project:exec -s web -- php bin/console doctrine:database:drop --force --if-exists
dde project:exec -s web -- php bin/console doctrine:database:create
dde project:exec -s web -- php bin/console doctrine:migrations:migrate --no-interaction
dde project:exec -s web -- php bin/console doctrine:fixtures:load --no-interaction
```

### Lint all files

```bash
#!/usr/bin/env bash
# @command lint
# @description Run all linters
set -euo pipefail
dde project:exec -s web php vendor/bin/ecs check
dde project:exec -s web php vendor/bin/phpstan analyse
```

### Global utility (in `~/.dde/plugins/`)

```bash
#!/usr/bin/env bash
# @command ports
# @description Show all exposed ports for the current project
docker compose ps --format "table {{.Name}}\t{{.Ports}}"
```

## Implementation Details

The plugin system consists of four classes:

- `PluginLoader` -- scans directories, parses annotations via regex (`/^#\s*@(\w+)\s+(.+)$/m`), returns `PluginDefinition` instances.
- `PluginDefinition` -- readonly DTO with `command`, `description`, `scriptPath`, and `pluginDir` properties.
- `PluginProxyCommand` -- Symfony Console command that wraps a `PluginDefinition` and executes the script via `ProcessFactory`. Validates that the script path resolves within the plugin directory (symlink traversal protection).
- `PluginCommandLoader` -- implements `CommandLoaderInterface` to lazily resolve plugin commands for the Symfony Console application.

## Related

- [Hooks](hooks.md) -- event-driven scripts tied to project lifecycle
- [Service Adapters](service-adapters.md) -- scripts that run inside containers at startup
