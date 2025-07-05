<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\WebSockets;

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\InternetAddress;
use Amp\WebSocket\Server\Websocket;
use Amp\WebSocket\Server\WebSocketGateway;
use Amp\WebSocket\Server\WebSocketServerFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * WebSocket Server Handler.
 *
 * Environment-configurable WebSocket server supporting both direct access
 * and behind-proxy deployments.
 */
class WebSocketServerHandler
{
    protected SocketHttpServer $server;

    protected ContainerInterface $container;

    protected LoggerInterface $logger;

    protected string $host;

    protected int $port;

    protected WebSocketGateway $gateway;

    public function __construct(ContainerInterface $container, string $host, int $port)
    {
        $this->container = $container;
        $this->logger = $container->get(LoggerInterface::class);
        $this->host = $host;
        $this->port = $port;

        $this->initialize();
    }

    /**
     * Initialize the WebSocket server with environment-based configuration.
     */
    protected function initialize(): void
    {
        $this->logger->info('Initializing WebSocket server handler', [
            'host' => $this->host,
            'port' => $this->port,
        ]);

        // Determine server mode from environment
        $serverMode = $_ENV['SERVER_MODE'] ?? $_ENV['WEBSOCKET_SERVER_MODE'] ?? 'direct';
        $enableCompression = filter_var($_ENV['WEBSOCKET_COMPRESSION'] ?? 'true', FILTER_VALIDATE_BOOLEAN);
        $heartbeatPeriod = (int) ($_ENV['WEBSOCKET_HEARTBEAT_PERIOD'] ?? 30);
        $maxFrameSize = (int) ($_ENV['WEBSOCKET_MAX_FRAME_SIZE'] ?? 2097152); // 2MB default

        $this->logger->info('WebSocket server configuration', [
            'mode' => $serverMode,
            'compression' => $enableCompression ? 'enabled' : 'disabled',
            'heartbeat_period' => $heartbeatPeriod,
            'max_frame_size' => $maxFrameSize,
        ]);

        // Create server based on deployment mode
        switch (strtolower($serverMode)) {
            case 'proxy':
            case 'behind-proxy':
                $this->server = SocketHttpServer::createForBehindProxy(
                    $this->logger,
                    $enableCompression,
                );
                $this->logger->info('Created WebSocket server for behind-proxy deployment');
                break;

            case 'direct':
            case 'direct-access':
            default:
                $this->server = SocketHttpServer::createForDirectAccess(
                    $this->logger,
                    $enableCompression,
                );
                $this->logger->info('Created WebSocket server for direct access deployment');
                break;
        }

        // Expose the server address
        $this->server->expose(new InternetAddress($this->host, $this->port));

        // Create WebSocket gateway with configuration
        $this->gateway = new WebSocketServerFactory(
            heartbeatPeriod: $heartbeatPeriod,
            maxFrameSize: $maxFrameSize,
            compression: $enableCompression,
        );

        // Create WebSocket handler
        $wsHandler = $this->container->has(WebSocketHandler::class)
            ? $this->container->get(WebSocketHandler::class)
            : new WebSocketHandler($this->logger);

        // Create WebSocket endpoint
        $websocket = new Websocket(
            $this->gateway,
            [$wsHandler, 'handleConnection'],
        );

        // Create request handler that serves WebSocket upgrades
        $requestHandler = new ClosureRequestHandler(static function ($request) use ($websocket) {
            // Check if this is a WebSocket upgrade request
            $upgrade = $request->getHeader('upgrade');
            $connection = $request->getHeader('connection');

            if ($upgrade && strtolower($upgrade) === 'websocket' &&
                $connection && stripos($connection, 'upgrade') !== false) {
                return $websocket->handleRequest($request);
            }

            // Return 404 for non-WebSocket requests
            return new \Amp\Http\Server\Response(404, [], 'WebSocket endpoint only');
        });

        // Create error handler
        $errorHandler = new DefaultErrorHandler();

        // Start the server
        $this->server->start($requestHandler, $errorHandler);

        $this->logger->info('WebSocket server handler started successfully', [
            'host' => $this->host,
            'port' => $this->port,
            'mode' => $serverMode,
            'compression' => $enableCompression,
            'heartbeat_period' => $heartbeatPeriod,
            'max_frame_size' => $maxFrameSize,
        ]);
    }

    /**
     * Stop the WebSocket server.
     */
    public function stop(): void
    {
        $this->logger->info('Stopping WebSocket server handler');
        $this->server->stop();
        $this->logger->info('WebSocket server handler stopped');
    }

    /**
     * Check if server is running.
     */
    public function isRunning(): bool
    {
        return isset($this->server);
    }

    /**
     * Get server statistics.
     */
    public function getStats(): array
    {
        return [
            'protocol' => 'websocket',
            'host' => $this->host,
            'port' => $this->port,
            'mode' => $_ENV['SERVER_MODE'] ?? 'direct',
            'compression' => filter_var($_ENV['WEBSOCKET_COMPRESSION'] ?? 'true', FILTER_VALIDATE_BOOLEAN),
            'heartbeat_period' => (int) ($_ENV['WEBSOCKET_HEARTBEAT_PERIOD'] ?? 30),
            'max_frame_size' => (int) ($_ENV['WEBSOCKET_MAX_FRAME_SIZE'] ?? 2097152),
            'running' => $this->isRunning(),
        ];
    }
}
