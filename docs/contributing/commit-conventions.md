---
title: "Commit Conventions"
---


dde uses [Conventional Commits](https://www.conventionalcommits.org/). Every commit is GPG-signed and signed-off:

```bash
git commit -S --signoff -m "feat(project): add hostname aliases to project:describe"
```

Never use `--no-verify`, `--no-gpg-sign`, or any other hook-skipping flag. Never add `Co-Authored-By:` trailers.

## Message Format

Examples: `feat(project):`, `fix(config):`, `test(manager):`, `docs(commands):`, `chore(ci):`

- **Subject**: lowercase, imperative, meta-information belongs in type+scope. The subject alone must stand on its own — it must not rely on the type/scope to complete the sentence.
- **Body**: explain the *why*, not the *what* — 1–2 sentences (effect + motivation). Never a multi-paragraph essay, never a file-by-file walkthrough; the diff already carries the *what*.
- Regression-fix commits should mention the regression in the body so the rationale survives in the git history.

## Choosing the Type

| Type       | Use for                                                                                                                                                                                                                                                                           |
| ---------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `feat`     | **Reserved for changes visible to end-users of dde** — a new command, flag, or automatic behaviour they experience. Internal utilities, new framework classes, refactor-enablers, internal APIs are **not** features. Be strict: if a user would not notice it, it is not `feat`. |
| `fix`      | User-visible bug fixes.                                                                                                                                                                                                                                                           |
| `perf`     | Same behaviour, measurably faster or lighter.                                                                                                                                                                                                                                     |
| `refactor` | The surface stays the same, the implementation moves (renaming, extraction, delegation).                                                                                                                                                                                          |
| `chore`    | Everything that doesn't belong elsewhere: new internal classes without behaviour change, tooling tweaks, formatting that isn't pure `style`.                                                                                                                                      |
| `style`    | Pure whitespace/formatting only (no code-visible behaviour). Prefer `chore` when unsure.                                                                                                                                                                                          |
| `test`     | Test additions, renames, scope changes. Use `test(e2e):` / `test(unit):` to disambiguate when relevant.                                                                                                                                                                           |
| `docs`     | Documentation-only changes.                                                                                                                                                                                                                                                       |

`feat`, `fix`, and `perf` commits require a `CHANGELOG.md` entry in the same branch.

## Branch History

Keep the history on a feature branch clean:

- Amend or surgically rebase a correction into the commit that introduced the bug/feature; do not append fix-up commits.
- A fix for a regression the branch itself introduced is folded back into the introducing commit, so it never becomes a phantom changelog entry.
- Use cherry-pick chains instead of `git rebase -i` (interactive rebase is unavailable to agents).
- `make qa` must stay green after every commit, including mid-branch checkouts (bisectability). Rebase with `--exec "make qa"` when in doubt.
- Force-push only with `--force-with-lease`.

## What Never Gets Committed

Plan and spec files under `.claude/plans/` and `.claude/specs/` are gitignored (along with `.claude/worktree/` and `.claude/worktrees/` for scratch worktrees) and must never be committed.
