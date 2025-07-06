<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\WebSockets;

use Amp\Websocket\WebsocketMessage;

interface WebSocketHandlerInterface
{
    /**
     * Handle a new WebSocket connection.
     *
     * @param WebSocketConnection $connection
     */
    public function onConnect(WebSocketConnection $connection): void;

    /**
     * Handle a WebSocket message.
     *
     * @param WebSocketConnection $connection
     * @param WebsocketMessage     $message
     */
    public function onMessage(WebSocketConnection $connection, WebsocketMessage $message): void;

    /**
     * Handle a WebSocket disconnection.
     *
     * @param WebSocketConnection $connection
     */
    public function onDisconnect(WebSocketConnection $connection): void;
}
