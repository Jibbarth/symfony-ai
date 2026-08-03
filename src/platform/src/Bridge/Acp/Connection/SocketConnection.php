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
use Amp\Socket\Socket;
use Fabpot\JsonRpc\Exception\ConnectionClosedException;
use Fabpot\JsonRpc\JsonRpcDispatcher;
use Fabpot\JsonRpc\JsonRpcPeer;
use Fabpot\JsonRpc\PsrTrafficLogger;
use Fabpot\JsonRpc\StreamJsonRpcTransport;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\Acp\Exception\TransportException;

final class SocketConnection implements ConnectionInterface
{
    private ?Socket $socket = null;
    private ?JsonRpcPeer $peer = null;
    private ?JsonRpcDispatcher $dispatcher = null;
    private bool $running = false;

    public function __construct(
        private readonly string $address,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function start(): void
    {
        if ($this->running) {
            return;
        }

        $this->logger->info('Connecting to ACP socket.', ['address' => $this->address]);

        try {
            $this->socket = \Amp\Socket\connect($this->address);
        } catch (\Throwable $e) {
            throw new TransportException(\sprintf('Failed to connect to ACP socket "%s".', $this->address), 0, $e);
        }

        $this->peer = new JsonRpcPeer(
            new StreamJsonRpcTransport($this->socket, $this->socket),
            new PsrTrafficLogger($this->logger),
        );
        $this->dispatcher = new JsonRpcDispatcher($this->peer);
        $this->running = true;
    }

    public function request(string $method, array $params = []): Future
    {
        if (!$this->running || null === $this->peer) {
            throw new ConnectionClosedException('ACP socket is not connected.');
        }

        return $this->peer->request($method, $params);
    }

    public function onNotification(string $method, callable $handler): void
    {
        if (!$this->running || null === $this->dispatcher) {
            throw new ConnectionClosedException('ACP socket is not connected.');
        }

        $this->dispatcher->onNotification($method, $handler);
    }

    public function onRequest(string $method, callable $handler): void
    {
        if (!$this->running || null === $this->dispatcher) {
            throw new ConnectionClosedException('ACP socket is not connected.');
        }

        $this->dispatcher->onRequest($method, $handler);
    }

    public function listen(): void
    {
        if (!$this->running || null === $this->peer) {
            throw new ConnectionClosedException('ACP socket is not connected.');
        }

        $this->peer->listen();
    }

    public function close(): void
    {
        if (!$this->running) {
            return;
        }

        $this->peer?->close();
        $this->socket = null;
        $this->peer = null;
        $this->dispatcher = null;
        $this->running = false;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }
}
