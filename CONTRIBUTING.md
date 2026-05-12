# Contributing

Thank you for your interest in contributing to dde!

Full contributor documentation lives at <https://dde.sh/contributing/development-setup/>:

- [Development setup](https://dde.sh/contributing/development-setup/) — prerequisites, sources-vs-built alias variants, build commands, project structure
- [Architecture overview](https://dde.sh/contributing/architecture/)
- [Testing](https://dde.sh/contributing/testing/)
- [Adding commands](https://dde.sh/contributing/adding-a-command/)
- [Adding services](https://dde.sh/contributing/adding-a-service/)
- [Adding adapters](https://dde.sh/contributing/adding-an-adapter/)
- [Release process](https://dde.sh/contributing/release-process/)

Before submitting a pull request, run `make qa` (ECS, PHPStan level 8, Rector dry-run, and all unit/integration tests) — see the [development setup guide](https://dde.sh/contributing/development-setup/) for details and individual tool invocations.
