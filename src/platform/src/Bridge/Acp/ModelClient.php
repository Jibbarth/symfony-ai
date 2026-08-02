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

use Amp\Cancellation;
use Amp\Future;
use Amp\Pipeline\Queue;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\Acp\Connection\ConnectionInterface;
use Symfony\AI\Platform\Bridge\Acp\Exception\ProtocolException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\RawResultInterface;

use function Amp\async;

/**
 * ACP model client using JsonRpcPeer.
 */
final class ModelClient implements ModelClientInterface
{
    private ?ConnectionInterface $connection = null;
    private ?Future $listenerFuture = null;
    private ?string $sessionId = null;
    private bool $handshakeDone = false;

    /**
     * @var array<string, mixed>
     */
    private array $agentCapabilities = [];

    /**
     * @var array<string, mixed>
     */
    private array $agentInfo = [];

    private ?Queue $notificationQueue = null;

    /**
     * @var \Closure(PermissionRequest): ?string|null
     */
    private ?\Closure $onPermissionRequest;

    /**
     * @param array<string, string> $environment
     */
    public function __construct(
        private readonly string $command,
        private readonly ?string $workingDirectory = null,
        private readonly array $environment = [],
        private readonly LoggerInterface $logger = new NullLogger(),
        ?ConnectionInterface $connection = null,
        ?callable $onPermissionRequest = null,
    ) {
        $this->connection = $connection;
        $this->onPermissionRequest = null === $onPermissionRequest ? null : \Closure::fromCallable($onPermissionRequest);
    }

    public function supports(Model $model): bool
    {
        return $model instanceof Acp;
    }

    public function request(Model $model, array|string $payload, array $options = []): RawResultInterface
    {
        if (!$model instanceof Acp) {
            throw new ProtocolException(\sprintf('Unsupported model "%s".', $model::class));
        }

        $this->ensurePeer();
        $this->startListener();

        if (!$this->handshakeDone) {
            $this->performHandshake($model, $options);
        }

        $prompt = $this->normalizePrompt($payload);

        $queue = new Queue(1024);
        $this->notificationQueue = $queue;

        $future = $this->connection->request('session/prompt', [
            'sessionId' => $this->sessionId,
            'prompt' => $prompt,
        ]);
        $future->finally(static function () use ($queue): void {
            $queue->complete();
        })->ignore();

        return new RawProcessResult($future, $this->logger, $queue);
    }

