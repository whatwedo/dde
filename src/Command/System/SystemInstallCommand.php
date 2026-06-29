<?php

declare(strict_types=1);

namespace App\Command\System;

use App\Command\AbstractSystemCommand;
use App\Config\SshAgentMode;
use App\Manager\ClaudeCodeManager;
use App\Manager\CompletionManager;
use App\Manager\GlobalConfigManager;
use App\Manager\MkcertManager;
use App\Output\FormatterResolver;
use App\Output\OutputFormatterInterface;
use App\Service\DnsmasqService;
use App\Service\SshAgentService;
use App\Service\TraefikService;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'system:install',
    description: 'Install and configure the dde system',
)]
final class SystemInstallCommand extends AbstractSystemCommand
{
    public function __construct(
        private readonly MkcertManager $mkcertManager,
        private readonly DnsmasqService $dnsmasqService,
        private readonly TraefikService $traefikService,
        private readonly SshAgentService $sshAgentService,
        private readonly CompletionManager $completionManager,
        private readonly ClaudeCodeManager $claudeCodeManager,
        private readonly GlobalConfigManager $globalConfigManager,
        private readonly string $configDir,
        FormatterResolver $formatterResolver,
    ) {
        parent::__construct($formatterResolver);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = $this->resolveFormatter($output, $input);
        $io = new SymfonyStyle($input, $output);

        if ($formatter->isInteractive()) {
            $io->title('dde System Installation');
        }

        $results = [];
        $hasErrors = false;

        // Step 1: Install mkcert root CA
        $results[] = $this->runStep($io, $formatter, 'mkcert', 'Installing mkcert root CA', function (): void {
            if (! $this->mkcertManager->isMkcertInstalled()) {
                throw new \RuntimeException('mkcert is not installed. Install it via: brew install mkcert (macOS) or apt install mkcert (Linux)');
            }

            $this->mkcertManager->install();
        }, $hasErrors);

        // Step 2: Generate default wildcard certificate for *.test plus the
        // dedicated certificate for system hostnames (mail.test, traefik.test)
        $results[] = $this->runStep($io, $formatter, 'default-cert', 'Generating TLS certificates', function (): void {
            $this->mkcertManager->ensureDefaultCertificate();
            $this->mkcertManager->ensureSystemCertificate();
        }, $hasErrors);

        // Step 3: Ensure Docker network
        $results[] = $this->runStep($io, $formatter, 'network', 'Creating Docker network', function (): void {
            $this->traefikService->ensureNetwork();
        }, $hasErrors);

        // Step 4: Configure and start dnsmasq
        $results[] = $this->runStep($io, $formatter, 'dnsmasq', 'Configuring DNS resolver', function (): void {
            $this->dnsmasqService->ensureConfig();
            $this->dnsmasqService->configureDns();
        }, $hasErrors);

        $results[] = $this->runStep($io, $formatter, 'dnsmasq', 'Starting dnsmasq', function (): void {
            $this->dnsmasqService->start();
        }, $hasErrors);

        // Step 5: Start Traefik
        $results[] = $this->runStep($io, $formatter, 'traefik', 'Starting Traefik reverse proxy', function (): void {
            $this->traefikService->start();
        }, $hasErrors);

        // Step 6: Start SSH-Agent — only in managed mode. In host mode dde forwards
        // the developer's host agent and runs no managed dde-ssh-agent container.
        if ($this->globalConfigManager->load()->sshAgentMode === SshAgentMode::Managed) {
            $results[] = $this->runStep($io, $formatter, 'ssh-agent', 'Starting SSH agent', function (): void {
                $this->sshAgentService->start();
            }, $hasErrors);
        }

        // Step 7: Shell completion
        $application = $this->getApplication();
        \assert($application instanceof Application);
        $results[] = $this->runStep($io, $formatter, 'shell-completion', 'Installing shell completion', function () use ($application): void {
            $this->completionManager->installCompletion($this->configDir, $application);
        }, $hasErrors);

        // Step 8: Claude Code skill (only if Claude Code is installed)
        if ($this->claudeCodeManager->isClaudeCodeInstalled()) {
            $results[] = $this->runStep($io, $formatter, 'claude-code', 'Installing Claude Code skill', function (): void {
                $this->claudeCodeManager->installSkill();
            }, $hasErrors);
        }

        if (!$formatter->isInteractive()) {
            if ($hasErrors) {
                $errorMessages = array_map(
                    static fn (array $r): string => sprintf('%s: %s', $r['step'], $r['message'] ?? 'unknown error'),
                    array_filter($results, static fn (array $r): bool => $r['status'] === 'error'),
                );

                return $formatter->error('Installation completed with errors', array_values($errorMessages));
            }

            return $formatter->success([
                'steps' => $results,
            ]);
        }

        $io->newLine();

        if ($hasErrors) {
            $io->warning('Installation completed with errors. Review the output above and fix any issues.');

            return Command::FAILURE;
        }

        $io->success('dde system has been installed and configured successfully.');

        return Command::SUCCESS;
    }

    /**
     * @return array{step: string, status: string, message: string|null}
     */
    private function runStep(SymfonyStyle $io, OutputFormatterInterface $formatter, string $step, string $label, \Closure $callback, bool &$hasErrors): array
    {
        if ($formatter->isInteractive()) {
            $io->write(sprintf('  %s... ', $label));
        }

        try {
            $callback();

            if ($formatter->isInteractive()) {
                $io->writeln('<info>done</info>');
            }

            return [
                'step' => $step,
                'status' => 'ok',
                'message' => null,
            ];
        } catch (\Throwable $throwable) {
            $hasErrors = true;

            if ($formatter->isInteractive()) {
                $io->writeln(sprintf('<error>failed</error> — %s', $throwable->getMessage()));
            }

            return [
                'step' => $step,
                'status' => 'error',
                'message' => $throwable->getMessage(),
            ];
        }
    }
}
