---
title: "Architecture Overview"
---


dde follows a layered architecture with thin commands, manager-based orchestration, and dependency injection throughout. This document describes the namespace structure and design principles.

## Namespace Overview

### Application (`App\`)

- `Application` -- extends Symfony Console Application. Defines the app version (`APP_VERSION`), filters visible commands to `project:*`/`system:*` prefixes, adds the global `--output` option, and registers plugin commands via `PluginCommandLoader`.
- `Kernel` -- Symfony Kernel with PHAR-aware overrides for `getCacheDir()`/`getLogDir()`.

### Commands (`App\Command\`)

Commands are the CLI entry points. They are thin wrappers that delegate to managers and services.

- `App\Command\Project\*` -- project-scoped commands (init, up, down, shell, exec, logs, etc.)
- `App\Command\Project\Database\*` -- database commands (db, db:export, db:import, db:snapshot:*)
- `App\Command\Project\Service\*` -- per-project service management (service:list, service:enable, service:disable)
- `App\Command\System\*` -- system-wide commands (install, update, up, down, restart, status, doctor, cleanup)
- `App\Command\AboutCommand` -- version and system information

Base classes:

- `AbstractBaseCommand` -- root base class, provides `FormatterResolver` and `resolveFormatter()`
- `AbstractProjectCommand` -- adds project directory detection, config resolution, database helpers
- `AbstractSystemCommand` -- marker base class for system commands

All commands use the `#[AsCommand]` attribute for registration.

### Managers (`App\Manager\`)

Managers contain the core business logic and orchestrate complex operations.

| Manager | Responsibility |
|---------|---------------|
| `ProjectLifecycleManager` | Full project up/down/restart orchestration (services, certs, dev layers, overrides) |
| `ProjectInitManager` | `.dde/` directory structure creation during `project:init` |
| `DockerComposeManager` | Docker Compose CLI calls (`up`, `down`, `build`), override file generation |
| `DockerManager` | Low-level Docker CLI (inspect, network, volume, exec, image operations) |
| `ImageManager` | Image label inspection, dev layer Dockerfile generation, build, cache invalidation |
| `ConfigManager` | YAML config loading, override chain resolution, project directory detection, worktree detection |
| `DatabaseManager` | Database shell, export, import, snapshot management, port resolution |
| `SystemServiceManager` | Versionable service container lifecycle (start, stop, status, port allocation) |
| `ServiceConfigManager` | Service container configuration generation |
| `CertificateManager` | TLS certificate orchestration, domain extraction from compose files |
| `MkcertManager` | mkcert CLI wrapper, certificate generation, Traefik dynamic TLS config, cert registry |
| `CompletionManager` | Shell completion generation and installation |

### Services (`App\Service\`)

System services represent the infrastructure containers managed by `system:up`/`system:down`.

- `ServiceInterface` -- (not used directly; `AbstractSystemService` is the base)
- `AbstractSystemService` -- base class with start/stop/status logic via `DockerManager`
- `TraefikService` -- reverse proxy (ports 80/443), network creation, Traefik config management
- `DnsmasqService` -- DNS resolver for `.test` TLD, image build, resolver file management
- `SshAgentService` -- SSH agent socket sharing across containers
- `MailpitService` -- mail testing service
- `ServiceRegistry` -- service type definitions (`SERVICE_TYPES` constant), version defaults, port mappings, global service collection

### Configuration (`App\Config\`)

- `GlobalConfig` -- DTO for `~/.dde/config.yml` (output format, DNS forward, SSH keys, service versions)
- `ProjectConfig` -- DTO for `.dde/config.yml` (project name, services, containers)
- `ResolvedConfig` -- merged configuration from global + project + defaults
- `WorktreeInfo` -- data class for git worktree metadata

#### Definition (`App\Config\Definition\`)

- `GlobalConfigDefinition` -- Symfony TreeBuilder schema for global config
- `ProjectConfigDefinition` -- Symfony TreeBuilder schema for project config

### Models (`App\Model\`)

