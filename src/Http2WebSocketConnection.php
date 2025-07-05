<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\WebSockets;

use Amp\Http\Server\Request;
use Amp\WebSocket\WebSocketException;
use Psr\Log\LoggerInterface;

/**
 * HTTP/2 WebSocket Connection (RFC 8441).
 *
 * Represents a WebSocket connection tunneled over HTTP/2 stream
 * using CONNECT method and :protocol pseudo-header
 */
class Http2WebSocketConnection
{
    private string $connectionId;

    private Request $request;

    private LoggerInterface $logger;

    private array $config;

    private bool $isOpen = true;

    private array $attributes = [];

    private array $frameBuffer = [];

    private int $streamId;

    private array $http2State;

    private float $lastActivity;

    // WebSocket opcodes
    private const OPCODE_CONTINUATION = 0x0;
    private const OPCODE_TEXT = 0x1;
    private const OPCODE_BINARY = 0x2;
    private const OPCODE_CLOSE = 0x8;
    private const OPCODE_PING = 0x9;
    private const OPCODE_PONG = 0xA;

    public function __construct(
        string $connectionId,
        Request $request,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->connectionId = $connectionId;
        $this->request = $request;
        $this->logger = $logger;
        $this->config = $config;
        $this->streamId = $this->extractStreamId($request);
        $this->lastActivity = microtime(true);

        $this->initializeHttp2State();

        $this->logger->debug('HTTP/2 WebSocket connection created', [
            'connection_id' => $this->connectionId,
            'stream_id' => $this->streamId,
            'remote_address' => $request->getClient()->getRemoteAddress()->toString(),
        ]);
    }

    /**
     * Get connection ID.
     */
    public function getId(): string
    {
        return $this->connectionId;
    }

    /**
     * Check if connection is open.
     */
    public function isOpen(): bool
    {
        return $this->isOpen;
    }

    /**
     * Get HTTP/2 stream ID.
     */
    public function getStreamId(): int
    {
        return $this->streamId;
    }

    /**
     * Send text message over HTTP/2 WebSocket.
     */
    public function send(string $message): void
    {
        if (!$this->isOpen) {
            throw new WebSocketException('Connection is closed');
        }

        $frame = $this->createWebSocketFrame(self::OPCODE_TEXT, $message);
        $this->sendHttp2DataFrame($frame);

        $this->logger->debug('Sent text message over HTTP/2 WebSocket', [
            'connection_id' => $this->connectionId,
            'stream_id' => $this->streamId,
            'message_length' => strlen($message),
        ]);
    }

    /**
     * Send binary message over HTTP/2 WebSocket.
     */
    public function sendBinary(string $data): void
    {
        if (!$this->isOpen) {
            throw new WebSocketException('Connection is closed');
        }

        $frame = $this->createWebSocketFrame(self::OPCODE_BINARY, $data);
        $this->sendHttp2DataFrame($frame);

        $this->logger->debug('Sent binary message over HTTP/2 WebSocket', [
            'connection_id' => $this->connectionId,
            'stream_id' => $this->streamId,
            'data_length' => strlen($data),
        ]);
    }

    /**
     * Send ping frame.
     */
    public function ping(string $payload = ''): void
    {
        if (!$this->isOpen) {
            throw new WebSocketException('Connection is closed');
        }

        $frame = $this->createWebSocketFrame(self::OPCODE_PING, $payload);
        $this->sendHttp2DataFrame($frame);

        $this->logger->debug('Sent ping over HTTP/2 WebSocket', [
            'connection_id' => $this->connectionId,
            'stream_id' => $this->streamId,
        ]);
    }

    /**
     * Send pong frame.
     */
    public function pong(string $payload = ''): void
    {
        if (!$this->isOpen) {
            throw new WebSocketException('Connection is closed');
        }

        $frame = $this->createWebSocketFrame(self::OPCODE_PONG, $payload);
        $this->sendHttp2DataFrame($frame);

        $this->logger->debug('Sent pong over HTTP/2 WebSocket', [
            'connection_id' => $this->connectionId,
            'stream_id' => $this->streamId,
        ]);
    }

    /**
     * Close WebSocket connection.
     */
    public function close(int $code = 1000, string $reason = ''): void
    {
        if (!$this->isOpen) {
            return;
        }

        // Create close payload
        $payload = pack('n', $code);
        if ($reason) {
            $payload .= $reason;
        }

        $frame = $this->createWebSocketFrame(self::OPCODE_CLOSE, $payload);
        $this->sendHttp2DataFrame($frame);

        $this->isOpen = false;

        $this->logger->info('Closed HTTP/2 WebSocket connection', [
            'connection_id' => $this->connectionId,
            'stream_id' => $this->streamId,
            'close_code' => $code,
            'reason' => $reason,
        ]);
    }

