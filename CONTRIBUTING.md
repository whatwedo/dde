# Contributing

## Commit Message Format

The repository follows the [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/) format.

Each commit message consists of a **header**, a **body** and a
**footer**. The header has a special format that includes a **type**, a
**scope** and a **subject**:

```
<type>(<scope>): <subject>
<BLANK LINE>
<body>
<BLANK LINE>
<footer>
```

The **header** is mandatory and the **scope** of the header is optional.

Any line of the commit message cannot be longer 100 characters! This
allows the message to be easier to read on GitLab as well as in various
git tools.

Samples:

```
docs(changelog): update changelog to beta.5
```

```
fix(release): need to depend on latest rxjs and zone.js

The version in our package.json gets copied to the one we publish, and users need the latest of these.
```

### Type

Must be one of the following:

-   **chore**: Changes that affect the build system or external
    dependencies (example scopes: gulp, broccoli, npm) or the CI.
-   **docs**: Documentation only changes
-   **feat**: A new feature
-   **fix**: A bug fix
-   **perf**: A code change that improves performance
-   **refactor**: A code change that neither fixes a bug nor adds a
    feature
-   **style**: Changes that do not affect the meaning of the code
    (white-space, formatting, missing semi-colons, etc)
-   **test**: Adding missing tests or correcting existing tests

### Scope

The scope should be the name of the section affected (as perceived by
the person reading the changelog generated from commit messages).

Examples:

-   **configuration**
-   **mail**
-   **commands**
-   **commands/shell**
-   **commands/stop**

### Subject

The subject contains a succinct description of the change:

-   use the imperative, present tense: "change" not "changed" nor
    "changes"
-   don't capitalize the first letter
-   no dot (.) at the end

### Body

Just as in the **subject**, use the imperative, present tense: "change"
not "changed" nor "changes". The body should include the motivation for
the change and contrast this with previous behavior.

### Footer

The footer should contain a full URL or reference to a ticket on GitHub.com
if the change happens in regards of an open issue.

**Breaking Changes** should start with the word `BREAKING CHANGE:` with
a space or two newlines. The rest of the commit message is then used for
this.
