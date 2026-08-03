<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\Acp;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\Acp\Connection\ProcessConnection;
use Symfony\AI\Platform\Bridge\Acp\Connection\SocketConnection;
use Symfony\AI\Platform\Bridge\Acp\Exception\TransportException;
use Symfony\AI\Platform\Contract;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\ModelRouter\CatalogBasedModelRouter;
use Symfony\AI\Platform\ModelRouterInterface;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Factory for creating ACP provider and platform.
 */
class Factory
{
    /**
     * @param non-empty-string      $name
     * @param array<string, string> $environment
     */
    public static function createProvider(
        string $name = 'acp',
        ?string $command = null,
        ?string $workingDirectory = null,
        array $environment = [],
        ?LoggerInterface $logger = null,
        ModelCatalogInterface $modelCatalog = new ModelCatalog(),
        ?Contract $contract = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        string $connectionType = 'process',
        ?string $host = null,
        ?int $port = null,
        ?callable $onPermissionRequest = null,
    ): ProviderInterface {
        $logger ??= new NullLogger();
        if ('socket' === $connectionType) {
            if (null === $host || null === $port) {
                throw new TransportException('ACP socket connection requires both "host" and "port".');
            }
            $connection = new SocketConnection(\sprintf('tcp://%s:%d', $host, $port), $logger);
        } else {
            $connection = new ProcessConnection(
                trim($command ?? ''),
                $workingDirectory,
                $environment,
                $logger,
            );
        }

        $modelClient = new ModelClient(
            command: $command ?? '',
            workingDirectory: $workingDirectory,
            environment: $environment,
            logger: $logger,
            connection: $connection,
            onPermissionRequest: $onPermissionRequest,
        );

        return new Provider(
            $name,
            [$modelClient],
            [new ResultConverter()],
            $modelCatalog,
            $contract ?? Contract::create(),
            $eventDispatcher,
        );
    }

    /**
     * @param non-empty-string      $name
     * @param array<string, string> $environment
     */
    public static function createPlatform(
        string $name = 'acp',
        ?string $command = null,
        ?string $workingDirectory = null,
        array $environment = [],
        ?LoggerInterface $logger = null,
        ModelCatalogInterface $modelCatalog = new ModelCatalog(),
        ?Contract $contract = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?ModelRouterInterface $modelRouter = null,
        string $connectionType = 'process',
        ?string $host = null,
        ?int $port = null,
        ?callable $onPermissionRequest = null,
    ): Platform {
        return new Platform(
            [self::createProvider($name, $command, $workingDirectory, $environment, $logger, $modelCatalog, $contract, $eventDispatcher, $connectionType, $host, $port, $onPermissionRequest)],
            $modelRouter ?? new CatalogBasedModelRouter(),
            $eventDispatcher,
        );
    }
}