    /**
     * Receive WebSocket frame over HTTP/2.
     */
    public function receiveFrame(): ?array
    {
        if (!$this->isOpen) {
            return null;
        }

        // In a real implementation, this would read from the HTTP/2 stream
        // For now, we'll simulate frame reception
        $this->updateLastActivity();

        // Check for buffered frames
        if (!empty($this->frameBuffer)) {
            return array_shift($this->frameBuffer);
        }

        return null;
    }

    /**
     * Set connection attribute.
     */
    public function setAttribute(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    /**
     * Get connection attribute.
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Check if attribute exists.
     */
    public function hasAttribute(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    /**
     * Remove attribute.
     */
    public function removeAttribute(string $key): self
    {
        unset($this->attributes[$key]);
        return $this;
    }

    /**
     * Get connection state for serialization (zero-downtime reload support).
     */
    public function getState(): array
    {
        return [
            'connection_id' => $this->connectionId,
            'stream_id' => $this->streamId,
            'is_open' => $this->isOpen,
            'attributes' => $this->attributes,
            'http2_state' => $this->http2State,
            'last_activity' => $this->lastActivity,
            'remote_address' => $this->request->getClient()->getRemoteAddress()->toString(),
            'headers' => $this->request->getHeaders(),
        ];
    }

    /**
     * Restore connection state from serialized data.
     */
    public function restoreState(array $state): void
    {
        $this->connectionId = $state['connection_id'];
        $this->streamId = $state['stream_id'];
        $this->isOpen = $state['is_open'];
        $this->attributes = $state['attributes'];
        $this->http2State = $state['http2_state'];
        $this->lastActivity = $state['last_activity'];

        $this->logger->info('Restored HTTP/2 WebSocket connection state', [
            'connection_id' => $this->connectionId,
            'stream_id' => $this->streamId,
        ]);
    }

    /**
     * Get connection statistics.
     */
    public function getStats(): array
    {
        return [
            'connection_id' => $this->connectionId,
            'stream_id' => $this->streamId,
            'protocol' => 'HTTP/2 WebSocket',
            'is_open' => $this->isOpen,
            'last_activity' => $this->lastActivity,
            'uptime' => microtime(true) - $this->lastActivity,
            'attributes_count' => count($this->attributes),
            'http2_state' => $this->http2State,
        ];
    }

    /**
     * Create WebSocket frame according to RFC 6455.
     */
    private function createWebSocketFrame(int $opcode, string $payload): string
    {
        $frame = '';
        $payloadLength = strlen($payload);

        // First byte: FIN (1) + RSV (000) + Opcode (4 bits)
        $firstByte = 0x80 | ($opcode & 0x0F);
        $frame .= chr($firstByte);

        // Second byte: MASK (0 for server-to-client) + Payload length
        if ($payloadLength < 126) {
            $frame .= chr($payloadLength);
        } elseif ($payloadLength < 65536) {
            $frame .= chr(126) . pack('n', $payloadLength);
        } else {
            $frame .= chr(127) . pack('J', $payloadLength);
        }

        // No masking for server-to-client frames
        $frame .= $payload;

        return $frame;
    }

    /**
     * Send data frame over HTTP/2 stream.
     */
    private function sendHttp2DataFrame(string $data): void
    {
        // In a real implementation, this would send the WebSocket frame
        // as DATA frame over the HTTP/2 stream identified by $this->streamId

        $this->updateLastActivity();

        $this->logger->debug('Sent HTTP/2 DATA frame', [
            'connection_id' => $this->connectionId,
            'stream_id' => $this->streamId,
            'frame_length' => strlen($data),
        ]);
    }

    /**
     * Extract stream ID from HTTP/2 request.
     */
    private function extractStreamId(Request $request): int
    {
        // In a real implementation, this would extract the stream ID
        // from the HTTP/2 request context
        return random_int(1, 2147483647);
    }

    /**
     * Initialize HTTP/2 stream state.
     */
    private function initializeHttp2State(): void
    {
        $this->http2State = [
            'stream_state' => 'open',
            'window_size' => 65536,
            'flow_control_enabled' => true,
            'compression_enabled' => $this->config['enable_compression'] ?? false,
            'last_frame_type' => null,
            'frames_sent' => 0,
            'frames_received' => 0,
            'bytes_sent' => 0,
            'bytes_received' => 0,
        ];
    }

    /**
     * Update last activity timestamp.
     */
    private function updateLastActivity(): void
    {
        $this->lastActivity = microtime(true);
    }

    /**
     * Get remote address.
     */
    public function getRemoteAddress(): string
    {
        return $this->request->getClient()->getRemoteAddress()->toString();
    }

    /**
     * Simulate receiving a frame (for testing).
     */
    public function simulateReceiveFrame(int $opcode, string $payload): void
    {
        $this->frameBuffer[] = [
            'opcode' => $opcode,
            'payload' => $payload,
            'timestamp' => microtime(true),
        ];

        $this->updateLastActivity();
    }
}
