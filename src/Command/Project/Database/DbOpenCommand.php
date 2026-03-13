<?php

declare(strict_types=1);

namespace App\Command\Project\Database;

use App\Command\AbstractDatabaseCommand;
use App\Database\DatabaseAdapterRegistry;
use App\Manager\ConfigManager;
use App\Manager\DatabaseManager;
use App\Output\FormatterResolver;
use App\Service\ServiceRegistry;
use App\Util\UrlOpenerUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'project:db:open',
    description: 'Open the database in an external client',
)]
final class DbOpenCommand extends AbstractDatabaseCommand
{
    public function __construct(
        ConfigManager $configManager,
        FormatterResolver $formatterResolver,
        private readonly DatabaseManager $databaseManager,
        private readonly DatabaseAdapterRegistry $adapterRegistry,
        private readonly ServiceRegistry $serviceRegistry,
        private readonly UrlOpenerUtil $urlOpener,
    ) {
        parent::__construct($configManager, $formatterResolver);
    }

    protected function configure(): void
    {
        $this
            ->addOption('service', 's', InputOption::VALUE_REQUIRED, 'Database service name (default: first configured DB service)')
            ->addOption('database', 'd', InputOption::VALUE_REQUIRED, 'Database name (default: project name)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = $this->resolveFormatter($output, $input);

        try {
            $config = $this->getResolvedConfig();
        } catch (\RuntimeException $runtimeException) {
            return $formatter->error($runtimeException->getMessage());
        }

        try {
            $serviceDefinition = $this->resolveDbService($input, $config, $this->serviceRegistry);
        } catch (\RuntimeException $runtimeException) {
            return $formatter->error($runtimeException->getMessage());
        }

        $containerName = $this->databaseManager->resolveContainerName($serviceDefinition);

        if (! $this->databaseManager->isContainerRunning($serviceDefinition)) {
            return $formatter->error(sprintf('Container "%s" is not running.', $containerName));
        }

        $database = $this->resolveDatabase($input, $config, $serviceDefinition->name);

        try {
            $adapter = $this->adapterRegistry->getAdapter($serviceDefinition->name);
        } catch (\InvalidArgumentException $invalidArgumentException) {
            return $formatter->error($invalidArgumentException->getMessage());
        }

        $hostPort = $this->databaseManager->resolveHostPort($serviceDefinition);

        $url = $adapter->getDsn('127.0.0.1', $hostPort, $database);

        if (!$formatter->isInteractive()) {
            return $formatter->success([
                'status' => 'ok',
                'url' => $url,
            ]);
        }

        $output->writeln(sprintf('Opening <info>%s</info>...', $url));

        if (! $this->urlOpener->open($url)) {
            $output->writeln(sprintf('<comment>Could not open client automatically. URL: %s</comment>', $url));
        }

        return self::SUCCESS;
    }
}
