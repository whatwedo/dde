<?php

declare(strict_types=1);

namespace App\Command\System;

use App\Command\AbstractSystemCommand;
use App\Manager\DockerManager;
use App\Manager\GlobalConfigManager;
use App\Manager\SystemServiceManager;
use App\Model\ServiceStartStatus;
use App\Output\FormatterResolver;
use App\Service\ServiceRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'system:service:up',
    description: 'Start a system service manually',
)]
final class SystemServiceUpCommand extends AbstractSystemCommand
{
    public function __construct(
        private readonly SystemServiceManager $serviceManager,
        private readonly ServiceRegistry $serviceRegistry,
        private readonly DockerManager $dockerManager,
        private readonly GlobalConfigManager $globalConfigManager,
        FormatterResolver $formatterResolver,
    ) {
        parent::__construct($formatterResolver);
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('name')) {
            $suggestions->suggestValues(array_values($this->serviceRegistry->getAllServiceTypes()));
        }
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Service name (e.g. mariadb, valkey)')
            ->addOption('service-version', null, InputOption::VALUE_REQUIRED, 'Specific version to start');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = $this->resolveFormatter($output, $input);

        $name = $input->getArgument('name');

        if (! is_string($name)) {
            return $formatter->error('Invalid service name.');
        }

        if (! $this->serviceRegistry->isKnownService($name)) {
            $available = implode(', ', $this->serviceRegistry->getAllServiceTypes());

            return $formatter->error(
                sprintf('Unknown service "%s". Available services: %s', $name, $available),
            );
        }

        $versionOption = $input->getOption('service-version');
        $globalConfig = $this->globalConfigManager->load();

        $defaultVersion = $globalConfig->serviceVersions[$name] ?? $this->serviceRegistry->getServiceVersion($name);

        $resolvedVersion = is_string($versionOption) && $versionOption !== ''
            ? $versionOption
            : $defaultVersion;

        $isDefault = $resolvedVersion === $defaultVersion;

        $containerName = $this->serviceManager->getContainerName($name, $resolvedVersion);

        if ($this->dockerManager->isContainerRunning($containerName)) {
            $portBindings = $this->dockerManager->getContainerPorts($containerName);
            $port = $this->extractHostPort($portBindings) ?? $this->serviceRegistry->getServicePort($name);

            if (!$formatter->isInteractive()) {
                return $formatter->success([
                    'status' => ServiceStartStatus::ALREADY_RUNNING->value,
                    'container' => $containerName,
                    'port' => $port,
                    'service' => $name,
                    'version' => $resolvedVersion,
                ]);
            }

            $output->writeln(sprintf(
                '<info>Service %s:%s is already running (container: %s)</info>',
                $name,
                $resolvedVersion,
                $containerName,
            ));

            return Command::SUCCESS;
        }

        try {
            $this->serviceManager->startService($name, $resolvedVersion, $isDefault);
        } catch (\RuntimeException $runtimeException) {
            return $formatter->error(sprintf('Failed to start service "%s": %s', $name, $runtimeException->getMessage()));
        }

        $portBindings = $this->dockerManager->getContainerPorts($containerName);
        $port = $this->extractHostPort($portBindings) ?? $this->serviceRegistry->getServicePort($name);

        if (!$formatter->isInteractive()) {
            return $formatter->success([
                'status' => 'ok',
                'container' => $containerName,
                'service' => $name,
                'version' => $resolvedVersion,
                'port' => $port,
                'host' => '127.0.0.1',
            ]);
        }

        $output->writeln(sprintf(
            '<info>✓ Started service %s:%s → container %s (127.0.0.1:%d)</info>',
            $name,
            $resolvedVersion,
            $containerName,
            $port,
        ));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $portBindings
     */
    private function extractHostPort(array $portBindings): ?int
    {
        foreach ($portBindings as $bindings) {
            if (is_array($bindings)) {
                foreach ($bindings as $binding) {
                    if (isset($binding['HostPort']) && is_string($binding['HostPort'])) {
                        return (int) $binding['HostPort'];
                    }
                }
            }
        }

        return null;
    }
}
