<?php

declare(strict_types=1);

namespace App;

use App\Plugin\PluginCommandLoader;
use Symfony\Bundle\FrameworkBundle\Console\Application as BaseApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\HttpKernel\KernelInterface;

final class Application extends BaseApplication
{
    /**
     * Build-time placeholder substituted by `.github/workflows/build.yml`
     * (stable → git tag, nightly → short SHA). Public only because
     * `box compile` and the publish pipeline need a stable anchor; callers
     * MUST go through {@see Application::resolveVersion()} so an unbuilt
     * checkout reports `dev` instead of the raw token.
     */
    public const string APP_VERSION = '@APP_VERSION@';

    private const array ALLOWED_COMMANDS = ['about', 'completion', 'help', 'list'];

    private const array ALLOWED_PREFIXES = ['project:', 'system:'];

    private bool $pluginsRegistered = false;

    public function __construct(KernelInterface $kernel)
    {
        parent::__construct($kernel);

        $this->setName('dde');
        $this->setVersion(self::resolveVersion());
    }

    /**
     * @return array<string, Command>
     */
    public function all(?string $namespace = null): array
    {
        $commands = parent::all($namespace);

        return array_filter($commands, static function (string $name): bool {
            if (in_array($name, self::ALLOWED_COMMANDS, true)) {
                return true;
            }

            foreach (self::ALLOWED_PREFIXES as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    return true;
                }
            }

            return false;
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * Single source of truth for the binary version string at runtime.
     *
     * Reads APP_VERSION via Reflection (return type `mixed`) to keep the
     * comparison opaque to static analysers, which would otherwise collapse
     * it to a literal and treat the runtime fallback as dead code. In an
     * unbuilt checkout the constant still holds `@APP_VERSION@` — report
     * `dev` so `dde --version` (and any other caller) stays readable.
     */
    public static function resolveVersion(): string
    {
        $value = (new \ReflectionClassConstant(self::class, 'APP_VERSION'))->getValue();

        if (! is_string($value) || $value === '@APP_VERSION@') {
            return 'dev';
        }

        return $value;
    }

    protected function getDefaultInputDefinition(): InputDefinition
    {
        $definition = parent::getDefaultInputDefinition();

        $definition->addOption(new InputOption(
            'output',
            'o',
            InputOption::VALUE_REQUIRED,
            'Output format: text or json',
            'text',
        ));

        return $definition;
    }

    protected function registerCommands(): void
    {
        parent::registerCommands();

        if ($this->pluginsRegistered) {
            return;
        }

        $this->pluginsRegistered = true;

        try {
            /** @var PluginCommandLoader $pluginCommandLoader */
            $pluginCommandLoader = $this->getKernel()->getContainer()->get(PluginCommandLoader::class);
        } catch (ServiceNotFoundException | \LogicException | \InvalidArgumentException) {
            // Gracefully degrade when container is not available (e.g. outside of a project directory)
            return;
        }

        foreach ($pluginCommandLoader->getNames() as $commandName) {
            if (! $this->has($commandName)) {
                $this->addCommand($pluginCommandLoader->get($commandName));
            }
        }
    }
}
