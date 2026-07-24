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

- `App\Command\Project\*` -- project-scoped commands (init, up, down, stop, shell, exec, logs, open, status, describe, update)
- `App\Command\Project\Database\*` -- database commands (db, db:export, db:import, db:snapshot:*)
- `App\Command\Project\Service\*` -- per-project service management (service:list, service:enable, service:disable)
- `App\Command\System\*` -- system-wide commands (install, update, up, down, stop, restart, status, doctor, cleanup, service:up)
- `App\Command\AboutCommand` -- version and system information

Base classes:

- `AbstractBaseCommand` -- root base class, provides `FormatterResolver` and `resolveFormatter()`
- `AbstractProjectCommand` -- adds project directory detection, config resolution
- `AbstractDatabaseCommand` -- adds database adapter resolution and connection helpers
- `AbstractSystemCommand` -- marker base class for system commands

All commands use the `#[AsCommand]` attribute for registration.

### Managers (`App\Manager\`)

Managers contain the core business logic and orchestrate complex operations.

| Manager                        | Responsibility                                                                                                                                                     |
| ------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `ProjectLifecycleManager`      | Project up/down orchestration (services, certs, dev layers, overrides)                                                                                             |
| `SystemLifecycleManager`       | System up/down/stop/update orchestration (global services + versioned containers, image rebuild with `--pull`, post-install refresh for completion + claude-skill) |
| `ProjectInitManager`           | `.dde/` directory structure creation during `project:init`                                                                                                         |
| `ProjectInitAdaptationManager` | Project adaptation logic during `project:init` (compose/env migration proposals; `EnvMigrationProposal` is its DTO)                                                |
| `DockerComposeManager`         | Docker Compose CLI calls, runtime override generation                                                                                                              |
| `DockerManager`                | Low-level Docker CLI (inspect, network, volume, exec, image operations)                                                                                            |
| `ImageManager`                 | Image label inspection, dev layer build, cache invalidation                                                                                                        |
| `GlobalConfigManager`          | Global `~/.dde/config.yml` loading                                                                                                                                 |
| `ProjectConfigManager`         | Project `.dde/config.yml` loading, merge with global config, project directory detection                                                                           |
| `WorktreeManager`              | Git worktree detection, hostname resolution / rewriting (incl. subdomains), DB-name resolution, environment override computation (incl. `env_file` values)         |
| `DatabaseManager`              | Database shell, export, import, snapshot management, port resolution                                                                                               |
| `SystemServiceManager`         | Versionable service container lifecycle (start, stop, status, port allocation)                                                                                     |
| `ServiceConfigManager`         | Service container configuration generation                                                                                                                         |
| `MkcertManager`                | mkcert CLI wrapper, cert generation, Traefik dynamic TLS config, CA root path resolution for container trust                                                       |
| `CompletionManager`            | Shell completion generation and installation                                                                                                                       |
| `CleanupManager`               | Container and volume cleanup                                                                                                                                       |
| `ProjectInfoManager`           | Project info display                                                                                                                                               |
| `ClaudeCodeManager`            | Detects a Claude Code installation and installs/refreshes the bundled `skills/claude/dde` skill                                                                    |

### Services (`App\Service\`)

System services encapsulate the infrastructure containers managed by `system:up`/`system:down`. They implement `ServiceInterface`.

- `ServiceInterface` -- contract for system services, collected via container tag
- `ProjectNetworkAwareInterface` -- extends `ServiceInterface`; marks global services that must be attached to every per-project network (with their DNS aliases)
- `AbstractSystemService` -- base class with start/stop/status logic via `DockerManager`
- `TraefikService` -- reverse proxy (ports 80/443), network creation, Traefik config management
- `DnsmasqService` -- DNS resolver for the `.test` TLD, image build, resolver file management
- `SshAgentService` -- SSH agent socket sharing across containers
- `MailpitService` -- mail testing service
- `ServiceRegistry` -- service type definitions, version defaults, port mappings, global service collection
- `ImageBuilder` -- Docker image building for system services
- `HostSshAgentResolver` -- resolves the host SSH agent socket for `host` agent mode (macOS: always the Docker Desktop / OrbStack bridge; Linux: `SSH_AUTH_SOCK` or an explicit socket path, leading `~` expanded); single source of truth shared by `DockerComposeManager` and `SshAgentCheck`

### Configuration (`App\Config\`)

- `GlobalConfig` -- DTO for `~/.dde/config.yml` (output format, DNS forward, SSH keys, service versions)
- `ProjectConfig` -- DTO for `.dde/config.yml` (project name, services, containers)
- `ResolvedConfig` -- merged configuration from global + project + defaults
- `WorktreeInfo` -- data class for git worktree metadata

#### Definition (`App\Config\Definition\`)

- `GlobalConfigDefinition` -- Symfony TreeBuilder schema for the global config
- `ProjectConfigDefinition` -- Symfony TreeBuilder schema for the project config

### Models (`App\Model\`)

- `ContainerConfig` -- Docker container creation parameters (image, ports, volumes, labels, etc.)
- `ContainerInfo` -- running container metadata from `docker inspect`
- `ContainerStatus` -- container state representation
- `ServiceDefinition` -- service name, version, container name, ports
- `ServiceStartStatus` / `ServiceStatus` -- start outcome and running/stopped status of a service container
- `SystemLifecycleProgress` -- progress events emitted by `SystemLifecycleManager`, rendered live by the `system:*` commands
- `UserContext` -- host UID/GID for user mapping inside containers
- `HostSshAgentResolution` -- result of `HostSshAgentResolver` (available flag, mount source, reason)

### Parsers (`App\Parser\`)

