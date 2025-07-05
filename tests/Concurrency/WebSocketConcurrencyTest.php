<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\WebSockets\Tests\Concurrency;

use HighPerApp\HighPer\WebSockets\Connection;
use HighPerApp\HighPer\WebSockets\IndexedBroadcaster;
use HighPerApp\HighPer\WebSockets\Message;
use HighPerApp\HighPer\WebSockets\WebSocketServer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[Group('concurrency')]
#[Group('websockets')]
class WebSocketConcurrencyTest extends TestCase
{
    private WebSocketServer $server;

    private IndexedBroadcaster $broadcaster;

    protected function setUp(): void
    {
        $this->server = new WebSocketServer([
            'max_connections' => 1000,
        ], new NullLogger());

        $this->broadcaster = new IndexedBroadcaster();
    }

    #[Test]
    #[TestDox('Concurrent connection additions are handled safely')]
    public function testConcurrentConnectionAdditionsAreHandledSafely(): void
    {
        $connectionCount = 100;
        $connections = [];
        $addedConnections = [];

        // Simulate concurrent connection additions
        $operations = [];
        for ($i = 0; $i < $connectionCount; $i++) {
            $operations[] = function () use ($i, &$addedConnections) {
                $connection = new Connection("concurrent_add_{$i}");
                $this->server->addConnection($connection);
                $addedConnections[] = $connection->getId();
                return $connection;
            };
        }

        // Execute operations "concurrently" (simulated)
        foreach ($operations as $operation) {
            $connections[] = $operation();
        }

        $this->assertCount($connectionCount, $connections);
        $this->assertCount($connectionCount, $addedConnections);
        $this->assertEquals($connectionCount, $this->server->getConnectionCount());

        // Verify all connections are unique
        $uniqueConnections = array_unique($addedConnections);
        $this->assertCount($connectionCount, $uniqueConnections);
    }

    #[Test]
    #[TestDox('Concurrent message processing maintains data integrity')]
    public function testConcurrentMessageProcessingMaintainsDataIntegrity(): void
    {
        $messageCount = 200;
        $processedMessages = [];
        $messageCounters = [];

        $this->server->onMessage(static function (Connection $conn, Message $msg) use (&$processedMessages, &$messageCounters): void {
            $data = $msg->getData();
            $processedMessages[] = $data;

            $senderId = $data['sender_id'];
            if (!isset($messageCounters[$senderId])) {
                $messageCounters[$senderId] = 0;
            }
            $messageCounters[$senderId]++;
        });

        // Create connections
        $connections = [];
        for ($i = 0; $i < 10; $i++) {
            $connection = new Connection("msg_sender_{$i}");
            $this->server->addConnection($connection);
            $connections[] = $connection;
        }

        // Simulate concurrent message sending
        $messageOperations = [];
        for ($i = 0; $i < $messageCount; $i++) {
            $connectionIndex = $i % count($connections);
            $connection = $connections[$connectionIndex];

            $messageOperations[] = function () use ($connection, $i): void {
                $message = new Message([
                    'id' => $i,
                    'sender_id' => $connection->getId(),
                    'content' => "Message {$i}",
                    'timestamp' => microtime(true),
                ]);

                $this->server->handleMessage($connection, $message);
            };
        }

        // Execute message operations
        foreach ($messageOperations as $operation) {
            $operation();
        }

        $this->assertCount($messageCount, $processedMessages);
        $this->assertCount(10, $messageCounters); // 10 different senders

        // Verify message distribution
        foreach ($messageCounters as $senderId => $count) {
            $this->assertEquals($messageCount / 10, $count);
        }
    }

    #[Test]
    #[TestDox('Concurrent broadcasting to multiple channels works correctly')]
    public function testConcurrentBroadcastingToMultipleChannelsWorksCorrectly(): void
    {
        $channelCount = 50;
        $subscribersPerChannel = 5;
        $messagesPerChannel = 4;
        $receivedMessages = [];

        // Setup channels with subscribers
        for ($c = 0; $c < $channelCount; $c++) {
            $channel = "concurrent_channel_{$c}";

            for ($s = 0; $s < $subscribersPerChannel; $s++) {
                $subscriberId = "subscriber_{$c}_{$s}";
                $this->broadcaster->subscribe($channel, static function ($message) use (&$receivedMessages, $subscriberId, $channel): void {
                    if (!isset($receivedMessages[$channel])) {
                        $receivedMessages[$channel] = [];
                    }
                    if (!isset($receivedMessages[$channel][$subscriberId])) {
                        $receivedMessages[$channel][$subscriberId] = [];
                    }
                    $receivedMessages[$channel][$subscriberId][] = $message;
                });
            }
        }

        // Simulate concurrent broadcasting
        $broadcastOperations = [];
        for ($c = 0; $c < $channelCount; $c++) {
            $channel = "concurrent_channel_{$c}";

            for ($m = 0; $m < $messagesPerChannel; $m++) {
                $broadcastOperations[] = function () use ($channel, $m): void {
                    $this->broadcaster->broadcast($channel, [
                        'channel' => $channel,
                        'message_id' => $m,
                        'content' => "Message {$m} for {$channel}",
                        'timestamp' => microtime(true),
                    ]);
                };
            }
        }

        // Execute broadcast operations
        foreach ($broadcastOperations as $operation) {
            $operation();
        }

        // Verify results
        $this->assertCount($channelCount, $receivedMessages);

        foreach ($receivedMessages as $channel => $subscribers) {
            $this->assertCount($subscribersPerChannel, $subscribers);

            foreach ($subscribers as $subscriberId => $messages) {
                $this->assertCount($messagesPerChannel, $messages);

                // Verify message integrity
                for ($i = 0; $i < $messagesPerChannel; $i++) {
                    $this->assertEquals($channel, $messages[$i]['channel']);
                    $this->assertEquals($i, $messages[$i]['message_id']);
                }
            }
        }
    }

