<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\WebSockets;

class Message
{
    private array $data;

    private string $payload;

    private string $type;

    private float $timestamp;

    public function __construct(array $data, string $type = 'text')
    {
        $this->data = $data;
        $this->type = $type;
        $this->payload = json_encode($data);
        $this->timestamp = microtime(true);
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }

    public function setData(array $data): void
    {
        $this->data = $data;
        $this->payload = json_encode($data);
    }

    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'type' => $this->type,
            'timestamp' => $this->timestamp,
        ];
    }

    public function __toString(): string
    {
        return $this->payload;
    }
}