- `DockerComposeParser` -- reads and normalizes docker-compose.yml files
- `DockerfileParser` -- extracts information from Dockerfiles (base image, labels)

### Adapters (`App\Adapter\`)

- `AdapterRegistry` -- discovers and provides adapter scripts (built-in ones from `resources/adapters/`, project-specific ones from `.dde/adapters/`). Handles PHAR extraction.

### Database (`App\Database\`)

- `DatabaseAdapterInterface` -- contract for database-specific operations (DSN generation, shell, export, import)
- `MariaDbAdapter` -- MariaDB/MySQL implementation
- `PostgresAdapter` -- PostgreSQL implementation
- `DatabaseAdapterRegistry` -- maps service names to adapters

### Doctor (`App\Doctor\`)

- `CheckInterface` -- contract for health checks (tagged with `dde.doctor_check`)
- `CheckResult` -- check outcome (name, status, message, fixHint)
- `CheckStatus` -- enum: `Ok`, `Warning`, `Error`
- `App\Doctor\Check\*` -- 11 concrete check implementations

### Events (`App\Event\`)

- `AbstractProjectEvent` -- base class carrying the project directory
- `ProjectUpPreEvent`, `ProjectUpPostEvent` -- dispatched before/after project:up
- `ProjectDownPreEvent`, `ProjectDownPostEvent` -- dispatched before/after project:down

### Hooks (`App\Hook\`)

- `HookRunner` -- executes shell scripts from `.dde/hooks/` at lifecycle points
- `HookSubscriber` -- event subscriber that triggers `HookRunner` on project events

### Plugins (`App\Plugin\`)

- `PluginLoader` -- scans `~/.dde/plugins/` (global) and `.dde/plugins/` (project) for annotated shell scripts
- `PluginDefinition` -- parsed plugin metadata (command name, description, script path)
- `PluginProxyCommand` -- wraps a plugin script as a Symfony Console command, registered as `project:{name}`
- `PluginCommandLoader` -- lazy command loader that integrates plugins into the Symfony application

### Output (`App\Output\`)

- `OutputFormatterInterface` -- contract for output formatting (success, error, table, isInteractive)
- `TextFormatter` -- human-readable console output with Symfony styling
- `JsonFormatter` -- structured JSON output
- `OutputFormat` -- enum of the supported `--output` values
- `FormatterResolver` -- resolves and caches the active formatter

### Event Listeners (`App\EventListener\`)

- `OutputFormatListener` -- validates the `--output` option and configures the formatter on every command
- `SystemInstallCheckListener` -- warns when commands run before `system:install` completed

### Utilities (`App\Util\`)

- `ComposeEnvEntryParser` -- compose `environment:` entry normalisation
- `DockerComposeModifier` -- persistent modifications to a project's `docker-compose.yml` during `project:init`: adds Traefik labels, injects `DATABASE_URL`/`MAILER_DSN` for detected dde services, migrates `VIRTUAL_HOST`/`VIRTUAL_PORT` (v1) to labels, and removes v1 boilerplate that the runtime overlay injects instead
- `DiffUtil` -- unified diffs for file comparisons
- `IdentifierSanitizer` -- slug sanitisation for hostnames and DB identifiers
- `NdJsonParser` -- newline-delimited JSON parsing (Docker CLI output)
- `PrivilegeEscalator` -- optimistic-then-sudo wrapper for host-level writes during `system:install`
- `ProcessFactory` -- `symfony/process` factory used by the managers
- `ShellDetectorUtil` -- detects the current shell (zsh, bash, etc.)
- `TempFileUtil` -- temporary directories/files
- `TraefikLabelGenerator` -- pure generation of the Traefik v3 label set for a hostname (incl. hostname allow-list against `Host()` rule injection)
- `UrlOpenerUtil` -- opens URLs in the default browser (cross-platform)

### Exceptions (`App\Exception\`)

- `HookFailedException` -- raised when a lifecycle hook script exits non-zero

## Container Labels

Every container created via `DockerManager::run()` (i.e. all dde-managed system containers — Traefik, dnsmasq, Mailpit, SSH-Agent, and the versioned service containers like `dde-postgres-18.3`) carries:

- `dde.managed=true` -- marker used by `CleanupManager` and `dde system:down/cleanup` to find every dde container, regardless of name.
- `dde.service=<name>` -- service identity (`traefik`, `mailpit`, `postgres`, …).
- `dde.version=<version>` -- only on versioned services from `SystemServiceManager`.
- `com.docker.compose.project=dde` -- groups dde-managed system containers under a single `dde` project in the Docker Desktop UI. dde does not actually use docker compose for these containers, but Docker Desktop only inspects this label for grouping.

## Design Principles

1. **Thin commands**: Commands only handle CLI I/O. All logic lives in managers and services.
2. **Dependency injection**: All classes use constructor injection with Symfony autowiring. No static methods or service locators.
3. **`#[AsCommand]` registration**: All commands use the attribute, no manual YAML configuration.
4. **`symfony/process` for all external calls**: Docker, git, mkcert, dig -- all external tools are called via `Process`, and only from manager classes. No `shell_exec()` or `exec()`.
5. **Strict types**: Every file declares `strict_types=1`.
6. **Readonly where possible**: Value objects and services use `readonly` properties.
7. **PHP enums for fixed sets**: `CheckStatus`, `OutputFormat`, etc. — no constant lists.
8. **Explicit return types**: No implicit returns, no mixed returns without reason.
9. **Not `final` by default**: Only leaf classes that implement an interface or extend an abstract class are `final`; pure static utilities may be `final`.
10. **Single source of truth**: Domain values (e.g. DB credentials) live in the class whose responsibility they are (`DatabaseAdapter`), every other caller delegates to it. No hardcoded duplicates.
