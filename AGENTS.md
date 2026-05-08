# dde — Docker Development Environment

## Project
dde is a CLI application for the local Docker development environment at whatwedo.
Based on Symfony 8, PHP 8.5, built as a single-file binary via static-php-cli.

## Architecture
- Namespace: `App\`
- Commands: `App\Command\Project\*`, `App\Command\System\*` — thin commands, business logic in managers/services
- Manager: `App\Manager\` — orchestrate and coordinate
  - `ProjectLifecycleManager` — project up/down orchestration (services, certs, dev layers, overrides)
  - `SystemLifecycleManager` — system up/down/stop/update orchestration (global services + versioned containers, image rebuild with --pull, post-install refresh for completion + claude-skill)
  - `ProjectInitManager` — `.dde/` directory structure creation
  - `ProjectInitAdaptationManager` — project init adaptation logic
  - `DockerComposeManager` — docker compose CLI calls, override generation
  - `DockerManager` — low-level Docker CLI (inspect, network, volume, exec, image list)
  - `ImageManager` — image label inspection, dev layer build, cache
  - `GlobalConfigManager` — global `~/.dde/config.yml` loading
  - `ProjectConfigManager` — project `.dde/config.yml` loading, merge with global, project directory detection
  - `WorktreeManager` — git worktree detection, hostname resolution / rewriting (incl. subdomains), DB-name resolution, environment override computation (incl. `env_file` values)
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
- Model: `App\Model\` — `ContainerConfig`, `ContainerInfo`, `ContainerStatus`, `ServiceDefinition`, `ServiceStartStatus`, `ServiceStatus`, `UserContext`, `EnvMigrationProposal` (DTO for project:init env-file migrations; lives in `App\Manager`)
- Parser: `App\Parser\` — `DockerComposeParser` (YAML), `DockerfileParser` (Dockerfile syntax)
- Adapter: `App\Adapter\` — `AdapterRegistry` (nginx, php-fpm, apache shell scripts in `resources/adapters/`)
- Database: `App\Database\` — `DatabaseAdapterInterface`, `MariaDbAdapter`, `PostgresAdapter`, `DatabaseAdapterRegistry`
- Doctor: `App\Doctor\` — `CheckInterface`, `CheckResult`, `CheckStatus`, 11 check classes under `App\Doctor\Check\`
- Event: `App\Event\` — `ProjectUpPreEvent`, `ProjectUpPostEvent`, `ProjectDownPreEvent`, `ProjectDownPostEvent`
- Hooks: `App\Hook\` — `HookRunner`, `HookSubscriber` (event-driven hook execution)
- Plugins: `App\Plugin\` — `PluginLoader`, `PluginDefinition`, `PluginProxyCommand`, `PluginCommandLoader`
- Output: `App\Output\` — `OutputFormatterInterface` with `TextFormatter`/`JsonFormatter`, `FormatterResolver`
- EventListener: `App\EventListener\` — `OutputFormatListener` (validates `--output` option)
- Util: `App\Util\` — `ComposeEnvEntryParser` (compose `environment:` entry normalisation), `DockerComposeModifier`, `DiffUtil`, `IdentifierSanitizer` (slug sanitisation for hostname + DB identifiers), `NdJsonParser`, `ProcessFactory`, `ShellDetectorUtil`, `TempFileUtil`, `UrlOpenerUtil`
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
- Classes are not `final` by default — only use `final` on leaf classes that implement an interface or extend an abstract class. Pure utility classes (static methods only, stateless) may be `final`.
- `readonly` properties where possible
- PHP enums instead of constants for fixed value sets
- Return types always explicit
- Docker interaction ONLY via manager classes, never shell_exec/exec directly
- symfony/process for all process calls
- Symfony DI with autowiring, commands via #[AsCommand]
- Single source of truth: domain values (e.g. DB credentials) live in the class whose responsibility they are (`DatabaseAdapter`), and every other caller delegates to it. No hardcoded duplicates.
- Never add `@phpstan-ignore*` or inline `@var` comments just to silence PHPStan. No `assert()` for type narrowing. Fix the underlying type issue instead.
- Never use `--no-verify`, `--no-gpg-sign` or any hook-skipping flag on git commands.
- YAML compose output: use `Symfony\Component\Yaml\Tag\TaggedValue` (`!override`) to force key replacement when the base file's list-form values would otherwise be merged.

## Quality
- ECS: `make ecs` (whatwedo/php-coding-standard, whatwedo-symfony set) — **runs `ecs check --fix` and rewrites files in place.** CI runs `ecs check` (no `--fix`) and fails on any diff. After every `make ecs` / `make qa` run, `git status` **must** be clean before committing or pushing; stage and amend any auto-fixed files first. A green local `make qa` with dirty working tree means CI will fail.
- PHPStan: `make phpstan` (Level 8)
- Rector: `make rector` (PHP 8.5 + Symfony 8 sets) — dry-run in `make qa`; never run `rector process` (mutating) on a feature branch without an explicit review checkpoint.
- Tests: `make test` (PHPUnit, Unit + Integration, excludes e2e)
- Tests: `make test-e2e` (only e2e)

## Testing
- Unit tests for every new class, written simultaneously with the code (TDD: red → green → refactor).
- Tests mirror src/ structure: `tests/Unit/Manager/ImageManagerTest.php`, `tests/Unit/Util/…`, `tests/Unit/Command/…`.
- Three tiers:
  - `tests/Unit/**` — pure unit, no Docker, no CLI invocation. Default `make test`.
  - `tests/Integration/**` — wires real collaborators (e.g. real `DockerComposeParser`), still no Docker daemon.
  - `tests/E2E/**` with `#[Group('e2e')]` — spawns the `bin/console` CLI and real Docker; excluded from CI.
- Use `#[DataProvider]` + `iterable<string, array{…}>` for parametrised cases.
- Mark tests that do not assert on their mocks with `#[AllowMockObjectsWithoutExpectations]` (PHPUnit 12 style).
- Every E2E test must own its setUp/tearDown: isolated `tempDir`, `DDE_CONFIG_DIR` / `DDE_DATA_DIR` pointing at a random path, containers cleaned up with `$this->cleanupLeftoverContainers()`.
- When a fix addresses a real-world bug, add a **regression test** that would have caught the bug (verify by reverting the fix and watching the test fail).
- `make qa` must stay green after every commit, including bisect-mid-branch checkouts. Rebase with `--exec "make qa"` when in doubt.
- After pushing, verify CI with `gh pr checks <number>` (or `gh run watch`). A green local `make qa` does not guarantee CI green — the ECS auto-fix trap above is the canonical example. Treat "pushed" as "in progress" until CI reports pass.

## Documentation
**All committed Markdown (under `docs/`, `skills/`, `README.md`, `CHANGELOG.md`, `AGENTS.md`) is written in English.** The project is distributed publicly and consumed by an English-speaking audience; keep the docs consistent.

**Every developed feature must be documented in the same branch.** A feature is not complete until the relevant docs are updated. Before merging, ask: "where would a user / maintainer / AI agent look for this?"

Required touch-points per feature:

- **User-facing behaviour** (new command, new flag, new automatic behaviour) → `docs/guides/<topic>.md`. Add a cross-reference from related guides.
- **Architecture / internals change** (new manager, new util, reshuffled responsibilities) → update this `AGENTS.md` (architecture list) plus a note in `docs/internals/<topic>.md` when the internal contract is non-obvious.
- **v1 → v2 migration impact** (changes that affect how a legacy project is upgraded) → extend `docs/guides/migration-from-v1.md`.
- **Claude / AI agent workflow** (anything that changes how the `dde` CLI should be driven from an AI agent) → update `skills/claude/dde/SKILL.md`.
- **Convention changes** (rules for commits, tests, PHPStan, architecture invariants) → extend this `AGENTS.md`.

Docs that live with the feature:

- Prefer short, focused pages over long generic ones.
- Always include a "why" alongside the "how" for non-obvious design decisions (e.g. why `.env` stays neutral while `docker-compose.yml` carries the dde runtime).
- Regression-fix commits should mention the regression in the commit body so the rationale survives in the git history, even if no docs page needs updating.

## Commit Messages
Conventional Commits: `feat(project):`, `fix(config):`, `test(manager):`, `docs(commands):`, `chore(ci):`

- **Subject:** lowercase, imperative, meta-info in type+scope — the subject alone must stand on its own (not relying on type/scope to complete the sentence).
- **Body:** explain the *why*, not the *what*. Include constraints, prior incidents, the alternative paths considered. Can be long.
- **`feat`** is reserved for changes that are visible to end-users of dde (new command, new flag, new automatic behaviour they experience). Internal utilities, new framework classes, refactor-enablers, internal APIs → `chore` or `refactor`. Be strict: if a user would not notice it, it is not a feature.
- **`refactor`** when the surface stays the same but the implementation moves (renaming, extraction, delegation).
- **`chore`** for everything that doesn't belong elsewhere: new internal classes without behaviour change, tooling tweaks, formatting that isn't pure `style`.
- **`style`** only for pure whitespace/formatting (no code-visible behaviour). Prefer `chore` when unsure.
- **`test`** for test additions, renames, scope changes. Use `test(e2e):` / `test(unit):` to disambiguate when relevant.
- **`docs`** for documentation-only changes.
- Never add `Co-Authored-By:` trailers.
- Always sign commits (`-S`) and sign-off (`--signoff`). Never use `--no-verify`, `--no-gpg-sign`.
- Keep the git history clean on a feature branch: amend or surgically rebase into the commit that introduced the bug/feature; do not append fix-up commits. Use cherry-pick chains instead of `git rebase -i`.
- Plan and spec files under `.claude/plans/` and `.claude/specs/` are gitignored (along with `.claude/worktree/` for scratch worktrees) and must never be committed.
