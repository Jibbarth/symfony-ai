<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Config\Definition\Configurator;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

return (new ArrayNodeDefinition('acp'))
    ->validate()
        ->ifTrue(static function (array $value): bool {
            if ('socket' === ($value['connection'] ?? 'process')) {
                return !(isset($value['host'], $value['port']));
            }

            return false;
        })
        ->thenInvalid('ACP socket connection requires both "host" and "port".')
    ->end()
    ->children()
        ->stringNode('name')
            ->defaultValue('acp')
            ->cannotBeEmpty()
        ->end()
        ->enumNode('connection')
            ->values(['process', 'socket'])
            ->defaultValue('process')
        ->end()
        ->stringNode('command')->end()
        ->stringNode('host')->end()
        ->integerNode('port')->min(1)->max(65535)->end()
        ->stringNode('working_directory')->end()
        ->scalarNode('permission_handler')
            ->info('Service id of a callable invoked for ACP session/request_permission decisions.')
        ->end()
        ->arrayNode('environment')
            ->useAttributeAsKey('name')
            ->scalarPrototype()->end()
        ->end()
    ->end();
