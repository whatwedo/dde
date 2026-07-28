# dde — Docker Development Environment

dde is whatwedo's CLI for local Docker development environments: automatic HTTPS (Traefik + mkcert), local DNS (dnsmasq), per-project database services, and git-worktree isolation. It is a Symfony 8 console application on PHP 8.5, shipped as a single static binary (PHAR + static-php-cli).

Layered architecture: thin commands under `App\Command\{Project,System}\` delegate to managers (`App\Manager\`, orchestration) and system services (`App\Service\`, one class per infrastructure container). Docker, git, and mkcert are called only through manager classes via `symfony/process` — never `shell_exec`/`exec`.

## Read before you work

Read the page that matches your task before touching code:

| Task                            | Read                                                                   |
| ------------------------------- | ---------------------------------------------------------------------- |
| Anything under `src/`           | `docs/contributing/architecture.md` — namespace map, design principles |
| Writing tests                   | `docs/contributing/testing.md`                                         |
| Build, PHAR, local dev setup    | `docs/contributing/development-setup.md`                               |
| Reproducible builds             | `docs/internals/reproducible-builds.md`                                |
| Release, CI/CD, nightly channel | `docs/contributing/release-process.md`                                 |
| Compose override generation     | `docs/internals/docker-compose-override.md`                            |
| Committing                      | `docs/contributing/commit-conventions.md`                              |

User-facing behaviour is documented in `docs/guides/` and `docs/services/`, internal contracts in `docs/internals/`.

## Verify your work

- `make qa` — ECS + PHPStan (level 8) + Rector (dry-run) + tests. Must stay green after every commit, including mid-branch checkouts.
- `make test` — PHPUnit Unit + Integration, no Docker needed. `make test-e2e` — E2E tests, requires Docker, excluded from CI.
- **ECS trap:** `make ecs`/`make qa` run `ecs check --fix` and rewrite files in place; CI runs `ecs check` without `--fix` and fails on any diff. After every QA run, `git status` must be clean before committing or pushing — stage and amend auto-fixed files first.
- Never run `rector process` (mutating) on a feature branch without an explicit review checkpoint.
- After pushing, verify CI with `gh pr checks <number>` (or `gh run watch`). Treat "pushed" as "in progress" until CI reports green.

## Rules the tools can't enforce

- Classes are not `final` by default — only leaf classes that implement an interface or extend an abstract class (pure static utilities may be `final`).
- PHP enums instead of constants for fixed value sets.
- Single source of truth: domain values (e.g. DB credentials) live in the class whose responsibility they are; every other caller delegates. No hardcoded duplicates.
- Never add `@phpstan-ignore*` or inline `@var` comments to silence PHPStan; no `assert()` for type narrowing. Fix the underlying type issue instead.
- TDD: write the unit test together with the code (red → green → refactor). A bug fix ships with a regression test that fails when the fix is reverted.
- `Application::APP_VERSION` ships as the literal `@APP_VERSION@` (substituted by CI at build time) — never hand-edit the constant.

## Documentation is part of the feature

A feature is complete only when documented in the same branch. All committed Markdown is written in English.

- User-visible change (`feat`, `fix`, `perf`) → entry in `CHANGELOG.md`. No exceptions — a missing changelog entry is the most common review finding. **One short sentence per entry**, stating what changed for the user; the mechanism and the reasoning belong in the commit message and the PR, never here. Link the ticket and the PR, and additionally the docs page when relevant.
- New command, flag, or automatic behaviour → `docs/guides/<topic>.md`, cross-referenced from related guides.
- New manager/util or reshuffled responsibilities → `docs/contributing/architecture.md`; non-obvious internal contracts → `docs/internals/<topic>.md`.
- v1 → v2 migration impact → `docs/guides/migration-from-v1.md`.
- Changes to how AI agents drive the dde CLI → `skills/claude/dde/SKILL.md`.
- Convention changes → this `AGENTS.md`. Keep it short: only universally applicable rules live here, details belong in `docs/contributing/`.

Prefer short, focused pages over long generic ones, and always include the *why* alongside the *how* for non-obvious design decisions.

Documentation is **timeless**: it describes the behaviour of the current version only — never its history or future. Phrases like "new in this version", "previously", or "will be" don't belong in the docs; change-over-time lives in `CHANGELOG.md` and the git history.

## Commits

Conventional Commits, always signed and signed-off (`git commit -S --signoff`). Read `docs/contributing/commit-conventions.md` for the type taxonomy and branch-history rules before committing.

- Never use `--no-verify`, `--no-gpg-sign`, or any hook-skipping flag. Never add `Co-Authored-By:` trailers.
- Keep feature-branch history clean: amend or rebase fixes into the commit that introduced them instead of appending fix-up commits.
- `.claude/plans/`, `.claude/specs/`, `.claude/worktree/`, and `.claude/worktrees/` are gitignored and must never be committed.
