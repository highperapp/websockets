<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\WebSockets;

use Amp\Cancellation;
use Amp\Websocket\Client;
use Amp\Websocket\Message;

class WebSocketConnection
{
    /**
     * @var array The connection attributes
     */
    protected array $attributes = [];

    /**
     * Create a new WebSocket connection.
     *
     * @param Client $client
     */
    public function __construct(protected Client $client)
    {
    }

    /**
     * Get the client ID.
     *
     * @return int
     */
    public function getId(): int
    {
        return $this->client->getId();
    }

    /**
     * Check if the connection is open.
     *
     * @return bool
     */
    public function isOpen(): bool
    {
        return $this->client->isConnected();
    }

    /**
     * Receive a message.
     *
     * @param Cancellation|null $cancellation
     *
     * @return Message|null
     */
    public function receive(?Cancellation $cancellation = null): ?Message
    {
        return $this->client->receive($cancellation);
    }

    /**
     * Send a message.
     *
     * @param string $message
     */
    public function send(string $message): void
    {
        $this->client->send($message);
    }

    /**
     * Send a JSON message.
     *
     * @param mixed $data
     */
    public function sendJson(mixed $data): void
    {
        $this->send(json_encode($data));
    }

    /**
     * Close the connection.
     *
     * @param int    $code
     * @param string $reason
     */
    public function close(int $code = 1000, string $reason = ''): void
    {
        $this->client->close($code, $reason);
    }

    /**
     * Set a connection attribute.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return self
     */
    public function setAttribute(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * Get a connection attribute.
     *
     * @param string $key
     * @param mixed  $default
     *
     * @return mixed
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Check if a connection attribute exists.
     *
     * @param string $key
     *
     * @return bool
     */
    public function hasAttribute(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    /**
     * Remove a connection attribute.
     *
     * @param string $key
     *
     * @return self
     */
    public function removeAttribute(string $key): self
    {
        unset($this->attributes[$key]);

        return $this;
    }

    /**
     * Get the underlying client.
     *
     * @return Client
     */
    public function getClient(): Client
    {
        return $this->client;
    }
}
