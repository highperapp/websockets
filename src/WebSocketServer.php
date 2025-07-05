<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\WebSockets;

use HighPerApp\HighPer\WebSockets\Exceptions\ConnectionException;
use HighPerApp\HighPer\WebSockets\Exceptions\WebSocketException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class WebSocketServer
{
    private array $config;

    private array $connections = [];

    private array $eventHandlers = [];

    private array $middleware = [];

    private LoggerInterface $logger;

    private array $stats;

    private float $startTime;

    /** @var callable|null */
    private $messageValidator = null;

    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->validateConfig($config);

        $this->config = array_merge([
            'host' => '127.0.0.1',
            'port' => 8080,
            'max_connections' => 1000,
            'heartbeat_interval' => 30,
            'ping_timeout' => 60,
            'compression' => false,
            'max_frame_size' => 1048576,
            'ssl' => [
                'enabled' => false,
                'cert' => '',
                'key' => '',
                'passphrase' => '',
            ],
        ], $config);

        $this->logger = $logger ?? new NullLogger();
        $this->startTime = microtime(true);
        $this->initializeStats();
    }

    public function getHost(): string
    {
        return $this->config['host'];
    }

    public function getPort(): int
    {
        return $this->config['port'];
    }

    public function getMaxConnections(): int
    {
        return $this->config['max_connections'];
    }

    public function onConnect(callable $handler): void
    {
        $this->eventHandlers['connect'] = $handler;
    }

    public function onMessage(callable $handler): void
    {
        $this->eventHandlers['message'] = $handler;
    }

    public function onDisconnect(callable $handler): void
    {
        $this->eventHandlers['disconnect'] = $handler;
    }

    public function addConnection(Connection $connection): void
    {
        if (count($this->connections) >= $this->config['max_connections']) {
            throw new ConnectionException('Maximum connections reached');
        }

        $this->connections[$connection->getId()] = $connection;
    }

    public function removeConnection(Connection $connection): void
    {
        unset($this->connections[$connection->getId()]);
    }

    public function getConnection(string $id): ?Connection
    {
        return $this->connections[$id] ?? null;
    }

    public function hasConnection(string $id): bool
    {
        return isset($this->connections[$id]);
    }

    public function getConnectionCount(): int
    {
        return count($this->connections);
    }

    public function broadcast($data): void
    {
        foreach ($this->connections as $connection) {
            $connection->send($data);
        }
    }

    public function broadcastWhere($data, callable $filter): void
    {
        foreach ($this->connections as $connection) {
            if ($filter($connection)) {
                $connection->send($data);
            }
        }
    }

    public function addMiddleware(callable $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    public function setMessageValidator(callable $validator): void
    {
        $this->messageValidator = $validator;
    }

    public function validateMessage(Message $message): bool
    {
        if ($this->messageValidator === null) {
            return true;
        }

        return ($this->messageValidator)($message);
    }

    public function handleConnect(Connection $connection): void
    {
        if (isset($this->eventHandlers['connect'])) {
            ($this->eventHandlers['connect'])($connection);
        }

        $this->stats['connections']['total']++;
    }

    public function handleMessage(Connection $connection, Message $message): void
    {
        $this->processMessage($connection, $message);
        $this->stats['messages']['received']++;
    }

    public function handleDisconnect(Connection $connection): void
    {
        if (isset($this->eventHandlers['disconnect'])) {
            ($this->eventHandlers['disconnect'])($connection);
        }
    }

    public function processMessage(Connection $connection, Message $message): void
    {
        $handler = $this->eventHandlers['message'] ?? null;

        if ($handler === null) {
            return;
        }

        $next = static function (Connection $conn, Message $msg) use ($handler): void {
            $handler($conn, $msg);
        };

        // Process through middleware stack
        $stack = array_reverse($this->middleware);
        foreach ($stack as $middleware) {
            $next = static fn (Connection $conn, Message $msg) => $middleware($conn, $msg, $next);
        }

        $next($connection, $message);
    }

    public function validateFrame(Frame $frame): bool
    {
        // Check opcode validity
        $validOpcodes = [
            Frame::OPCODE_TEXT,
            Frame::OPCODE_BINARY,
            Frame::OPCODE_CLOSE,
            Frame::OPCODE_PING,
            Frame::OPCODE_PONG,
        ];

        if (!in_array($frame->getOpcode(), $validOpcodes, true)) {
            return false;
        }

        // Check frame size
        if (strlen($frame->getPayload()) > $this->config['max_frame_size']) {
            return false;
        }

        return true;
    }

    public function compressFrame(string $data): string
    {
        if (!$this->config['compression']) {
            return $data;
        }

        return gzcompress($data);
    }

    public function decompressFrame(string $data): string
    {
        if (!$this->config['compression']) {
            return $data;
        }

        return gzuncompress($data);
    }

    public function checkTimeouts(): array
    {
        $timedOut = [];
        $timeout = $this->config['ping_timeout'];

        foreach ($this->connections as $connection) {
            if (!$connection->isAlive() ||
                (time() - $connection->getLastPingTime()) > $timeout) {
                $timedOut[] = $connection;
            }
        }

        return $timedOut;
    }

    public function isSslEnabled(): bool
    {
        return $this->config['ssl']['enabled'] ?? false;
    }

    public function getSslCertPath(): string
    {
        return $this->config['ssl']['cert'] ?? '';
    }

    public function getSslKeyPath(): string
    {
        return $this->config['ssl']['key'] ?? '';
    }

    public function getStats(): array
    {
        return array_merge($this->stats, [
            'uptime' => microtime(true) - $this->startTime,
            'memory_usage' => memory_get_usage(true),
            'connections' => [
                'active' => count($this->connections),
                'total' => $this->stats['connections']['total'],
            ],
        ]);
    }

    private function validateConfig(array $config): void
    {
        if (isset($config['host']) && empty($config['host'])) {
            throw new WebSocketException('Invalid host specified');
        }

        if (isset($config['port'])) {
            $port = $config['port'];
            // Allow port 0 for testing (auto-assign), otherwise 1-65535
            if ($port < 0 || $port > 65535) {
                throw new WebSocketException('Port must be between 1 and 65535');
            }
        }

        if (isset($config['max_connections']) && $config['max_connections'] <= 0) {
            throw new WebSocketException('Max connections must be greater than 0');
        }
    }

    private function initializeStats(): void
    {
        $this->stats = [
            'connections' => [
                'active' => 0,
                'total' => 0,
            ],
            'messages' => [
                'sent' => 0,
                'received' => 0,
            ],
            'bytes' => [
                'sent' => 0,
                'received' => 0,
            ],
        ];
    }
}
