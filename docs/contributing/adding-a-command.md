---
title: "Adding a Command"
---


This guide walks through adding a new CLI command to dde.

## Step 1: Create the Command Class

Create a new class in the appropriate namespace:

- `App\Command\Project\` for project-scoped commands
- `App\Command\System\` for system-wide commands

```php
<?php

declare(strict_types=1);

namespace App\Command\Project;

use App\Command\AbstractProjectCommand;
use App\Manager\ConfigManager;
use App\Output\FormatterResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'project:example',
    description: 'An example project command',
)]
final class ProjectExampleCommand extends AbstractProjectCommand
{
    public function __construct(
        ConfigManager $configManager,
        FormatterResolver $formatterResolver,
    ) {
        parent::__construct($configManager, $formatterResolver);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = $this->resolveFormatter($output, $input);
        $config = $this->getResolvedConfig();
        $projectDir = $this->getProjectDirectory();

        // Your logic here -- delegate to a manager or service

        return $formatter->success(['message' => 'Done']);
    }
}
```

## Step 2: Use the `#[AsCommand]` Attribute

The `#[AsCommand]` attribute registers the command with Symfony. No additional YAML or service configuration is needed thanks to autowiring.

Required properties:

- `name`: the command name (e.g. `project:example` or `system:example`)
- `description`: a brief description shown in `dde list`

## Step 3: Extend the Right Base Class

| Base Class | When to Use |
|-----------|-------------|
| `AbstractProjectCommand` | Commands that operate on a project (need project directory, config) |
| `AbstractSystemCommand` | Commands that operate on the dde system (no project context needed) |

`AbstractProjectCommand` provides:

- `getProjectDirectory()` -- finds and returns the project root
- `getResolvedConfig()` -- loads and merges global + project configuration
- `getProjectConfig()` -- loads project configuration only
- `resolveDbService()` -- resolves a database service from config
- `resolveDatabase()` -- resolves the database name

`AbstractBaseCommand` (parent of both) provides:

- `resolveFormatter()` -- returns the configured `OutputFormatterInterface` (text or JSON)

## Step 4: Implement `execute()`

Keep the command thin. Business logic belongs in managers (`App\Manager\`) or services (`App\Service\`). The command should:

1. Resolve the output formatter
2. Gather input (options, arguments)
3. Call a manager method
4. Return results via the formatter

Use `$formatter->success()` for successful results and `$formatter->error()` for errors. Both return an integer exit code suitable for returning from `execute()`.

## Step 5: Write a Unit Test

Create a test at `tests/Unit/Command/Project/ProjectExampleCommandTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command\Project;

use PHPUnit\Framework\TestCase;

final class ProjectExampleCommandTest extends TestCase
{
    public function testExecuteReturnsSuccess(): void
    {
        // Test your command's behavior with mocked dependencies
    }
}
```

## Step 6: Run QA

```bash
make qa
```

Ensure ECS, PHPStan, Rector, and all tests pass before submitting.

## Checklist

- [ ] Class created in correct namespace (`Project\` or `System\`)
- [ ] `#[AsCommand]` attribute with name and description
- [ ] Extends `AbstractProjectCommand` or `AbstractSystemCommand`
- [ ] `declare(strict_types=1)` at the top
- [ ] Business logic delegated to a manager (not in the command)
- [ ] Uses `resolveFormatter()` for output
- [ ] Unit test written
- [ ] `make qa` passes
