<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\WebSockets;

class Connection
{
    private string $id;

    private bool $isAlive;

    /** @var array<string, mixed> */
    private array $attributes;

    private int $lastPingTime;

    private int $connectedAt;

    public function __construct(string $id)
    {
        $this->id = $id;
        $this->isAlive = true;
        $this->attributes = [];
        $this->lastPingTime = time();
        $this->connectedAt = time();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function isAlive(): bool
    {
        return $this->isAlive;
    }

    public function setAlive(bool $alive): void
    {
        $this->isAlive = $alive;
    }

    public function getLastPingTime(): int
    {
        return $this->lastPingTime;
    }

    public function updatePingTime(): void
    {
        $this->lastPingTime = time();
    }

    public function getConnectedAt(): int
    {
        return $this->connectedAt;
    }

    public function send(mixed $data): bool
    {
        // Mock implementation - in real scenarios this would send data
        return true;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function hasAttribute(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    public function removeAttribute(string $key): void
    {
        unset($this->attributes[$key]);
    }

    public function close(): void
    {
        $this->isAlive = false;
    }
}
