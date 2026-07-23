---
title: "Configuration Loader"
---


The `ConfigManager` is responsible for loading, validating, and merging configuration from multiple sources into a single `ResolvedConfig`.

## Override Chain

Configuration is resolved through a layered merge:

1. **Hardcoded defaults** -- defined in `ServiceRegistry::SERVICE_TYPES` (default versions, ports)
2. **Global config** -- `~/.dde/config.yml`, loaded by `ConfigManager::loadGlobalConfig()`
3. **Project config** -- `.dde/config.yml`, loaded by `ConfigManager::loadProjectConfig()`
4. **CLI options** -- `--output`, `--service`, etc., applied at runtime

### Step 1: Load Global Config

```php
public function loadGlobalConfig(): GlobalConfig
```

Reads `~/.dde/config.yml` (path from the `configDir` parameter). The YAML is parsed and validated against `GlobalConfigDefinition` (a Symfony TreeBuilder schema). If the file is missing, empty defaults are used. If the file has invalid configuration, a warning is recorded and defaults are used.

Returns a `GlobalConfig` DTO containing:

- `output` -- default output format (text/json)
- `dnsForward` -- DNS forward servers for dnsmasq
- `sshKeys` -- SSH key paths for the agent (`null` when not configured, which triggers auto-detection; an explicit empty list disables key loading)
- `serviceVersions` -- global version overrides (e.g. `mariadb: "10"`)
- `warnings` -- any validation warnings

### Step 2: Load Project Config

```php
public function loadProjectConfig(string $projectDir): ProjectConfig
```

Reads `{projectDir}/.dde/config.yml`. Validated against `ProjectConfigDefinition`.

Returns a `ProjectConfig` DTO containing:

- `name` -- project name (used for hostnames, database names)
- `services` -- declared services (array of `ServiceDefinition`)
- `containers` -- container-specific settings (e.g. `default_database_name`)

### Step 3: Merge into ResolvedConfig

```php
public function resolveConfig(string $projectDir): ResolvedConfig
```

Calls both loaders and merges via `ResolvedConfig::merge()`:

```php
public static function merge(GlobalConfig $global, ProjectConfig $project, array $defaultServiceVersions = []): self
```

The merge logic for service versions:

```
defaults (ServiceRegistry) < global config < project explicit version
```

Specifically:

- `$serviceVersions = array_merge($defaultServiceVersions, $global->serviceVersions)`
- When resolving a specific service version, `getServiceVersion()` checks project services first, then falls back to `$serviceVersions`

## Project Directory Detection

```php
public function findProjectDirectory(): ?string
```

Searches upward from the current working directory for a `.dde/config.yml` file. Returns the directory containing it, or `null` if not found.

```php
public function findDockerProjectDirectory(): ?string
```

Similar, but searches for Docker Compose files (`docker-compose.yml`, `docker-compose.yaml`, `compose.yml`, `compose.yaml`).

## Worktree Detection

```php
public function detectWorktree(string $projectDir): ?WorktreeInfo
```

Runs `git worktree list --porcelain` and parses the output to determine if the current directory is a git worktree. Returns `WorktreeInfo` (with main directory, worktree directory, branch name, and directory suffix) or `null` if not a worktree.

## Hostname Resolution

```php
public function resolveProjectHostname(string $projectName, ?WorktreeInfo $worktreeInfo): string
```

Returns the hostname for the project:

- Standard project: `{projectName}.test`
- Worktree: `{projectName}-{sanitized-suffix}.test`

The suffix is sanitized via `sanitizeWorktreeSuffix()`: transliterated to ASCII, lowercased, and truncated to 63 characters (DNS label limit).
