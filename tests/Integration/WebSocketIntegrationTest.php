<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\WebSockets\Tests\Integration;

use HighPerApp\HighPer\WebSockets\Connection;
use HighPerApp\HighPer\WebSockets\IndexedBroadcaster;
use HighPerApp\HighPer\WebSockets\Message;
use HighPerApp\HighPer\WebSockets\WebSocketServer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[Group('integration')]
#[Group('websockets')]
class WebSocketIntegrationTest extends TestCase
{
    private WebSocketServer $server;

    private IndexedBroadcaster $broadcaster;

    private NullLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new NullLogger();
        $this->broadcaster = new IndexedBroadcaster();
        $this->server = new WebSocketServer([
            'host' => '127.0.0.1',
            'port' => 8080,
            'max_connections' => 100,
        ], $this->logger);
    }

    #[Test]
    #[TestDox('WebSocket server integrates with IndexedBroadcaster correctly')]
    public function testWebSocketServerIntegratesWithIndexedBroadcasterCorrectly(): void
    {
        $receivedMessages = [];

        // Set up subscriber
        $subscriber = static function ($message) use (&$receivedMessages): void {
            $receivedMessages[] = $message;
        };

        $subscriptionId = $this->broadcaster->subscribe('test-channel', $subscriber);

        // Broadcast message
        $testMessage = ['type' => 'test', 'data' => 'integration test'];
        $this->broadcaster->broadcast('test-channel', $testMessage);

        $this->assertCount(1, $receivedMessages);
        $this->assertEquals($testMessage, $receivedMessages[0]);

        // Test unsubscribe
        $this->assertTrue($this->broadcaster->unsubscribe('test-channel', $subscriptionId));

        // Broadcast again - should not receive
        $this->broadcaster->broadcast('test-channel', ['type' => 'test2']);
        $this->assertCount(1, $receivedMessages); // Still only 1 message
    }

    #[Test]
    #[TestDox('Multiple connections can send and receive messages simultaneously')]
    public function testMultipleConnectionsCanSendAndReceiveMessagesSimultaneously(): void
    {
        $connections = [];
        $receivedMessages = [];

        // Create multiple connections
        for ($i = 0; $i < 5; $i++) {
            $connection = new Connection("conn_{$i}");
            $connections[] = $connection;
            $this->server->addConnection($connection);
        }

        // Set up message handler
        $this->server->onMessage(static function (Connection $connection, Message $message) use (&$receivedMessages): void {
            $receivedMessages[$connection->getId()] = $message->getData();
        });

        // Send messages from each connection
        for ($i = 0; $i < 5; $i++) {
            $message = new Message(['from' => "conn_{$i}", 'text' => "Hello from connection {$i}"]);
            $this->server->handleMessage($connections[$i], $message);
        }

        $this->assertCount(5, $receivedMessages);

        for ($i = 0; $i < 5; $i++) {
            $this->assertArrayHasKey("conn_{$i}", $receivedMessages);
            $this->assertEquals("conn_{$i}", $receivedMessages["conn_{$i}"]['from']);
        }
    }

    #[Test]
    #[TestDox('Broadcasting works correctly with multiple channels')]
    public function testBroadcastingWorksCorrectlyWithMultipleChannels(): void
    {
        $channelMessages = [];

        // Subscribe to multiple channels
        $this->broadcaster->subscribe('channel-1', static function ($msg) use (&$channelMessages): void {
            $channelMessages['channel-1'][] = $msg;
        });

        $this->broadcaster->subscribe('channel-2', static function ($msg) use (&$channelMessages): void {
            $channelMessages['channel-2'][] = $msg;
        });

        $this->broadcaster->subscribe('channel-1', static function ($msg) use (&$channelMessages): void {
            $channelMessages['channel-1-sub2'][] = $msg;
        });

        // Broadcast to different channels
        $this->broadcaster->broadcast('channel-1', 'message for channel 1');
        $this->broadcaster->broadcast('channel-2', 'message for channel 2');

        $this->assertCount(2, $channelMessages['channel-1']);
        $this->assertCount(2, $channelMessages['channel-1-sub2']);
        $this->assertCount(1, $channelMessages['channel-2']);

        $this->assertEquals('message for channel 1', $channelMessages['channel-1'][0]);
        $this->assertEquals('message for channel 2', $channelMessages['channel-2'][0]);
    }

    #[Test]
    #[TestDox('WebSocket server handles connection lifecycle correctly')]
    public function testWebSocketServerHandlesConnectionLifecycleCorrectly(): void
    {
        $connectionEvents = [];

        $this->server->onConnect(static function (Connection $conn) use (&$connectionEvents): void {
            $connectionEvents[] = ['type' => 'connect', 'id' => $conn->getId()];
        });

        $this->server->onDisconnect(static function (Connection $conn) use (&$connectionEvents): void {
            $connectionEvents[] = ['type' => 'disconnect', 'id' => $conn->getId()];
        });

        // Create and add connection
        $connection = new Connection('lifecycle-test');
        $this->server->addConnection($connection);
        $this->server->handleConnect($connection);

        $this->assertEquals(1, $this->server->getConnectionCount());
        $this->assertTrue($this->server->hasConnection('lifecycle-test'));

        // Disconnect
        $this->server->removeConnection($connection);
        $this->server->handleDisconnect($connection);

        $this->assertEquals(0, $this->server->getConnectionCount());
        $this->assertFalse($this->server->hasConnection('lifecycle-test'));

        // Verify events
        $this->assertCount(2, $connectionEvents);
        $this->assertEquals('connect', $connectionEvents[0]['type']);
        $this->assertEquals('disconnect', $connectionEvents[1]['type']);
    }

    #[Test]
    #[TestDox('Middleware chain executes in correct order during message processing')]
    public function testMiddlewareChainExecutesInCorrectOrderDuringMessageProcessing(): void
    {
        $executionOrder = [];

        // Add middleware in order
        $this->server->addMiddleware(static function ($conn, $msg, $next) use (&$executionOrder) {
            $executionOrder[] = 'middleware-1-before';
            $result = $next($conn, $msg);
            $executionOrder[] = 'middleware-1-after';
            return $result;
        });

        $this->server->addMiddleware(static function ($conn, $msg, $next) use (&$executionOrder) {
            $executionOrder[] = 'middleware-2-before';
            $result = $next($conn, $msg);
            $executionOrder[] = 'middleware-2-after';
            return $result;
        });

        $this->server->onMessage(static function ($conn, $msg) use (&$executionOrder): void {
            $executionOrder[] = 'handler';
        });

        $connection = new Connection('middleware-test');
        $message = new Message(['type' => 'test']);

        $this->server->processMessage($connection, $message);

        $this->assertEquals([
            'middleware-1-before',
            'middleware-2-before',
            'handler',
            'middleware-2-after',
            'middleware-1-after',
        ], $executionOrder);
    }

    #[Test]
    #[TestDox('Broadcasting performance is acceptable for high subscriber count')]
    public function testBroadcastingPerformanceIsAcceptableForHighSubscriberCount(): void
    {
        $subscriberCount = 1000;
        $messageCount = 0;

        // Add many subscribers
        for ($i = 0; $i < $subscriberCount; $i++) {
            $this->broadcaster->subscribe('performance-test', static function () use (&$messageCount): void {
                $messageCount++;
            });
        }

        $startTime = microtime(true);

        // Broadcast message
        $this->broadcaster->broadcast('performance-test', 'performance test message');

        $duration = microtime(true) - $startTime;

        $this->assertEquals($subscriberCount, $messageCount);
        $this->assertLessThan(0.1, $duration); // Should complete in under 100ms
    }

    #[Test]
    #[TestDox('Connection attributes persist correctly across operations')]
    public function testConnectionAttributesPersistCorrectlyAcrossOperations(): void
    {
        $connection = new Connection('attr-test');

        // Set attributes
        $connection->setAttribute('user_id', 123);
        $connection->setAttribute('role', 'admin');
        $connection->setAttribute('session', ['id' => 'sess_123', 'created' => time()]);

        $this->server->addConnection($connection);

        // Retrieve connection and verify attributes
        $retrievedConnection = $this->server->getConnection('attr-test');

        $this->assertNotNull($retrievedConnection);
        $this->assertEquals(123, $retrievedConnection->getAttribute('user_id'));
        $this->assertEquals('admin', $retrievedConnection->getAttribute('role'));
        $this->assertIsArray($retrievedConnection->getAttribute('session'));
        $this->assertEquals('sess_123', $retrievedConnection->getAttribute('session')['id']);

        // Test attribute modification
        $retrievedConnection->setAttribute('last_activity', time());
        $this->assertIsInt($retrievedConnection->getAttribute('last_activity'));

        // Test attribute removal
        $retrievedConnection->removeAttribute('role');
        $this->assertNull($retrievedConnection->getAttribute('role'));
        $this->assertFalse($retrievedConnection->hasAttribute('role'));
    }

    #[Test]
    #[TestDox('Message validation works correctly with custom validators')]
    public function testMessageValidationWorksCorrectlyWithCustomValidators(): void
    {
        $validMessages = [];
        $invalidMessages = [];

        // Set up message validator
        $this->server->setMessageValidator(static function (Message $message) {
            $data = $message->getData();
            return isset($data['type']) && in_array($data['type'], ['chat', 'system', 'broadcast'], true);
        });

        // Set up message handler
        $this->server->onMessage(static function (Connection $conn, Message $msg) use (&$validMessages): void {
            $validMessages[] = $msg->getData();
        });

        $connection = new Connection('validation-test');

        // Test valid messages
        $validMsg1 = new Message(['type' => 'chat', 'text' => 'Hello']);
        $validMsg2 = new Message(['type' => 'system', 'text' => 'System message']);

        $this->assertTrue($this->server->validateMessage($validMsg1));
        $this->assertTrue($this->server->validateMessage($validMsg2));

        // Test invalid messages
        $invalidMsg1 = new Message(['text' => 'No type']);
        $invalidMsg2 = new Message(['type' => 'invalid', 'text' => 'Invalid type']);

        $this->assertFalse($this->server->validateMessage($invalidMsg1));
        $this->assertFalse($this->server->validateMessage($invalidMsg2));
    }

    #[Test]
    #[TestDox('Server statistics are accurately maintained across operations')]
    public function testServerStatisticsAreAccuratelyMaintainedAcrossOperations(): void
    {
        $initialStats = $this->server->getStats();

        // Add connections
        for ($i = 0; $i < 3; $i++) {
            $connection = new Connection("stats-test-{$i}");
            $this->server->addConnection($connection);
            $this->server->handleConnect($connection);
        }

        // Send messages
        $connections = [
            $this->server->getConnection('stats-test-0'),
            $this->server->getConnection('stats-test-1'),
            $this->server->getConnection('stats-test-2'),
        ];

        foreach ($connections as $connection) {
            $message = new Message(['type' => 'test', 'from' => $connection->getId()]);
            $this->server->handleMessage($connection, $message);
        }

        $finalStats = $this->server->getStats();

        // Verify connection stats
        $this->assertEquals(3, $finalStats['connections']['active']);
        $this->assertEquals(3, $finalStats['connections']['total']);

        // Verify message stats
        $this->assertEquals(3, $finalStats['messages']['received']);

        // Verify uptime is reasonable
        $this->assertGreaterThan(0, $finalStats['uptime']);
        $this->assertLessThan(1, $finalStats['uptime']); // Should be under 1 second for test

        // Verify memory usage is tracked
        $this->assertGreaterThan(0, $finalStats['memory_usage']);
    }
}
