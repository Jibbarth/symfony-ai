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
use Amp\Process\Process;
use Fabpot\JsonRpc\Exception\ConnectionClosedException;
use Fabpot\JsonRpc\JsonRpcDispatcher;
use Fabpot\JsonRpc\JsonRpcPeer;
use Fabpot\JsonRpc\PsrTrafficLogger;
use Fabpot\JsonRpc\StreamJsonRpcTransport;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\Acp\Exception\CliNotFoundException;
use Symfony\Component\Process\ExecutableFinder;

final class ProcessConnection implements ConnectionInterface
{
    private ?Process $process = null;
    private ?JsonRpcPeer $peer = null;
    private ?JsonRpcDispatcher $dispatcher = null;
    private bool $running = false;

    public function __construct(
        private readonly string $command,
        private readonly ?string $workingDirectory = null,
        private readonly array $environment = [],
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function start(): void
    {
        if ($this->running) {
            return;
        }

        $command = $this->buildCommand();
        $this->logger->info('Starting ACP process.', ['command' => $command]);
        $this->process = Process::start($command, $this->workingDirectory, $this->environment);
        $this->peer = new JsonRpcPeer(
            new StreamJsonRpcTransport($this->process->getStdout(), $this->process->getStdin()),
            new PsrTrafficLogger($this->logger),
        );
        $this->dispatcher = new JsonRpcDispatcher($this->peer);
        $this->running = true;
    }

    public function request(string $method, array $params = []): Future
    {
        if (!$this->running || null === $this->peer) {
            throw new ConnectionClosedException('ACP process is not started.');
        }

        return $this->peer->request($method, $params);
    }

    public function onNotification(string $method, callable $handler): void
    {
        if (!$this->running || null === $this->dispatcher) {
            throw new ConnectionClosedException('ACP process is not started.');
        }

        $this->dispatcher->onNotification($method, $handler);
    }

    public function onRequest(string $method, callable $handler): void
    {
        if (!$this->running || null === $this->dispatcher) {
            throw new ConnectionClosedException('ACP process is not started.');
        }

        $this->dispatcher->onRequest($method, $handler);
    }

    public function listen(): void
    {
        if (!$this->running || null === $this->peer) {
            throw new ConnectionClosedException('ACP process is not started.');
        }

        $this->peer->listen();
    }

    public function close(): void
    {
        if (!$this->running) {
            return;
        }

        $this->peer?->close();
        $this->process?->kill();
        $this->peer = null;
        $this->dispatcher = null;
        $this->process = null;
        $this->running = false;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    private function buildCommand(): array
    {
        $parts = preg_split('/\s+/', trim($this->command)) ?: [];
        if ([] === $parts) {
            throw new CliNotFoundException('ACP command cannot be empty.');
        }

        $binary = (new ExecutableFinder())->find($parts[0]);
        if (null === $binary) {
            throw new CliNotFoundException(\sprintf('ACP binary "%s" was not found.', $parts[0]));
        }

        $parts[0] = $binary;

        return $parts;
    }
}
