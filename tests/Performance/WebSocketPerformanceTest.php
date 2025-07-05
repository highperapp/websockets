<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\WebSockets\Tests\Performance;

use HighPerApp\HighPer\WebSockets\Connection;
use HighPerApp\HighPer\WebSockets\Frame;
use HighPerApp\HighPer\WebSockets\IndexedBroadcaster;
use HighPerApp\HighPer\WebSockets\Message;
use HighPerApp\HighPer\WebSockets\WebSocketServer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[Group('performance')]
#[Group('websockets')]
class WebSocketPerformanceTest extends TestCase
{
    private WebSocketServer $server;

    private IndexedBroadcaster $broadcaster;

    protected function setUp(): void
    {
        $this->server = new WebSocketServer([
            'max_connections' => 10000,
            'compression' => true,
        ], new NullLogger());

        $this->broadcaster = new IndexedBroadcaster();
    }

    #[Test]
    #[TestDox('Server handles 1000 connections efficiently')]
    public function testServerHandles1000ConnectionsEfficiently(): void
    {
        $connectionCount = 1000;
        $startTime = microtime(true);
        $initialMemory = memory_get_usage(true);

        // Add connections
        for ($i = 0; $i < $connectionCount; $i++) {
            $connection = new Connection("perf_conn_{$i}");
            $this->server->addConnection($connection);
        }

        $endTime = microtime(true);
        $finalMemory = memory_get_usage(true);

        $duration = $endTime - $startTime;
        $memoryIncrease = $finalMemory - $initialMemory;

        $this->assertEquals($connectionCount, $this->server->getConnectionCount());
        $this->assertLessThan(1.0, $duration); // Under 1 second
        $this->assertLessThan(50 * 1024 * 1024, $memoryIncrease); // Under 50MB

        echo "\nConnection Performance:\n";
        echo "- {$connectionCount} connections added in " . number_format($duration * 1000, 2) . "ms\n";
        echo '- Memory increase: ' . number_format($memoryIncrease / 1024 / 1024, 2) . "MB\n";
        echo '- Avg time per connection: ' . number_format(($duration / $connectionCount) * 1000000, 2) . "μs\n";
    }

    #[Test]
    #[TestDox('Broadcasting to 5000 subscribers completes within acceptable time')]
    public function testBroadcastingTo5000SubscribersCompletesWithinAcceptableTime(): void
    {
        $subscriberCount = 5000;
        $messageCount = 0;
        $channel = 'performance-broadcast';

        $startSetup = microtime(true);

        // Add subscribers
        for ($i = 0; $i < $subscriberCount; $i++) {
            $this->broadcaster->subscribe($channel, static function ($message) use (&$messageCount): void {
                $messageCount++;
            });
        }

        $setupTime = microtime(true) - $startSetup;

        // Broadcast message
        $startBroadcast = microtime(true);
        $this->broadcaster->broadcast($channel, 'Performance test message');
        $broadcastTime = microtime(true) - $startBroadcast;

        $this->assertEquals($subscriberCount, $messageCount);
        $this->assertLessThan(0.5, $setupTime); // Setup under 500ms
        $this->assertLessThan(0.1, $broadcastTime); // Broadcast under 100ms

        echo "\nBroadcast Performance:\n";
        echo "- {$subscriberCount} subscribers setup in " . number_format($setupTime * 1000, 2) . "ms\n";
        echo "- Broadcast to {$subscriberCount} subscribers in " . number_format($broadcastTime * 1000, 2) . "ms\n";
        echo '- Throughput: ' . number_format($subscriberCount / $broadcastTime) . " messages/second\n";
    }

    #[Test]
    #[TestDox('Message processing throughput is acceptable for high volume')]
    public function testMessageProcessingThroughputIsAcceptableForHighVolume(): void
    {
        $messageCount = 10000;
        $processedCount = 0;

        $this->server->onMessage(static function (Connection $conn, Message $msg) use (&$processedCount): void {
            $processedCount++;
        });

        $connection = new Connection('throughput-test');
        $this->server->addConnection($connection);

        $startTime = microtime(true);

        // Process many messages
        for ($i = 0; $i < $messageCount; $i++) {
            $message = new Message(['id' => $i, 'type' => 'test', 'data' => "Message {$i}"]);
            $this->server->handleMessage($connection, $message);
        }

        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        $messagesPerSecond = $messageCount / $duration;

        $this->assertEquals($messageCount, $processedCount);
        $this->assertGreaterThan(10000, $messagesPerSecond); // At least 10k messages/sec

        echo "\nMessage Processing Performance:\n";
        echo "- {$messageCount} messages processed in " . number_format($duration * 1000, 2) . "ms\n";
        echo '- Throughput: ' . number_format($messagesPerSecond) . " messages/second\n";
        echo '- Avg processing time: ' . number_format(($duration / $messageCount) * 1000000, 2) . "μs per message\n";
    }

