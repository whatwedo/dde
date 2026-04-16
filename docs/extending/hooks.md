---
title: "Hooks"
---


Hooks are shell scripts that run automatically at defined points in the project lifecycle. They execute on the **host machine** (not inside containers), with the project directory as the working directory.

## Hook Directories

Place executable `*.sh` files in the corresponding `.dde/hooks/` subdirectory:

| Directory | Trigger |
|-----------|---------|
| `.dde/hooks/project.up.pre/` | Before containers start |
| `.dde/hooks/project.up.post/` | After containers are started |
| `.dde/hooks/project.down.pre/` | Before containers stop |
| `.dde/hooks/project.down.post/` | After containers are stopped |

These directories are created automatically by `dde project:init`.

## Execution Rules

- Only `*.sh` files are picked up.
- Scripts run in **alphabetical order** by filename. Use numeric prefixes (e.g. `01-`, `02-`) to control order.
- Each script has a **300-second timeout**. If a script exceeds this, it is killed.
- Scripts that are **not executable** are skipped with a warning. Fix with `chmod +x <script>`.
- If a script exits with a **non-zero exit code**, a `HookFailedException` is thrown and the lifecycle operation is aborted (for pre-hooks) or a warning is displayed (for post-hooks on `project:up`).
- Scripts receive **no arguments**. Use `dde` commands inside scripts to interact with containers.

## Skipping Hooks

The `--skip-hooks` flag is available on these commands:

- `dde project:up --skip-hooks`
- `dde project:down --skip-hooks`
- `dde project:restart --skip-hooks`
- `dde project:update --skip-hooks`

When `--skip-hooks` is set, the `HookSubscriber` checks `$event->skipHooks` and returns early without executing any hook scripts.

## Examples

### Load fixtures after containers start

```bash
#!/usr/bin/env bash
# .dde/hooks/project.up.post/01-load-fixtures.sh
set -euo pipefail
dde project:exec -s web php bin/console doctrine:fixtures:load --no-interaction
```

### Run database migrations on startup

```bash
#!/usr/bin/env bash
# .dde/hooks/project.up.post/02-migrate.sh
set -euo pipefail
dde project:exec -s web php bin/console doctrine:migrations:migrate --no-interaction
```

### Install dependencies before containers start

```bash
#!/usr/bin/env bash
# .dde/hooks/project.up.pre/01-check-env.sh
set -euo pipefail
if [ ! -f .env.local ]; then
    cp .env .env.local
    echo "Created .env.local from .env"
fi
```

### Clear cache on shutdown

```bash
#!/usr/bin/env bash
# .dde/hooks/project.down.pre/01-clear-cache.sh
set -euo pipefail
dde project:exec -s web php bin/console cache:clear || true
```

## Error Handling

When a hook fails (non-zero exit code), the error message includes the script filename, exit code, and stderr output:

```
Hook "01-load-fixtures.sh" failed with exit code 1: <stderr output>
```

For **pre-hooks** (`project.up.pre`, `project.down.pre`), a failure aborts the entire operation. For **post-hooks** on `project:up`, a failure produces a warning but the project remains running.

## Implementation Details

Hooks are driven by Symfony events:

| Event | Hook directory |
|-------|---------------|
| `ProjectUpPreEvent` | `project.up.pre` |
| `ProjectUpPostEvent` | `project.up.post` |
| `ProjectDownPreEvent` | `project.down.pre` |
| `ProjectDownPostEvent` | `project.down.post` |

The `HookSubscriber` listens to these events and delegates to `HookRunner`, which uses Symfony Finder to locate scripts and Symfony Process to execute them.

## Related

- [Plugins](plugins.md) -- extend dde with custom commands
- [Project Lifecycle](../getting-started/commands.md) -- commands that trigger hooks
