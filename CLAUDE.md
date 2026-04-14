# dde — Docker Development Environment

## Project
dde is a CLI application for the local Docker development environment at whatwedo.
Based on Symfony 8, PHP 8.5, built as a single-file binary via static-php-cli.

## Architecture
- Namespace: `App\`
- Commands: `App\Command\Project\*`, `App\Command\System\*` — thin commands, business logic in managers/services
- Manager: `App\Manager\` — orchestrate and coordinate
  - `ProjectLifecycleManager` — project up/down orchestration (services, certs, dev layers, overrides)
  - `ProjectInitManager` — `.dde/` directory structure creation
  - `ProjectInitAdaptationManager` — project init adaptation logic
  - `DockerComposeManager` — docker compose CLI calls, override generation
  - `DockerManager` — low-level Docker CLI (inspect, network, volume, exec, image list)
  - `ImageManager` — image label inspection, dev layer build, cache
  - `ConfigManager` — config loading, override chain, project detection
  - `DatabaseManager` — DB shell, export, import, snapshot, port resolution
  - `SystemServiceManager` — versionable service lifecycle (start, stop, port allocation)
  - `ServiceConfigManager` — service container config generation
  - `MkcertManager` — mkcert CLI wrapper, cert generation, Traefik dynamic TLS config
  - `CompletionManager` — shell completion generation and installation
  - `CleanupManager` — container and volume cleanup
  - `ProjectInfoManager` — project info display
- Services: `App\Service\` — encapsulate individual system services (Traefik, dnsmasq, Mailpit, SSH-Agent). Implement `ServiceInterface`
  - `ServiceRegistry` — service type definitions, port mapping, version defaults
  - `ImageBuilder` — Docker image building for system services
- Config: `App\Config\` — `GlobalConfig`, `ProjectConfig`, `ResolvedConfig`
  - Definition: `App\Config\Definition\` — `GlobalConfigDefinition`, `ProjectConfigDefinition` (Symfony TreeBuilder schemas)
- Model: `App\Model\` — `ContainerConfig`, `ContainerInfo`, `ContainerStatus`, `ServiceDefinition`, `ServiceStartStatus`, `ServiceStatus`, `UserContext`
- Parser: `App\Parser\` — `DockerComposeParser` (YAML), `DockerfileParser` (Dockerfile syntax)
- Adapter: `App\Adapter\` — `AdapterRegistry` (nginx, php-fpm, apache shell scripts in `resources/adapters/`)
- Database: `App\Database\` — `DatabaseAdapterInterface`, `MariaDbAdapter`, `PostgresAdapter`, `DatabaseAdapterRegistry`
- Doctor: `App\Doctor\` — `CheckInterface`, `CheckResult`, `CheckStatus`, 11 check classes under `App\Doctor\Check\`
- Event: `App\Event\` — `ProjectUpPreEvent`, `ProjectUpPostEvent`, `ProjectDownPreEvent`, `ProjectDownPostEvent`
- Hooks: `App\Hook\` — `HookRunner`, `HookSubscriber` (event-driven hook execution)
- Plugins: `App\Plugin\` — `PluginLoader`, `PluginDefinition`, `PluginProxyCommand`, `PluginCommandLoader`
- Output: `App\Output\` — `OutputFormatterInterface` with `TextFormatter`/`JsonFormatter`, `FormatterResolver`
- EventListener: `App\EventListener\` — `OutputFormatListener` (validates `--output` option)
- Util: `App\Util\` — `DockerComposeModifier`, `DiffUtil`, `NdJsonParser`, `ProcessFactory`, `ShellDetectorUtil`, `TempFileUtil`, `UrlOpenerUtil`
- Exception: `App\Exception\` — `HookFailedException`

## Build
- PHAR: `make build` — builds `bin/dde.phar` via humbug/box (`box.phar`, standalone)
- Binary: `make build-binary` — combines PHAR with `micro.sfx` from static-php-cli into standalone executable
- Build script: `scripts/build.sh` — automates micro.sfx download and binary creation, reads PHP version from `composer.json`
- PHAR context: `bin/console` detects PHAR via `str_starts_with(__DIR__, 'phar://')` and disables Dotenv
- Kernel overrides `getCacheDir()`/`getLogDir()` for PHAR (pre-warmed cache, temp log dir)
- PHP version: single source of truth in `composer.json` (`require.php`), propagated to build.sh and CI workflows

## CI/CD
- `.github/workflows/ci.yml` — ECS + PHPStan + Rector + Tests on push/PR
- `.github/workflows/release.yml` — Multi-platform build on tag `v*` (4 platforms: macOS/Linux x86_64+arm64)
- PHP version extracted from `composer.json` in both workflows
- PHPStan requires Symfony cache warmup (dev env) for container XML analysis
- Tests with `#[Group('e2e')]` require Docker and are excluded from CI (`--exclude-group=e2e`)

## Installation
- `system:install` — configures mkcert, dnsmasq (macOS + Linux), Traefik, SSH-Agent, shell completion
- DNS: macOS via `/etc/resolver/test`, Linux via systemd-resolved or NetworkManager

## Rules
- Every file: `declare(strict_types=1);`
- Classes are not `final` by default — only use `final` on leaf classes that implement an interface or extend an abstract class
- `readonly` properties where possible
- PHP enums instead of constants for fixed value sets
- Return types always explicit
- Docker interaction ONLY via manager classes, never shell_exec/exec directly
- symfony/process for all process calls
- Symfony DI with autowiring, commands via #[AsCommand]

## Quality
- ECS: `make ecs` (whatwedo/php-coding-standard, whatwedo-symfony set)
- PHPStan: `make phpstan` (Level 8)
- Rector: `make rector` (PHP 8.5 + Symfony 8 sets)
- Tests: `make test` (PHPUnit, Unit + Integration, excludes e2e)
- Tests: `make test-e2e` (only e2e)

## Testing
- Unit tests for every new class, written simultaneously with the code
- Tests mirror src/ structure: `tests/Unit/Manager/ImageManagerTest.php`
- Integration tests require Docker (`#[Group('e2e')]`)
- Minimum: happy path + most important error cases

## Commit Messages
Conventional Commits: `feat(project):`, `fix(config):`, `test(manager):`, `docs(commands):`, `chore(ci):`