    #[Test]
    #[TestDox('Frame compression provides significant size reduction for repetitive data')]
    public function testFrameCompressionProvidesSignificantSizeReductionForRepetitiveData(): void
    {
        $repetitiveData = str_repeat('Hello World! This is a test message. ', 1000);
        $randomData = random_bytes(strlen($repetitiveData));

        $startTime = microtime(true);
        $compressedRepetitive = $this->server->compressFrame($repetitiveData);
        $compressionTime = microtime(true) - $startTime;

        $startTime = microtime(true);
        $compressedRandom = $this->server->compressFrame($randomData);
        $randomCompressionTime = microtime(true) - $startTime;

        // Test decompression
        $startTime = microtime(true);
        $decompressedRepetitive = $this->server->decompressFrame($compressedRepetitive);
        $decompressionTime = microtime(true) - $startTime;

        $repetitiveRatio = strlen($compressedRepetitive) / strlen($repetitiveData);
        $randomRatio = strlen($compressedRandom) / strlen($randomData);

        $this->assertEquals($repetitiveData, $decompressedRepetitive);
        $this->assertLessThan(0.1, $repetitiveRatio); // Should compress to under 10%
        $this->assertGreaterThan(0.9, $randomRatio); // Random data won't compress much

        echo "\nCompression Performance:\n";
        echo '- Repetitive data: ' . number_format($repetitiveRatio * 100, 1) . "% of original size\n";
        echo '- Random data: ' . number_format($randomRatio * 100, 1) . "% of original size\n";
        echo '- Compression time: ' . number_format($compressionTime * 1000, 2) . "ms\n";
        echo '- Decompression time: ' . number_format($decompressionTime * 1000, 2) . "ms\n";
    }

    #[Test]
    #[TestDox('Multiple channels broadcasting simultaneously maintains performance')]
    public function testMultipleChannelsBroadcastingSimultaneouslyMaintainsPerformance(): void
    {
        $channelCount = 100;
        $subscribersPerChannel = 50;
        $totalMessages = 0;

        // Setup channels with subscribers
        for ($c = 0; $c < $channelCount; $c++) {
            $channel = "channel_{$c}";

            for ($s = 0; $s < $subscribersPerChannel; $s++) {
                $this->broadcaster->subscribe($channel, static function ($message) use (&$totalMessages): void {
                    $totalMessages++;
                });
            }
        }

        $startTime = microtime(true);

        // Broadcast to all channels simultaneously
        for ($c = 0; $c < $channelCount; $c++) {
            $channel = "channel_{$c}";
            $this->broadcaster->broadcast($channel, "Message for {$channel}");
        }

        $duration = microtime(true) - $startTime;
        $expectedMessages = $channelCount * $subscribersPerChannel;

        $this->assertEquals($expectedMessages, $totalMessages);
        $this->assertLessThan(1.0, $duration); // Should complete in under 1 second

        echo "\nMulti-channel Performance:\n";
        echo "- {$channelCount} channels with {$subscribersPerChannel} subscribers each\n";
        echo "- {$expectedMessages} total messages delivered in " . number_format($duration * 1000, 2) . "ms\n";
        echo '- Throughput: ' . number_format($expectedMessages / $duration) . " messages/second\n";
    }

