<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\WebSockets;

use Amp\Future;
use Amp\Pipeline\Pipeline;
use Amp\Pipeline\Queue;
use Amp\Websocket\WebsocketMessage;
use Psr\Log\LoggerInterface;

use function Amp\async;
use function Amp\Future\awaitAll;

/**
 * Streaming WebSocket Handler.
 *
 * High-performance WebSocket streaming with real-time data processing,
 * backpressure handling, and concurrent connection management.
 */
class StreamingWebSocketHandler
{
    protected LoggerInterface $logger;

    protected array $config;

    protected array $connections = [];

    protected array $streams = [];

    protected array $stats = [];

    protected Queue $messageQueue;

    public function __construct(LoggerInterface $logger, array $config = [])
    {
        $this->logger = $logger;
        $this->config = array_merge([
            'max_connections' => 10000,
            'max_message_size' => 1024 * 1024, // 1MB
            'heartbeat_interval' => 30,
            'buffer_size' => 8192,
            'enable_compression' => true,
            'enable_backpressure' => true,
            'backpressure_threshold' => 1000,
            'stream_chunk_size' => 4096,
            'concurrent_streams' => 100,
        ], $config);

        $this->messageQueue = new Queue();
        $this->initializeStats();
    }

    /**
     * Handle new WebSocket connection.
     */
    public function handleConnection(WebSocketConnection $connection): Future
    {
        return async(function () use ($connection): void {
            $connectionId = $this->generateConnectionId();

            $this->connections[$connectionId] = [
                'connection' => $connection,
                'created_at' => time(),
                'last_activity' => time(),
                'message_count' => 0,
                'bytes_sent' => 0,
                'bytes_received' => 0,
                'streams' => [],
                'user_data' => [],
            ];

            $this->updateStats('connections_established');

            $this->logger->info('WebSocket connection established', [
                'connection_id' => $connectionId,
                'total_connections' => count($this->connections),
            ]);

            try {
                $this->startHeartbeat($connectionId);
                $this->processMessages($connectionId);
            } catch (\Throwable $e) {
                $this->logger->error('WebSocket connection error', [
                    'connection_id' => $connectionId,
                    'error' => $e->getMessage(),
                ]);
            } finally {
                $this->closeConnection($connectionId);
            }
        });
    }