    #[Test]
    #[TestDox('Concurrent subscription and unsubscription operations are thread-safe')]
    public function testConcurrentSubscriptionAndUnsubscriptionOperationsAreThreadSafe(): void
    {
        $channel = 'thread_safety_test';
        $operationCount = 200;
        $subscriptionIds = [];
        $finalSubscribers = 0;

        // Mix of subscribe and unsubscribe operations
        $operations = [];

        // First half: subscribe operations
        for ($i = 0; $i < $operationCount / 2; $i++) {
            $operations[] = function () use ($channel, $i, &$subscriptionIds) {
                $subscriptionId = $this->broadcaster->subscribe($channel, static function ($message) use ($i): void {
                    // Subscriber callback
                });
                $subscriptionIds[] = $subscriptionId;
                return $subscriptionId;
            };
        }

        // Second half: mixed subscribe/unsubscribe operations
        for ($i = $operationCount / 2; $i < $operationCount; $i++) {
            if ($i % 3 === 0 && !empty($subscriptionIds)) {
                // Unsubscribe operation
                $operations[] = function () use ($channel, &$subscriptionIds) {
                    if (!empty($subscriptionIds)) {
                        $subscriptionId = array_pop($subscriptionIds);
                        return $this->broadcaster->unsubscribe($channel, $subscriptionId);
                    }
                    return false;
                };
            } else {
                // Subscribe operation
                $operations[] = function () use ($channel, $i, &$subscriptionIds) {
                    $subscriptionId = $this->broadcaster->subscribe($channel, static function ($message) use ($i): void {
                        // Subscriber callback
                    });
                    $subscriptionIds[] = $subscriptionId;
                    return $subscriptionId;
                };
            }
        }

        // Execute operations
        foreach ($operations as $operation) {
            $operation();
        }

        $finalSubscriberCount = $this->broadcaster->getSubscriberCount($channel);
        $stats = $this->broadcaster->getStats();

        $this->assertGreaterThan(0, $finalSubscriberCount);
        $this->assertLessThanOrEqual($operationCount, $finalSubscriberCount);
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('subscribers', $stats);
    }

    #[Test]
    #[TestDox('Race conditions in connection state management are handled properly')]
    public function testRaceConditionsInConnectionStateManagementAreHandledProperly(): void
    {
        $connectionCount = 50;
        $stateOperations = [];
        $connections = [];

        // Create connections
        for ($i = 0; $i < $connectionCount; $i++) {
            $connection = new Connection("race_test_{$i}");
            $connections[] = $connection;
            $this->server->addConnection($connection);
        }

        // Create concurrent state modification operations
        foreach ($connections as $index => $connection) {
            // Set attributes
            $stateOperations[] = static function () use ($connection, $index): void {
                $connection->setAttribute('user_id', $index * 100);
                $connection->setAttribute('session_id', "session_{$index}");
                $connection->setAttribute('last_activity', time());
            };

            // Modify attributes
            $stateOperations[] = static function () use ($connection, $index): void {
                $userId = $connection->getAttribute('user_id', 0);
                $connection->setAttribute('user_id', $userId + 1);
                $connection->setAttribute(
                    'modification_count',
                    $connection->getAttribute('modification_count', 0) + 1,
                );
            };

            // Read attributes
            $stateOperations[] = static function () use ($connection) {
                $userId = $connection->getAttribute('user_id');
                $sessionId = $connection->getAttribute('session_id');
                $lastActivity = $connection->getAttribute('last_activity');

                return [
                    'user_id' => $userId,
                    'session_id' => $sessionId,
                    'last_activity' => $lastActivity,
                ];
            };
        }

        // Shuffle operations to simulate random execution order
        shuffle($stateOperations);

        // Execute operations
        foreach ($stateOperations as $operation) {
            $operation();
        }

        // Verify final state consistency
        foreach ($connections as $index => $connection) {
            $userId = $connection->getAttribute('user_id');
            $sessionId = $connection->getAttribute('session_id');

            $this->assertIsInt($userId);
            $this->assertEquals("session_{$index}", $sessionId);
            $this->assertTrue($connection->hasAttribute('last_activity'));
        }
    }