    #[Test]
    #[TestDox('Frame parsing performance is acceptable for high-frequency operations')]
    public function testFrameParsingPerformanceIsAcceptableForHighFrequencyOperations(): void
    {
        $frameCount = 1000;
        $frames = [];

        // Generate frames
        for ($i = 0; $i < $frameCount; $i++) {
            $frame = new Frame(Frame::OPCODE_TEXT, "Test message {$i}");
            $frames[] = $frame->toBinary();
        }

        // Test frame parsing performance
        $startTime = microtime(true);
        $parsedFrames = [];

        foreach ($frames as $binaryFrame) {
            $parsedFrames[] = Frame::fromBinary($binaryFrame);
        }

        $parseTime = microtime(true) - $startTime;

        // Test frame generation performance
        $startTime = microtime(true);
        $generatedFrames = [];

        for ($i = 0; $i < $frameCount; $i++) {
            $frame = new Frame(Frame::OPCODE_TEXT, "Generated message {$i}");
            $generatedFrames[] = $frame->toBinary();
        }

        $generateTime = microtime(true) - $startTime;

        $this->assertCount($frameCount, $parsedFrames);
        $this->assertCount($frameCount, $generatedFrames);
        $this->assertLessThan(0.1, $parseTime); // Under 100ms for 1000 frames
        $this->assertLessThan(0.1, $generateTime); // Under 100ms for 1000 frames

        echo "\nFrame Processing Performance:\n";
        echo "- {$frameCount} frames parsed in " . number_format($parseTime * 1000, 2) . "ms\n";
        echo "- {$frameCount} frames generated in " . number_format($generateTime * 1000, 2) . "ms\n";
        echo '- Parse rate: ' . number_format($frameCount / $parseTime) . " frames/second\n";
        echo '- Generate rate: ' . number_format($frameCount / $generateTime) . " frames/second\n";
    }

    #[Test]
    #[TestDox('Memory usage remains stable under sustained load')]
    public function testMemoryUsageRemainsStableUnderSustainedLoad(): void
    {
        $iterations = 100;
        $connectionsPerIteration = 10;
        $messagesPerConnection = 5;

        $memoryReadings = [];

        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $connections = [];

            // Add connections
            for ($i = 0; $i < $connectionsPerIteration; $i++) {
                $connection = new Connection("load_test_{$iteration}_{$i}");
                $this->server->addConnection($connection);
                $connections[] = $connection;
            }

            // Send messages
            foreach ($connections as $connection) {
                for ($m = 0; $m < $messagesPerConnection; $m++) {
                    $message = new Message(['iter' => $iteration, 'msg' => $m]);
                    $this->server->handleMessage($connection, $message);
                }
            }

            // Remove connections
            foreach ($connections as $connection) {
                $this->server->removeConnection($connection);
            }

            // Record memory usage every 10 iterations
            if ($iteration % 10 === 0) {
                $memoryReadings[] = memory_get_usage(true);
            }
        }

        // Check memory stability
        $minMemory = min($memoryReadings);
        $maxMemory = max($memoryReadings);
        $memoryIncrease = $maxMemory - $minMemory;
        $memoryIncreasePercent = ($memoryIncrease / $minMemory) * 100;

        $this->assertLessThan(20, $memoryIncreasePercent); // Less than 20% increase

        echo "\nMemory Stability:\n";
        echo '- Min memory: ' . number_format($minMemory / 1024 / 1024, 2) . "MB\n";
        echo '- Max memory: ' . number_format($maxMemory / 1024 / 1024, 2) . "MB\n";
        echo '- Memory increase: ' . number_format($memoryIncreasePercent, 2) . "%\n";
        echo "- {$iterations} iterations with {$connectionsPerIteration} connections each\n";
    }

    #[Test]
    #[TestDox('Broadcaster statistics calculation is performant')]
    public function testBroadcasterStatisticsCalculationIsPerformant(): void
    {
        $channelCount = 1000;
        $subscribersPerChannel = 10;

        // Setup many channels and subscribers
        for ($c = 0; $c < $channelCount; $c++) {
            $channel = "stats_channel_{$c}";

            for ($s = 0; $s < $subscribersPerChannel; $s++) {
                $this->broadcaster->subscribe($channel, static function (): void {
                });
            }
        }

        // Perform some broadcasts
        for ($c = 0; $c < 100; $c++) {
            $this->broadcaster->broadcast("stats_channel_{$c}", 'test message');
        }

        // Measure stats calculation time
        $startTime = microtime(true);
        $stats = $this->broadcaster->getStats();
        $statsTime = microtime(true) - $startTime;

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('broadcasts', $stats);
        $this->assertArrayHasKey('subscribers', $stats);
        $this->assertArrayHasKey('channels', $stats);
        $this->assertEquals($channelCount, $stats['channels']);
        $this->assertEquals(100, $stats['broadcasts']);
        $this->assertLessThan(0.01, $statsTime); // Under 10ms

        echo "\nStatistics Performance:\n";
        echo "- {$channelCount} channels with {$subscribersPerChannel} subscribers each\n";
        echo '- Statistics calculated in ' . number_format($statsTime * 1000, 2) . "ms\n";
        echo "- Total channels: {$stats['channels']}\n";
        echo "- Total subscribers: {$stats['total_subscribers']}\n";
        echo "- Total broadcasts: {$stats['broadcasts']}\n";
    }
}