    /**
     * Create real-time data stream.
     */
    public function createStream(string $connectionId, string $streamId, callable $dataSource, array $options = []): Future
    {
        return async(function () use ($connectionId, $streamId, $dataSource, $options): void {
            if (!isset($this->connections[$connectionId])) {
                throw new \RuntimeException("Connection not found: {$connectionId}");
            }

            $chunkSize = $options['chunk_size'] ?? $this->config['stream_chunk_size'];
            $interval = $options['interval'] ?? 0.1; // 100ms default
            $enableCompression = $options['compression'] ?? $this->config['enable_compression'];

            $stream = [
                'id' => $streamId,
                'connection_id' => $connectionId,
                'data_source' => $dataSource,
                'options' => $options,
                'created_at' => time(),
                'last_sent' => 0,
                'bytes_sent' => 0,
                'chunk_count' => 0,
                'active' => true,
            ];

            $this->streams[$streamId] = $stream;
            $this->connections[$connectionId]['streams'][] = $streamId;

            $this->updateStats('streams_created');

            try {
                while ($stream['active'] && isset($this->connections[$connectionId])) {
                    $data = $dataSource();

                    if ($data !== null) {
                        $this->sendStreamData($connectionId, $streamId, $data, $enableCompression);
                        $stream['last_sent'] = time();
                        $stream['chunk_count']++;
                    }

                    if ($interval > 0) {
                        usleep((int) ($interval * 1000000));
                    }

                    // Check backpressure
                    if ($this->shouldApplyBackpressure($connectionId)) {
                        usleep(10000); // 10ms pause
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error('Stream processing error', [
                    'stream_id' => $streamId,
                    'connection_id' => $connectionId,
                    'error' => $e->getMessage(),
                ]);
            } finally {
                $this->closeStream($streamId);
            }
        });
    }

    /**
     * Broadcast data to multiple connections.
     */
    public function broadcast(array $connectionIds, $data, array $options = []): Future
    {
        return async(function () use ($connectionIds, $data, $options) {
            $enableCompression = $options['compression'] ?? $this->config['enable_compression'];
            $futures = [];

            foreach ($connectionIds as $connectionId) {
                if (isset($this->connections[$connectionId])) {
                    $futures[] = $this->sendData($connectionId, $data, $enableCompression);
                }
            }

            [$errors, $results] = awaitAll($futures);

            $successCount = 0;
            $errorCount = 0;

            foreach ($results as $result) {
                if ($result->isComplete()) {
                    $successCount++;
                } else {
                    $errorCount++;
                }
            }

            $this->updateStats('broadcast_operations');
            $this->updateStats('broadcast_successes', $successCount);
            $this->updateStats('broadcast_errors', $errorCount);

            return [
                'total_sent' => $successCount,
                'errors' => $errorCount,
                'data_size' => is_string($data) ? strlen($data) : strlen(json_encode($data)),
            ];
        });
    }

    /**
     * Create data pipeline for stream processing.
     */
    public function createPipeline(string $connectionId): WebSocketPipeline
    {
        if (!isset($this->connections[$connectionId])) {
            throw new \RuntimeException("Connection not found: {$connectionId}");
        }

        return new WebSocketPipeline($connectionId, $this, $this->logger);
    }

    /**
     * Send data to specific connection.
     */
    public function sendData(string $connectionId, $data, bool $compress = false): Future
    {
        return async(function () use ($connectionId, $data, $compress) {
            if (!isset($this->connections[$connectionId])) {
                throw new \RuntimeException("Connection not found: {$connectionId}");
            }

            $connection = $this->connections[$connectionId]['connection'];
            $payload = is_string($data) ? $data : json_encode($data);

            if (strlen($payload) > $this->config['max_message_size']) {
                throw new \RuntimeException('Message exceeds maximum size');
            }

            if ($compress && $this->config['enable_compression']) {
                $payload = gzcompress($payload);
            }

            $connection->send($payload);

            $this->connections[$connectionId]['bytes_sent'] += strlen($payload);
            $this->connections[$connectionId]['message_count']++;
            $this->connections[$connectionId]['last_activity'] = time();

            $this->updateStats('messages_sent');
            $this->updateStats('bytes_sent', strlen($payload));

            return [
                'sent' => true,
                'bytes' => strlen($payload),
                'compressed' => $compress,
            ];
        });
    }

    /**
     * Get connection statistics.
     */
    public function getConnectionStats(string $connectionId): ?array
    {
        if (!isset($this->connections[$connectionId])) {
            return null;
        }

        $conn = $this->connections[$connectionId];

        return [
            'connection_id' => $connectionId,
            'created_at' => $conn['created_at'],
            'last_activity' => $conn['last_activity'],
            'uptime' => time() - $conn['created_at'],
            'message_count' => $conn['message_count'],
            'bytes_sent' => $conn['bytes_sent'],
            'bytes_received' => $conn['bytes_received'],
            'active_streams' => count($conn['streams']),
            'user_data' => $conn['user_data'],
        ];
    }

    /**
     * Get global statistics.
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'active_connections' => count($this->connections),
            'active_streams' => count($this->streams),
            'memory_usage' => memory_get_usage(true),
            'queue_size' => $this->messageQueue->count(),
        ]);
    }

    /**
     * Close specific connection.
     */
    public function closeConnection(string $connectionId): void
    {
        if (!isset($this->connections[$connectionId])) {
            return;
        }

        $connection = $this->connections[$connectionId];

        // Close all streams for this connection
        foreach ($connection['streams'] as $streamId) {
            $this->closeStream($streamId);
        }

        // Close WebSocket connection
        $connection['connection']->close();

        unset($this->connections[$connectionId]);

        $this->updateStats('connections_closed');

        $this->logger->info('WebSocket connection closed', [
            'connection_id' => $connectionId,
            'uptime' => time() - $connection['created_at'],
            'messages_sent' => $connection['message_count'],
        ]);
    }

    /**
     * Close specific stream.
     */
    public function closeStream(string $streamId): void
    {
        if (!isset($this->streams[$streamId])) {
            return;
        }

        $stream = $this->streams[$streamId];
        $stream['active'] = false;

        // Remove from connection's stream list
        $connectionId = $stream['connection_id'];
        if (isset($this->connections[$connectionId])) {
            $this->connections[$connectionId]['streams'] = array_filter(
                $this->connections[$connectionId]['streams'],
                static fn ($id) => $id !== $streamId,
            );
        }

        unset($this->streams[$streamId]);

        $this->updateStats('streams_closed');

        $this->logger->debug('Stream closed', [
            'stream_id' => $streamId,
            'connection_id' => $connectionId,
            'chunks_sent' => $stream['chunk_count'],
        ]);
    }

    /**
     * Process incoming messages.
     */
    protected function processMessages(string $connectionId): void
    {
        $connection = $this->connections[$connectionId]['connection'];

        while ($message = $connection->receive()) {
            $this->handleMessage($connectionId, $message);
            $this->connections[$connectionId]['last_activity'] = time();
        }
    }

    /**
     * Handle incoming message.
     */
    protected function handleMessage(string $connectionId, WebsocketMessage $message): void
    {
        $payload = $message->buffer();

        $this->connections[$connectionId]['bytes_received'] += strlen($payload);
        $this->updateStats('messages_received');
        $this->updateStats('bytes_received', strlen($payload));

        // Add to message queue for processing
        $this->messageQueue->push([
            'connection_id' => $connectionId,
            'payload' => $payload,
            'received_at' => microtime(true),
        ]);
    }

    /**
     * Send stream data to connection.
     */
    protected function sendStreamData(string $connectionId, string $streamId, $data, bool $compress): void
    {
        $payload = [
            'type' => 'stream_data',
            'stream_id' => $streamId,
            'data' => $data,
            'timestamp' => microtime(true),
        ];

        $this->sendData($connectionId, $payload, $compress);

        $this->streams[$streamId]['bytes_sent'] += is_string($data) ? strlen($data) : strlen(json_encode($data));
    }

    /**
     * Start heartbeat for connection.
     */
    protected function startHeartbeat(string $connectionId): Future
    {
        return async(function () use ($connectionId): void {
            $interval = $this->config['heartbeat_interval'];

            while (isset($this->connections[$connectionId])) {
                sleep($interval);

                if (isset($this->connections[$connectionId])) {
                    try {
                        $this->sendData($connectionId, ['type' => 'heartbeat', 'timestamp' => time()]);
                    } catch (\Throwable $e) {
                        $this->logger->debug('Heartbeat failed, connection may be closed', [
                            'connection_id' => $connectionId,
                        ]);
                        break;
                    }
                }
            }
        });
    }

    /**
     * Check if backpressure should be applied.
     */
    protected function shouldApplyBackpressure(string $connectionId): bool
    {
        if (!$this->config['enable_backpressure']) {
            return false;
        }

        return $this->messageQueue->count() > $this->config['backpressure_threshold'];
    }

    /**
     * Generate unique connection ID.
     */
    protected function generateConnectionId(): string
    {
        return uniqid('ws_', true);
    }

    /**
     * Update statistics.
     */
    protected function updateStats(string $key, int $value = 1): void
    {
        if (!isset($this->stats[$key])) {
            $this->stats[$key] = 0;
        }

        $this->stats[$key] += $value;
    }

    /**
     * Initialize statistics.
     */
    protected function initializeStats(): void
    {
        $this->stats = [
            'connections_established' => 0,
            'connections_closed' => 0,
            'streams_created' => 0,
            'streams_closed' => 0,
            'messages_sent' => 0,
            'messages_received' => 0,
            'bytes_sent' => 0,
            'bytes_received' => 0,
            'broadcast_operations' => 0,
            'broadcast_successes' => 0,
            'broadcast_errors' => 0,
        ];
    }
}

/**
 * WebSocket Processing Pipeline.
 */
class WebSocketPipeline
{
    protected string $connectionId;

    protected StreamingWebSocketHandler $handler;

    protected LoggerInterface $logger;

    protected array $processors = [];

    public function __construct(string $connectionId, StreamingWebSocketHandler $handler, LoggerInterface $logger)
    {
        $this->connectionId = $connectionId;
        $this->handler = $handler;
        $this->logger = $logger;
    }

    /**
     * Add processor to pipeline.
     */
    public function addProcessor(callable $processor): self
    {
        $this->processors[] = $processor;
        return $this;
    }

    /**
     * Process and send data through pipeline.
     */
    public function process($data): Future
    {
        return async(function () use ($data) {
            $processedData = $data;

            foreach ($this->processors as $processor) {
                $processedData = $processor($processedData);
            }

            return $this->handler->sendData($this->connectionId, $processedData);
        });
    }
}
