<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\Acp\Connection;

use Amp\Future;

interface ConnectionInterface
{
    public function start(): void;

    /**
     * @param array<string, mixed> $params
     *
     * @return Future<mixed>
     */
    public function request(string $method, array $params = []): Future;

    public function onNotification(string $method, callable $handler): void;

    public function onRequest(string $method, callable $handler): void;

    public function listen(): void;

    public function close(): void;

    public function isRunning(): bool;
}