- `ContainerConfig` -- Docker container creation parameters (image, ports, volumes, labels, etc.)
- `ContainerInfo` -- Running container metadata from `docker inspect`
- `ServiceDefinition` -- service name, version, container name, ports
- `ServiceStatus` -- running/stopped status of a service container
- `UserContext` -- host UID/GID for user mapping inside containers

### Parsers (`App\Parser\`)

- `DockerComposeParser` -- reads and normalizes docker-compose.yml files
- `DockerfileParser` -- extracts information from Dockerfiles (base image, labels)

### Modifier (`App\Util\DockerComposeModifier`)

- `DockerComposeModifier` -- applies persistent modifications to a project's docker-compose.yml during `project:init`: external `dde` network, Traefik labels, SSH-Agent volume mount, database environment variables, `VIRTUAL_HOST`/`VIRTUAL_PORT` migration, v1 build args removal

### Adapters (`App\Adapter\`)

- `AdapterRegistry` -- discovers and provides adapter scripts (built-in from `resources/adapters/` and project-specific from `.dde/adapters/`). Handles PHAR extraction.

### Database (`App\Database\`)

- `DatabaseAdapterInterface` -- contract for database-specific operations (DSN generation, shell, export, import)
- `MariaDbAdapter` -- MariaDB/MySQL implementation
- `PostgresAdapter` -- PostgreSQL implementation
- `DatabaseAdapterRegistry` -- maps service names to adapters

### Doctor (`App\Doctor\`)

- `CheckInterface` -- contract for health checks (tagged with `dde.doctor_check`)
- `CheckResult` -- check outcome (name, status, message, fixHint)
- `CheckStatus` -- enum: `Ok`, `Warning`, `Error`
- `App\Doctor\Check\*` -- 10 concrete check implementations

### Events (`App\Event\`)

- `AbstractProjectEvent` -- base class carrying project directory
- `ProjectUpPreEvent`, `ProjectUpPostEvent` -- dispatched before/after project:up
- `ProjectDownPreEvent`, `ProjectDownPostEvent` -- dispatched before/after project:down

### Hooks (`App\Hook\`)

- `HookRunner` -- executes shell scripts from `.dde/hooks/` at lifecycle points
- `HookSubscriber` -- event subscriber that triggers `HookRunner` on project events

### Plugins (`App\Plugin\`)

- `PluginLoader` -- scans `~/.dde/plugins/` (global) and `.dde/plugins/` (project) for annotated shell scripts
- `PluginDefinition` -- parsed plugin metadata (command name, description, script path)
- `PluginProxyCommand` -- wraps a plugin script as a Symfony Console command, registered as `project:exec:{name}`
- `PluginCommandLoader` -- lazy command loader that integrates plugins into the Symfony application

### Output (`App\Output\`)

- `OutputFormatterInterface` -- contract for output formatting (success, error, table, isInteractive)
- `TextFormatter` -- human-readable console output with Symfony styling
- `JsonFormatter` -- structured JSON output
- `FormatterResolver` -- resolves and caches the active formatter

### EventListener (`App\EventListener\`)

- `OutputFormatListener` -- validates `--output` option and configures the formatter on every command

### Utilities (`App\Util\`)

- `ShellDetectorUtil` -- detects the current shell (zsh, bash, etc.)
- `TempFileUtil` -- creates temporary directories/files
- `UrlOpenerUtil` -- opens URLs in the default browser (cross-platform)
- `DiffUtil` -- generates unified diffs for file comparisons

## Design Principles

1. **Thin commands**: Commands only handle CLI I/O. All logic lives in managers and services.
2. **Dependency injection**: All classes use constructor injection with Symfony autowiring. No static methods or service locators.
3. **`#[AsCommand]` registration**: All commands use the attribute, no manual YAML configuration.
4. **`symfony/process` for all external calls**: Docker, git, mkcert, dig -- all external tools are called via `Process`. No `shell_exec()` or `exec()`.
5. **Strict types**: Every file declares `strict_types=1`.
6. **Readonly where possible**: Value objects and services use `readonly` properties.
7. **PHP enums for fixed sets**: `CheckStatus`, output format options, etc.
8. **Explicit return types**: No implicit returns, no mixed returns without reason.
