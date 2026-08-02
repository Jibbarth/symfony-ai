<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\Acp\Tests;

use Amp\Future;
use Amp\NullCancellation;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\Acp\Acp;
use Symfony\AI\Platform\Bridge\Acp\Connection\ConnectionInterface;
use Symfony\AI\Platform\Bridge\Acp\Exception\CliNotFoundException;
use Symfony\AI\Platform\Bridge\Acp\ModelClient;
use Symfony\AI\Platform\Bridge\Acp\PermissionRequest;
use Symfony\AI\Platform\Bridge\Acp\RawProcessResult;
use Symfony\AI\Platform\Contract;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Model;

/**
 * @covers \Symfony\AI\Platform\Bridge\Acp\ModelClient
 */
final class ModelClientTest extends TestCase
{
    public function testSupportsAcpModel()
    {
        $client = new ModelClient('dummy', null, [], new NullLogger(), new FakeConnection());

        $this->assertTrue($client->supports(new Acp('acp-v1')));
    }

    public function testDoesNotSupportOtherModels()
    {
        $client = new ModelClient('dummy', null, [], new NullLogger(), new FakeConnection());

        $this->assertFalse($client->supports(new Model('other')));
    }

    public function testThrowsExceptionWhenCliNotFound()
    {
        $this->expectException(CliNotFoundException::class);
        $this->expectExceptionMessage('ACP binary "" was not found.');

        $client = new ModelClient('', null, [], new NullLogger());
        $client->request(new Acp('acp-v1'), 'Hello');
    }

    public function testRequestReturnsRawProcessResult()
    {
        $connection = new FakeConnection(
            requestResponses: [
                '{"jsonrpc":"2.0","id":1,"result":{"protocolVersion":1,"agentCapabilities":{},"agentInfo":{}}}',
                '{"jsonrpc":"2.0","id":2,"result":{"sessionId":"session-1"}}',
                '{"jsonrpc":"2.0","id":3,"result":{"stopReason":"end_turn"}}',
            ],
            serverMessages: []
        );
        $client = new ModelClient('dummy', null, [], new NullLogger(), $connection);

        $result = $client->request(new Acp('acp-v1'), 'Hello');

        $this->assertInstanceOf(RawProcessResult::class, $result);
    }

    public function testRequestSendsNormalizedStringPrompt()
    {
        $connection = new FakeConnection(
            requestResponses: [
                '{"jsonrpc":"2.0","id":1,"result":{"protocolVersion":1,"agentCapabilities":{},"agentInfo":{}}}',
                '{"jsonrpc":"2.0","id":2,"result":{"sessionId":"session-1"}}',
                '{"jsonrpc":"2.0","id":3,"result":{"stopReason":"end_turn"}}',
            ],
            serverMessages: []
        );
        $client = new ModelClient('dummy', null, [], new NullLogger(), $connection);

        $client->request(new Acp('acp-v1'), 'Hello');

        $messages = $connection->messages;
        $this->assertCount(3, $messages);
        $this->assertSame('session/prompt', $messages[2]['method']);
        $this->assertSame([['type' => 'text', 'text' => 'Hello']], $messages[2]['params']['prompt']);
    }

    public function testRequestSendsPromptArrayFromPayload()
    {
        $connection = new FakeConnection(
            requestResponses: [
                '{"jsonrpc":"2.0","id":1,"result":{"protocolVersion":1,"agentCapabilities":{},"agentInfo":{}}}',
                '{"jsonrpc":"2.0","id":2,"result":{"sessionId":"session-1"}}',
                '{"jsonrpc":"2.0","id":3,"result":{"stopReason":"end_turn"}}',
            ],
            serverMessages: []
        );
        $client = new ModelClient('dummy', null, [], new NullLogger(), $connection);

        $client->request(new Acp('acp-v1'), ['prompt' => ['What is PHP?']]);

        $messages = $connection->messages;
        $this->assertSame([['type' => 'text', 'text' => 'What is PHP?']], $messages[2]['params']['prompt']);
    }

    public function testRequestNormalizesMessageBagPayload()
    {
        $connection = new FakeConnection(
            requestResponses: [
                '{"jsonrpc":"2.0","id":1,"result":{"protocolVersion":1,"agentCapabilities":{},"agentInfo":{}}}',
                '{"jsonrpc":"2.0","id":2,"result":{"sessionId":"session-1"}}',
                '{"jsonrpc":"2.0","id":3,"result":{"stopReason":"end_turn"}}',
            ],
            serverMessages: []
        );
        $client = new ModelClient('dummy', null, [], new NullLogger(), $connection);
        $payload = Contract::create()->createRequestPayload(new Acp('acp-v1'), new MessageBag(
            Message::forSystem('You are helpful.'),
            Message::ofUser('What is Symfony?'),
        ));

        $client->request(new Acp('acp-v1'), $payload);

        $messages = $connection->messages;
        $this->assertSame([
            ['type' => 'text', 'text' => '[system] You are helpful.'],
            ['type' => 'text', 'text' => '[user] What is Symfony?'],
        ], $messages[2]['params']['prompt']);
    }

    public function testClientConsumesConnectionNotTransport()
    {
        $connection = new FakeConnection(
            requestResponses: [
                '{"jsonrpc":"2.0","id":1,"result":{"protocolVersion":1,"agentCapabilities":{},"agentInfo":{}}}',
                '{"jsonrpc":"2.0","id":2,"result":{"sessionId":"session-1"}}',
                '{"jsonrpc":"2.0","id":3,"result":{"stopReason":"end_turn"}}',
            ],
            serverMessages: []
        );
        $client = new ModelClient('dummy', null, [], new NullLogger(), $connection);

        $result = $client->request(new Acp('acp-v1'), 'Hello');

        $this->assertInstanceOf(RawProcessResult::class, $result);
        $this->assertTrue($connection->isRunning());
    }

