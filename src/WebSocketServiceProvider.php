<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\WebSockets;

use HighPerApp\HighPer\Contracts\ApplicationInterface;
use HighPerApp\HighPer\Contracts\ContainerInterface;
use HighPerApp\HighPer\ServiceProvider\CoreServiceProvider;

/**
 * WebSocket Service Provider.
 *
 * Registers WebSocket services with the HighPer framework.
 */
class WebSocketServiceProvider extends CoreServiceProvider
{
    protected array $provides = [
        'websocket.server',
        'websocket.handler',
        WebSocketServerHandler::class,
    ];

    public function register(ContainerInterface $container): void
    {
        // Register WebSocket server handler
        $this->singleton($container, WebSocketServerHandler::class, function () {
            $config = $this->getWebSocketConfig();
            return new WebSocketServerHandler($config);
        });

        // Register aliases
        $this->alias($container, 'websocket.server', WebSocketServerHandler::class);
        $this->alias($container, 'websocket.handler', WebSocketServerHandler::class);

        // Register additional WebSocket components if available
        if ($this->classExists(Http2WebSocketHandler::class)) {
            $this->singleton($container, Http2WebSocketHandler::class, static fn () => new Http2WebSocketHandler());
            $this->alias($container, 'websocket.http2', Http2WebSocketHandler::class);
        }

        if ($this->classExists(StreamingWebSocketHandler::class)) {
            $this->singleton($container, StreamingWebSocketHandler::class, static fn () => new StreamingWebSocketHandler());
            $this->alias($container, 'websocket.streaming', StreamingWebSocketHandler::class);
        }
    }

    public function boot(ApplicationInterface $app): void
    {
        // Register WebSocket routes
        $this->addRoute($app, 'GET', '/ws', static function ($request) use ($app) {
            $container = $app->getContainer();
            $handler = $container->get(WebSocketServerHandler::class);
            return $handler->handle($request);
        });

        // Register health check for WebSocket
        $this->registerHealthCheck($app, 'websocket', static function () use ($app) {
            $container = $app->getContainer();

            if (!$container->has(WebSocketServerHandler::class)) {
                return [
                    'status' => 'warning',
                    'data' => ['message' => 'WebSocket handler not available'],
                ];
            }

            try {
                $handler = $container->get(WebSocketServerHandler::class);

                return [
                    'status' => 'healthy',
                    'data' => [
                        'handler_available' => true,
                        'active_connections' => method_exists($handler, 'getConnectionCount')
                            ? $handler->getConnectionCount()
                            : 0,
                    ],
                ];
            } catch (\Throwable $e) {
                return [
                    'status' => 'critical',
                    'data' => ['error' => $e->getMessage()],
                ];
            }
        });

        // Register metrics collector for WebSocket
        $this->registerMetricsCollector($app, 'websocket', static function () use ($app) {
            $container = $app->getContainer();

            if (!$container->has(WebSocketServerHandler::class)) {
                return ['websocket_available' => 0];
            }

            try {
                $handler = $container->get(WebSocketServerHandler::class);

                $metrics = ['websocket_available' => 1];

                if (method_exists($handler, 'getStats')) {
                    $stats = $handler->getStats();
                    $metrics = array_merge($metrics, [
                        'websocket_connections_active' => $stats['active_connections'] ?? 0,
                        'websocket_connections_total' => $stats['total_connections'] ?? 0,
                        'websocket_messages_sent' => $stats['messages_sent'] ?? 0,
                        'websocket_messages_received' => $stats['messages_received'] ?? 0,
                        'websocket_bytes_sent' => $stats['bytes_sent'] ?? 0,
                        'websocket_bytes_received' => $stats['bytes_received'] ?? 0,
                    ]);
                }

                return $metrics;
            } catch (\Throwable $e) {
                return [
                    'websocket_available' => 0,
                    'websocket_error' => 1,
                ];
            }
        });

        $this->log($app, 'info', 'WebSocket service provider booted successfully');
    }

    public function getDependencies(): array
    {
        return ['router', 'logger'];
    }

    public function checkRequirements(): bool
    {
        // Check if required extensions are available
        $requiredExtensions = ['sockets'];

        foreach ($requiredExtensions as $extension) {
            if (!$this->extensionLoaded($extension)) {
                return false;
            }
        }

        return true;
    }

    private function getWebSocketConfig(): array
    {
        return [
            'host' => $this->env('WEBSOCKET_HOST', '0.0.0.0'),
            'port' => (int) $this->env('WEBSOCKET_PORT', 8081),
            'max_connections' => (int) $this->env('WEBSOCKET_MAX_CONNECTIONS', 1000),
            'message_size_limit' => (int) $this->env('WEBSOCKET_MESSAGE_SIZE_LIMIT', 1048576), // 1MB
            'ping_interval' => (int) $this->env('WEBSOCKET_PING_INTERVAL', 30),
            'pong_timeout' => (int) $this->env('WEBSOCKET_PONG_TIMEOUT', 10),
            'compression' => $this->env('WEBSOCKET_COMPRESSION', true),
            'subprotocols' => explode(',', $this->env('WEBSOCKET_SUBPROTOCOLS', '')),
            'origins' => explode(',', $this->env('WEBSOCKET_ORIGINS', '*')),
            'ssl' => [
                'enabled' => $this->env('WEBSOCKET_SSL_ENABLED', false),
                'cert' => $this->env('WEBSOCKET_SSL_CERT', ''),
                'key' => $this->env('WEBSOCKET_SSL_KEY', ''),
                'passphrase' => $this->env('WEBSOCKET_SSL_PASSPHRASE', ''),
            ],
        ];
    }
}