    #[Test]
    #[TestDox('Concurrent access to broadcaster statistics is consistent')]
    public function testConcurrentAccessToBroadcasterStatisticsIsConsistent(): void
    {
        $readOperations = 100;
        $writeOperations = 50;
        $statsResults = [];

        // Setup some initial data
        for ($i = 0; $i < 10; $i++) {
            $this->broadcaster->subscribe("stats_channel_{$i}", static function (): void {
            });
        }

        // Create concurrent operations
        $operations = [];

        // Read operations
        for ($i = 0; $i < $readOperations; $i++) {
            $operations[] = function () use (&$statsResults, $i) {
                $stats = $this->broadcaster->getStats();
                $statsResults["read_{$i}"] = $stats;
                return $stats;
            };
        }

        // Write operations (broadcasts)
        for ($i = 0; $i < $writeOperations; $i++) {
            $operations[] = function () use ($i): void {
                $channel = 'stats_channel_' . ($i % 10);
                $this->broadcaster->broadcast($channel, "test message {$i}");
            };
        }

        // Shuffle to simulate concurrent execution
        shuffle($operations);

        // Execute operations
        foreach ($operations as $operation) {
            $operation();
        }

        // Verify statistics consistency
        $this->assertCount($readOperations, $statsResults);

        $finalStats = $this->broadcaster->getStats();
        $this->assertEquals($writeOperations, $finalStats['broadcasts']);
        $this->assertEquals(10, $finalStats['channels']);

        // All read operations should have valid statistics
        foreach ($statsResults as $readId => $stats) {
            $this->assertIsArray($stats);
            $this->assertArrayHasKey('broadcasts', $stats);
            $this->assertArrayHasKey('channels', $stats);
            $this->assertArrayHasKey('subscribers', $stats);
            $this->assertLessThanOrEqual($writeOperations, $stats['broadcasts']);
        }
    }

    #[Test]
    #[TestDox('Memory consistency is maintained under concurrent load')]
    public function testMemoryConsistencyIsMaintainedUnderConcurrentLoad(): void
    {
        $iterations = 50;
        $connectionsPerIteration = 10;
        $messagesPerConnection = 5;
        $memorySnapshots = [];

        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $iterationConnections = [];

            // Concurrent connection creation
            for ($i = 0; $i < $connectionsPerIteration; $i++) {
                $connection = new Connection("memory_test_{$iteration}_{$i}");
                $connection->setAttribute('iteration', $iteration);
                $connection->setAttribute('index', $i);
                $connection->setAttribute('data', str_repeat('x', 1000)); // 1KB per connection

                $this->server->addConnection($connection);
                $iterationConnections[] = $connection;
            }

            // Concurrent message processing
            foreach ($iterationConnections as $connection) {
                for ($m = 0; $m < $messagesPerConnection; $m++) {
                    $message = new Message([
                        'iteration' => $iteration,
                        'connection' => $connection->getId(),
                        'message_index' => $m,
                        'payload' => str_repeat('y', 500), // 500 bytes per message
                    ]);

                    $this->server->handleMessage($connection, $message);
                }
            }

            // Record memory usage
            if ($iteration % 10 === 0) {
                $memorySnapshots[] = [
                    'iteration' => $iteration,
                    'memory' => memory_get_usage(true),
                    'peak_memory' => memory_get_peak_usage(true),
                    'connections' => $this->server->getConnectionCount(),
                ];
            }

            // Concurrent connection cleanup
            foreach ($iterationConnections as $connection) {
                $this->server->removeConnection($connection);
            }
        }

        // Analyze memory consistency
        $memoryIncreases = [];
        for ($i = 1; $i < count($memorySnapshots); $i++) {
            $increase = $memorySnapshots[$i]['memory'] - $memorySnapshots[$i - 1]['memory'];
            $memoryIncreases[] = $increase;
        }

        // Memory should not increase dramatically over iterations
        $avgIncrease = array_sum($memoryIncreases) / count($memoryIncreases);
        $maxIncrease = max($memoryIncreases);

        $this->assertLessThan(5 * 1024 * 1024, $avgIncrease); // Less than 5MB average increase
        $this->assertLessThan(10 * 1024 * 1024, $maxIncrease); // Less than 10MB max increase

        echo "\nMemory Consistency:\n";
        echo "- Iterations: {$iterations}\n";
        echo '- Average memory increase: ' . number_format($avgIncrease / 1024 / 1024, 2) . "MB\n";
        echo '- Maximum memory increase: ' . number_format($maxIncrease / 1024 / 1024, 2) . "MB\n";
        echo '- Final memory: ' . number_format(end($memorySnapshots)['memory'] / 1024 / 1024, 2) . "MB\n";
    }
}