    public function testRequestHandlesPermissionRequestBeforeResponse()
    {
        $connection = new FakeConnection(
            requestResponses: [
                '{"jsonrpc":"2.0","id":1,"result":{"protocolVersion":1,"agentCapabilities":{},"agentInfo":{}}}',
                '{"jsonrpc":"2.0","id":2,"result":{"sessionId":"session-1"}}',
                '{"jsonrpc":"2.0","id":3,"result":{"stopReason":"end_turn"}}',
            ],
            serverMessages: [
                '{"jsonrpc":"2.0","id":99,"method":"session/request_permission","params":{"sessionId":"session-1","toolCall":{"toolCallId":"call-1","title":"Delete file","kind":"delete","status":"pending"},"options":[{"optionId":"allow-once","name":"Allow once","kind":"allow_once"}]}}',
            ]
        );
        $client = new ModelClient('dummy', null, [], new NullLogger(), $connection, static fn (PermissionRequest $request): ?string => 'allow-once');

        $result = $client->request(new Acp('acp-v1'), 'Hello');
        $this->assertSame(['stopReason' => 'end_turn'], $result->getData());

        // The async listener already processed the permission request during handshake
        $this->assertSame(['outcome' => ['outcome' => 'selected', 'optionId' => 'allow-once']], $connection->messages[1]['result']);
        $this->assertSame(99, $connection->messages[1]['id']);
    }

    public function testRequestDeniesPermissionByDefault()
    {
        $connection = new FakeConnection(
            requestResponses: [
                '{"jsonrpc":"2.0","id":1,"result":{"protocolVersion":1,"agentCapabilities":{},"agentInfo":{}}}',
                '{"jsonrpc":"2.0","id":2,"result":{"sessionId":"session-1"}}',
                '{"jsonrpc":"2.0","id":3,"result":{"stopReason":"end_turn"}}',
            ],
            serverMessages: [
                '{"jsonrpc":"2.0","id":99,"method":"session/request_permission","params":{"sessionId":"session-1","toolCall":{"toolCallId":"call-1","title":"Delete file","kind":"delete","status":"pending"},"options":[{"optionId":"allow-once","name":"Allow once","kind":"allow_once"}]}}',
            ]
        );
        $client = new ModelClient('dummy', null, [], new NullLogger(), $connection);

        $result = $client->request(new Acp('acp-v1'), 'Hello');
        $this->assertSame(['stopReason' => 'end_turn'], $result->getData());

        // The async listener already processed the permission request during handshake
        $this->assertSame(['outcome' => ['outcome' => 'denied', 'optionId' => '']], $connection->messages[1]['result']);
        $this->assertSame(99, $connection->messages[1]['id']);
    }
}

final class FakeConnection implements ConnectionInterface
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $messages = [];

    /**
     * @var list<array<string, mixed>>
     */
    private array $requestResponses = [];

    /**
     * @var list<array<string, mixed>>
     */
    private array $serverMessages = [];

    private array $notificationHandlers = [];
    private array $requestHandlers = [];
    private bool $running = false;
    private int $requestId = 0;

    /**
     * @param list<string> $requestResponses Responses to client requests (initialize, session/new, session/prompt, etc.)
     * @param list<string> $serverMessages   Server-initiated messages (notifications, requests)
     */
    public function __construct(
        array $requestResponses = [],
        array $serverMessages = [],
    ) {
        foreach ($requestResponses as $response) {
            $this->requestResponses[] = json_decode($response, true, 512, \JSON_THROW_ON_ERROR);
        }
        foreach ($serverMessages as $message) {
            $this->serverMessages[] = json_decode($message, true, 512, \JSON_THROW_ON_ERROR);
        }
    }

    public function start(): void
    {
        $this->running = true;
    }

    public function request(string $method, array $params = []): Future
    {
        ++$this->requestId;
        $this->messages[] = ['method' => $method, 'params' => $params, 'id' => $this->requestId];

        // Return the next request response
        if ([] !== $this->requestResponses) {
            $response = array_shift($this->requestResponses);

            return Future::complete($response['result'] ?? []);
        }

        return Future::complete([]);
    }

    public function onNotification(string $method, callable $handler): void
    {
        $this->notificationHandlers[$method] = $handler;
    }

    public function onRequest(string $method, callable $handler): void
    {
        $this->requestHandlers[$method] = $handler;
    }

    public function listen(): void
    {
        // Process all server-initiated messages (notifications and requests)
        while ([] !== $this->serverMessages) {
            $message = array_shift($this->serverMessages);

            if (isset($message['method'])) {
                if (isset($message['id'])) {
                    // It's a request from server - invoke handler and send response
                    $handler = $this->requestHandlers[$message['method']] ?? null;
                    if (null !== $handler) {
                        $result = $handler($message['params'] ?? [], new NullCancellation());
                        $this->messages[] = ['id' => $message['id'], 'result' => $result];
                    }
                } else {
                    // It's a notification from server
                    $handler = $this->notificationHandlers[$message['method']] ?? null;
                    if (null !== $handler) {
                        $handler($message['params'] ?? []);
                    }
                }
            }
        }
    }

    public function close(): void
    {
        $this->running = false;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }
}
