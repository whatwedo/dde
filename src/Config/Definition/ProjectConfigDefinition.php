<?php

declare(strict_types=1);

namespace App\Config\Definition;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class ProjectConfigDefinition implements ConfigurationInterface
{
    public const array SUPPORTED_SERVICES = ['mariadb', 'postgres', 'valkey', 'mailpit'];

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('dde_project');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('name')
                    ->defaultValue('')
                ->end()
                ->arrayNode('services')
                    ->defaultValue([])
                    ->beforeNormalization()
                        ->always(static function (mixed $v): mixed {
                            if (! is_array($v)) {
                                return $v;
                            }

                            return array_map(static function (mixed $entry): mixed {
                                if (is_string($entry)) {
                                    return [
                                        'name' => $entry,
                                        'version' => 'latest',
                                    ];
                                }

                                return $entry;
                            }, $v);
                        })
                    ->end()
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('name')
                                ->isRequired()
                                ->validate()
                                    ->ifTrue(static fn (mixed $v): bool => ! in_array($v, self::SUPPORTED_SERVICES, true))
                                    ->thenInvalid(sprintf('Invalid service name "%%s". Supported services: %s', implode(', ', self::SUPPORTED_SERVICES)))
                                ->end()
                            ->end()
                            ->scalarNode('version')
                                ->defaultValue('latest')
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('containers')
                    ->defaultValue([])
                    ->normalizeKeys(false)
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->variablePrototype()->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
