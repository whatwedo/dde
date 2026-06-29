<?php

declare(strict_types=1);

namespace App\Config\Definition;

use App\Config\SshAgentMode;
use App\Output\OutputFormat;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class GlobalConfigDefinition implements ConfigurationInterface
{
    public const string OUTPUT = 'text';

    public const array DNS_FORWARD = ['9.9.9.9', '149.112.112.112'];

    public const array SSH_KEYS = [];

    public const string SSH_AGENT_MODE = SshAgentMode::Managed->value;

    public const ?string SSH_AGENT_SOURCE = null;

    /**
     * @return list<string>
     */
    public static function supportedOutputs(): array
    {
        return array_column(OutputFormat::cases(), 'value');
    }

    /**
     * @return list<string>
     */
    public static function supportedSshAgentModes(): array
    {
        return array_column(SshAgentMode::cases(), 'value');
    }

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('dde_global');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->enumNode('output')
                    ->values(self::supportedOutputs())
                    ->defaultValue(self::OUTPUT)
                ->end()
                ->scalarNode('default_browser')
                    ->defaultNull()
                    ->info('Executable used by project:open to open the project URL (e.g. /usr/bin/firefox). Empty uses the platform default.')
                ->end()
                ->arrayNode('dns')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('forward')
                            ->scalarPrototype()->end()
                            ->defaultValue(self::DNS_FORWARD)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('ssh')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('keys')
                            ->scalarPrototype()->end()
                            ->defaultValue(self::SSH_KEYS)
                        ->end()
                        ->arrayNode('agent')
                            ->addDefaultsIfNotSet()
                            ->info('SSH agent mode: "managed" runs dde\'s own agent container; "host" forwards the developer\'s host agent. Global-only setting.')
                            ->children()
                                ->enumNode('mode')
                                    ->values(self::supportedSshAgentModes())
                                    ->defaultValue(self::SSH_AGENT_MODE)
                                ->end()
                                ->scalarNode('source')
                                    ->defaultValue(self::SSH_AGENT_SOURCE)
                                    ->info('Host agent source in "host" mode: null/env (use the host SSH_AUTH_SOCK) or an explicit socket path.')
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('services')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('version')->isRequired()->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
