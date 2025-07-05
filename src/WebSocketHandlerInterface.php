<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\WebSockets;

use Amp\Websocket\Message;

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
     * @param Message             $message
     */
    public function onMessage(WebSocketConnection $connection, Message $message): void;

    /**
     * Handle a WebSocket disconnection.
     *
     * @param WebSocketConnection $connection
     */
    public function onDisconnect(WebSocketConnection $connection): void;
}