    public function close(): void
    {
        if (null === $this->connection) {
            return;
        }

        if (null !== $this->sessionId && ($this->agentCapabilities['sessionCapabilities']['close'] ?? null) !== null) {
            try {
                $this->connection->request('session/close', ['sessionId' => $this->sessionId])->await();
            } catch (\Throwable) {
            }
        }

        $this->connection->close();
        if (null !== $this->listenerFuture) {
            try {
                $this->listenerFuture->await();
            } catch (\Throwable) {
            }
        }

        $this->connection = null;
        $this->listenerFuture = null;
        $this->sessionId = null;
        $this->handshakeDone = false;
        $this->agentCapabilities = [];
        $this->agentInfo = [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getAgentCapabilities(): array
    {
        return $this->agentCapabilities;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAgentInfo(): array
    {
        return $this->agentInfo;
    }

    private function ensurePeer(): void
    {
        $this->connection ??= new Connection\ProcessConnection(
            $this->command,
            $this->workingDirectory,
            $this->environment,
            $this->logger,
        );
        if (!$this->connection->isRunning()) {
            $this->connection->start();
        }

        $this->connection->onNotification('status', function (array $params): void {
            $this->logger->info('ACP agent status', ['status' => $params['status'] ?? 'unknown']);
        });

        $this->connection->onNotification('session/update', function (array $params): void {
            if (null !== $this->notificationQueue) {
                $this->notificationQueue->push([
                    'jsonrpc' => '2.0',
                    'method' => 'session/update',
                    'params' => $params,
                ]);
            }
        });

        $this->connection->onNotification('agent/messageStream', function (array $params): void {
            if (null !== $this->notificationQueue) {
                $this->notificationQueue->push([
                    'jsonrpc' => '2.0',
                    'method' => 'agent/messageStream',
                    'params' => $params,
                ]);
            }
        });

        $this->connection->onRequest('session/request_permission', function (array $params, Cancellation $cancellation): array {
            return $this->handlePermissionRequest($params);
        });
    }

    private function startListener(): void
    {
        if (null !== $this->listenerFuture) {
            return;
        }

        $this->listenerFuture = async(fn () => $this->connection->listen());
    }

    /**
     * @param array<string, mixed> $options
     */
    private function performHandshake(Acp $model, array $options): void
    {
        $this->logger->info('ACP handshake initializing');

        $initializeResult = $this->connection->request('initialize', [
            'protocolVersion' => $model->protocolVersion,
            'clientCapabilities' => (object) $model->clientCapabilities,
            'clientInfo' => [
                'name' => 'symfony-ai',
                'title' => 'Symfony AI',
                'version' => '0.1.0',
            ],
        ])->await();

        $protocolVersion = $initializeResult['protocolVersion'] ?? null;
        if (!\is_int($protocolVersion) || $protocolVersion < 1) {
            throw new ProtocolException('ACP returned an unsupported protocol version.');
        }

        $model->protocolVersion = min($model->protocolVersion, $protocolVersion);
        $this->agentCapabilities = \is_array($initializeResult['agentCapabilities'] ?? null) ? $initializeResult['agentCapabilities'] : [];
        $this->agentInfo = \is_array($initializeResult['agentInfo'] ?? null) ? $initializeResult['agentInfo'] : [];

        $missingCapabilities = array_diff($model->requiredAgentCapabilities, array_keys(array_filter($this->agentCapabilities)));
        if ([] !== $missingCapabilities) {
            throw new ProtocolException(\sprintf('ACP agent is missing required capabilities: "%s".', implode(', ', $missingCapabilities)));
        }

        $params = [
            'cwd' => $this->resolveWorkingDirectory($options),
            'mcpServers' => [],
        ];

        if (isset($options['additionalDirectories']) && [] !== $options['additionalDirectories']) {
            $params['additionalDirectories'] = $options['additionalDirectories'];
        }

        $sessionResult = $this->connection->request('session/new', $params)->await();
        $sessionId = $sessionResult['sessionId'] ?? null;

        if (!\is_string($sessionId) || '' === $sessionId) {
            throw new ProtocolException('ACP did not return a sessionId.');
        }

        $this->sessionId = $sessionId;
        $this->handshakeDone = true;

        $this->logger->info('ACP handshake complete', ['sessionId' => $sessionId]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function handlePermissionRequest(array $params): array
    {
        $request = PermissionRequest::fromParams($params);

        $this->logger->info('Permission requested', [
            'toolCallId' => $request->toolCallId,
            'title' => $request->title,
            'kind' => $request->kind,
        ]);

        if (null !== $this->onPermissionRequest) {
            $optionId = ($this->onPermissionRequest)($request);
            if (null !== $optionId) {
                return ['outcome' => ['outcome' => 'selected', 'optionId' => $optionId]];
            }
        }

        return ['outcome' => ['outcome' => 'denied', 'optionId' => '']];
    }

    /**
     * @param array<string, mixed>|string $payload
     *
     * @return list<array<string, mixed>>
     */
    private function normalizePrompt(array|string $payload): array
    {
        if (\is_string($payload)) {
            return [['type' => 'text', 'text' => $payload]];
        }

        $prompt = $payload['prompt'] ?? null;
        if (\is_array($prompt)) {
            return $this->normalizePromptEntries($prompt, $payload);
        }

        $messages = $payload['messages'] ?? null;
        if (\is_array($messages)) {
            return $this->normalizeMessages($messages, $payload);
        }

        return [['type' => 'text', 'text' => json_encode($payload, \JSON_THROW_ON_ERROR)]];
    }

    /**
     * @param list<mixed>  $prompt
     * @param array<mixed> $fallbackPayload
     *
     * @return list<array<string, mixed>>
     */
    private function normalizePromptEntries(array $prompt, array $fallbackPayload): array
    {
        $normalized = [];
        foreach ($prompt as $entry) {
            if (\is_string($entry)) {
                $normalized[] = ['type' => 'text', 'text' => $entry];
                continue;
            }

            if (!\is_array($entry)) {
                continue;
            }

            $type = $entry['type'] ?? 'text';
            if ('text' === $type && isset($entry['text']) && \is_string($entry['text'])) {
                $normalized[] = ['type' => 'text', 'text' => $entry['text']];
                continue;
            }

            $normalized[] = $entry;
        }

        if ([] !== $normalized) {
            return $normalized;
        }

        return [['type' => 'text', 'text' => json_encode($fallbackPayload, \JSON_THROW_ON_ERROR)]];
    }

    /**
     * @param list<mixed>  $messages
     * @param array<mixed> $fallbackPayload
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeMessages(array $messages, array $fallbackPayload): array
    {
        $parts = [];

        foreach ($messages as $message) {
            if (!\is_array($message)) {
                continue;
            }

            $text = $this->normalizeMessageContent($message['content'] ?? null);
            if (null === $text) {
                continue;
            }

            $role = $message['role'] ?? 'user';
            $parts[] = ['type' => 'text', 'text' => \sprintf('[%s] %s', $role, $text)];
        }

        if ([] !== $parts) {
            return $parts;
        }

        return [['type' => 'text', 'text' => json_encode($fallbackPayload, \JSON_THROW_ON_ERROR)]];
    }

    private function normalizeMessageContent(mixed $content): ?string
    {
        if (\is_string($content)) {
            return $content;
        }

        if (!\is_array($content)) {
            return null;
        }

        $parts = [];
        foreach ($content as $part) {
            if (\is_string($part)) {
                $parts[] = $part;
                continue;
            }

            if (!\is_array($part)) {
                continue;
            }

            if (($part['type'] ?? null) === 'text' && \is_string($part['text'] ?? null)) {
                $parts[] = $part['text'];
            }
        }

        if ([] === $parts) {
            return null;
        }

        return implode("\n", $parts);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveWorkingDirectory(array $options): string
    {
        $cwd = $options['cwd'] ?? $this->workingDirectory ?? getcwd();

        if (!\is_string($cwd) || '' === $cwd) {
            throw new TransportException('ACP working directory must be a non-empty string.');
        }

        return $cwd;
    }
}
