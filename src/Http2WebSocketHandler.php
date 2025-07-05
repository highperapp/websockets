<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\WebSockets;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\WebSocket\WebSocketException;
use Psr\Log\LoggerInterface;

/**
 * RFC 8441 WebSocket over HTTP/2 Handler.
 *
 * Implements WebSocket over HTTP/2 using CONNECT method and :protocol pseudo-header
 * as defined in RFC 8441: "Bootstrapping WebSockets with HTTP/2"
 */
class Http2WebSocketHandler
{
    private LoggerInterface $logger;

    private array $config;

    private array $activeConnections = [];

    private array $http2Settings;

    public function __construct(LoggerInterface $logger, array $config = [])
    {
        $this->logger = $logger;
        $this->config = array_merge([
            'enable_compression' => true,
            'max_frame_size' => 65536,
            'heartbeat_period' => 30,
            'connection_timeout' => 300,
            'max_connections' => 1000,
        ], $config);

        $this->http2Settings = $this->initializeHttp2Settings();
    }

    /**
     * Handle HTTP/2 WebSocket upgrade using CONNECT method (RFC 8441).
     */
    public function handleRequest(Request $request): Response
    {
        $this->logger->info('Processing HTTP/2 WebSocket request', [
            'method' => $request->getMethod(),
            'uri' => (string) $request->getUri(),
            'protocol_version' => $request->getProtocolVersion(),
        ]);

        // Validate RFC 8441 requirements
        if (!$this->isValidHttp2WebSocketRequest($request)) {
            return $this->createErrorResponse(
                HttpStatus::BAD_REQUEST,
                'Invalid HTTP/2 WebSocket request',
            );
        }

        try {
            return $this->establishWebSocketTunnel($request);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to establish HTTP/2 WebSocket tunnel', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->createErrorResponse(
                HttpStatus::INTERNAL_SERVER_ERROR,
                'WebSocket tunnel establishment failed',
            );
        }
    }

    /**
     * Validate HTTP/2 WebSocket request according to RFC 8441.
     */
    private function isValidHttp2WebSocketRequest(Request $request): bool
    {
        // RFC 8441 Section 4: CONNECT method requirement
        if ($request->getMethod() !== 'CONNECT') {
            $this->logger->debug('Invalid method for HTTP/2 WebSocket', [
                'method' => $request->getMethod(),
                'expected' => 'CONNECT',
            ]);
            return false;
        }

        // RFC 8441 Section 4: :protocol pseudo-header must be "websocket"
        $protocolHeader = $request->getHeader(':protocol');
        if ($protocolHeader !== 'websocket') {
            $this->logger->debug('Missing or invalid :protocol pseudo-header', [
                'protocol' => $protocolHeader,
                'expected' => 'websocket',
            ]);
            return false;
        }

        // RFC 8441 Section 4: :scheme and :path pseudo-headers must be omitted
        if ($request->hasHeader(':scheme') || $request->hasHeader(':path')) {
            $this->logger->debug('Invalid pseudo-headers present for CONNECT');
            return false;
        }

        // Standard WebSocket headers validation
        $secWebSocketKey = $request->getHeader('sec-websocket-key');
        $secWebSocketVersion = $request->getHeader('sec-websocket-version');

        if (!$secWebSocketKey || $secWebSocketVersion !== '13') {
            $this->logger->debug('Invalid WebSocket headers', [
                'key_present' => !empty($secWebSocketKey),
                'version' => $secWebSocketVersion,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Establish WebSocket tunnel over HTTP/2 stream.
     */
    private function establishWebSocketTunnel(Request $request): Response
    {
        $connectionId = $this->generateConnectionId();
        $secWebSocketKey = $request->getHeader('sec-websocket-key');

        // Generate WebSocket accept key (RFC 6455)
        $acceptKey = base64_encode(
            sha1($secWebSocketKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true),
        );

        // RFC 8441 Section 5: Successful CONNECT response
        $headers = [
            ':status' => '200',
            'sec-websocket-accept' => $acceptKey,
            'sec-websocket-protocol' => $this->negotiateSubProtocol($request),
            'sec-websocket-extensions' => $this->negotiateExtensions($request),
        ];

        // Remove empty headers
        $headers = array_filter($headers, static fn ($value) => !empty($value));

        $this->logger->info('HTTP/2 WebSocket tunnel established', [
            'connection_id' => $connectionId,
            'remote_address' => $request->getClient()->getRemoteAddress()->toString(),
            'subprotocol' => $headers['sec-websocket-protocol'] ?? 'none',
            'extensions' => $headers['sec-websocket-extensions'] ?? 'none',
        ]);

        // Create HTTP/2 WebSocket connection
        $connection = $this->createHttp2WebSocketConnection($request, $connectionId);
        $this->activeConnections[$connectionId] = $connection;

        // Start connection handler in background
        $this->handleWebSocketConnection($connection);

        return new Response(HttpStatus::OK, $headers, '');
    }

    /**
     * Create HTTP/2 WebSocket connection wrapper.
     */
    private function createHttp2WebSocketConnection(Request $request, string $connectionId): Http2WebSocketConnection
    {
        return new Http2WebSocketConnection(
            $connectionId,
            $request,
            $this->logger,
            $this->config,
        );
    }

    /**
     * Handle WebSocket connection over HTTP/2 stream.
     */
    private function handleWebSocketConnection(Http2WebSocketConnection $connection): void
    {
        // Run connection handler asynchronously
        \Amp\async(function () use ($connection): void {
            try {
                $this->logger->info('Starting HTTP/2 WebSocket connection handler', [
                    'connection_id' => $connection->getId(),
                ]);

                // Send welcome message
                $connection->send('Welcome to HTTP/2 WebSocket!');

                // Handle incoming frames
                while ($connection->isOpen()) {
                    $frame = $connection->receiveFrame();

                    if ($frame === null) {
                        break; // Connection closed
                    }

                    $this->processWebSocketFrame($connection, $frame);
                }
            } catch (WebSocketException $e) {
                $this->logger->warning('HTTP/2 WebSocket error', [
                    'connection_id' => $connection->getId(),
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('Unexpected error in HTTP/2 WebSocket handler', [
                    'connection_id' => $connection->getId(),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            } finally {
                $this->cleanupConnection($connection);
            }
        });
    }

    /**
     * Process WebSocket frame over HTTP/2.
     */
    private function processWebSocketFrame(Http2WebSocketConnection $connection, array $frame): void
    {
        $this->logger->debug('Processing HTTP/2 WebSocket frame', [
            'connection_id' => $connection->getId(),
            'opcode' => $frame['opcode'],
            'payload_length' => strlen($frame['payload']),
        ]);

        switch ($frame['opcode']) {
            case 0x1: // Text frame
                $response = 'HTTP/2 Echo: ' . $frame['payload'];
                $connection->send($response);
                break;

            case 0x2: // Binary frame
                $connection->sendBinary($frame['payload']);
                break;

            case 0x8: // Close frame
                $connection->close();
                break;

            case 0x9: // Ping frame
                $connection->pong($frame['payload']);
                break;

            case 0xA: // Pong frame
                // Handle pong response
                break;

            default:
                $this->logger->warning('Unknown WebSocket opcode', [
                    'opcode' => $frame['opcode'],
                    'connection_id' => $connection->getId(),
                ]);
        }
    }

    /**
     * Negotiate WebSocket subprotocol.
     */
    private function negotiateSubProtocol(Request $request): ?string
    {
        $requestedProtocols = $request->getHeader('sec-websocket-protocol');

        if (!$requestedProtocols) {
            return null;
        }

        $protocols = array_map('trim', explode(',', $requestedProtocols));
        $supportedProtocols = ['chat', 'echo', 'json'];

        foreach ($protocols as $protocol) {
            if (in_array($protocol, $supportedProtocols, true)) {
                return $protocol;
            }
        }

        return null;
    }

    /**
     * Negotiate WebSocket extensions.
     */
    private function negotiateExtensions(Request $request): ?string
    {
        $requestedExtensions = $request->getHeader('sec-websocket-extensions');

        if (!$requestedExtensions || !$this->config['enable_compression']) {
            return null;
        }

        // Support permessage-deflate extension
        if (str_contains($requestedExtensions, 'permessage-deflate')) {
            return 'permessage-deflate; client_max_window_bits=15; server_max_window_bits=15';
        }

        return null;
    }

    /**
     * Generate unique connection ID.
     */
    private function generateConnectionId(): string
    {
        return 'http2-ws-' . bin2hex(random_bytes(8)) . '-' . time();
    }

    /**
     * Initialize HTTP/2 settings for WebSocket support.
     */
    private function initializeHttp2Settings(): array
    {
        return [
            'SETTINGS_ENABLE_CONNECT_PROTOCOL' => 1, // RFC 8441 requirement
            'SETTINGS_MAX_CONCURRENT_STREAMS' => 100,
            'SETTINGS_INITIAL_WINDOW_SIZE' => 65536,
            'SETTINGS_MAX_FRAME_SIZE' => $this->config['max_frame_size'],
        ];
    }

    /**
     * Create error response.
     */
    private function createErrorResponse(int $status, string $message): Response
    {
        return new Response($status, [
            'content-type' => 'application/json',
        ], json_encode([
            'error' => $message,
            'protocol' => 'HTTP/2 WebSocket',
            'rfc' => 'RFC 8441',
        ]));
    }

    /**
     * Cleanup connection resources.
     */
    private function cleanupConnection(Http2WebSocketConnection $connection): void
    {
        $connectionId = $connection->getId();

        if (isset($this->activeConnections[$connectionId])) {
            unset($this->activeConnections[$connectionId]);
        }

        $this->logger->info('HTTP/2 WebSocket connection cleaned up', [
            'connection_id' => $connectionId,
            'active_connections' => count($this->activeConnections),
        ]);
    }

    /**
     * Get connection statistics.
     */
    public function getStats(): array
    {
        return [
            'protocol' => 'HTTP/2 WebSocket (RFC 8441)',
            'active_connections' => count($this->activeConnections),
            'max_connections' => $this->config['max_connections'],
            'http2_settings' => $this->http2Settings,
            'compression_enabled' => $this->config['enable_compression'],
        ];
    }

    /**
     * Get HTTP/2 settings for WebSocket support.
     */
    public function getHttp2Settings(): array
    {
        return $this->http2Settings;
    }
}
