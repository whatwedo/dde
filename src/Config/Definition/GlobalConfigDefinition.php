<?php

declare(strict_types=1);

namespace App\Config\Definition;

use App\Output\OutputFormat;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class GlobalConfigDefinition implements ConfigurationInterface
{
    public const string OUTPUT = 'text';

    public const array DNS_FORWARD = ['9.9.9.9', '149.112.112.112'];

    public const array SSH_KEYS = [];

    /**
     * @return list<string>
     */
    public static function supportedOutputs(): array
    {
        return array_column(OutputFormat::cases(), 'value');
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
