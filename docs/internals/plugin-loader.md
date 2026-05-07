---
title: "Plugin Loader"
---


The plugin system allows extending dde with custom shell scripts that are registered as Symfony Console commands.

## Plugin Locations

Plugins are loaded from two directories:

- **Global plugins**: `~/.dde/plugins/*.sh`
- **Project plugins**: `.dde/plugins/*.sh`

Project plugins override global plugins that have the same `@command` name.

## Plugin Format

A plugin is a shell script with annotation comments:

```bash
#!/usr/bin/env bash
# @command deploy
# @description Deploy the project to staging

set -e
echo "Deploying..."
# Your deployment logic here
```

Required annotations:

- `@command` -- the command name (registered as `project:{name}`)

Optional annotations:

- `@description` -- description shown in `dde list` and `dde help`

Scripts without a `@command` annotation are ignored.

## Loading Process

The `PluginLoader` class handles discovery and parsing:

```php
public function loadPlugins(?string $projectDir = null): array
```

1. Scans `~/.dde/plugins/` for `*.sh` files (via Symfony Finder, sorted by name)
2. Parses each file for `@command` and `@description` annotations using regex
3. Creates a `PluginDefinition` for each valid plugin
4. If a project directory is given, scans `.dde/plugins/` similarly
5. Merges project plugins into global plugins (project overrides global by command name)

### PluginDefinition

```php
final readonly class PluginDefinition
{
    public function __construct(
        public string $command,
        public string $description,
        public string $scriptPath,
    ) {}
}
```

## Command Registration

### PluginProxyCommand

Each plugin is wrapped in a `PluginProxyCommand`, which is a standard Symfony Console command:

- Registered as `project:{command}` (e.g. `project:deploy`). Built-in `project:*` commands take precedence — colliding plugin names are silently shadowed.
- Accepts optional arguments via an `args` variadic argument
- Executes the shell script via `symfony/process`
- Supports TTY mode for interactive scripts
- Returns the script's exit code

### PluginCommandLoader

The `PluginCommandLoader` implements Symfony's `CommandLoaderInterface` for lazy loading. It:

1. Calls `PluginLoader::loadPlugins()` to discover all plugins
2. Registers each as a `PluginProxyCommand`
3. Returns commands on demand when Symfony needs them

## Override Behavior

When a global and project plugin share the same `@command` name:

```
~/.dde/plugins/deploy.sh        # @command deploy
.dde/plugins/deploy.sh          # @command deploy  (wins)
```

The project plugin takes precedence. This uses a simple `array_merge()`:

```php
private function mergePlugins(array $global, array $project): array
{
    return array_merge($global, $project);
}
```

Since both arrays are keyed by command name, project entries overwrite global entries.

## Example

Create a global plugin:

```bash
# ~/.dde/plugins/hello.sh
# @command hello
# @description Say hello from a plugin

echo "Hello from dde plugin!"
echo "Arguments: $@"
```

Use it:

```bash
dde project:hello world
# Output:
# Hello from dde plugin!
# Arguments: world
```
