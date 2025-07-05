<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Contracts;

interface BroadcasterInterface
{
    /**
     * Broadcast a message to all subscribers of a channel.
     */
    public function broadcast(string $channel, mixed $message): void;

    /**
     * Subscribe to a channel.
     */
    public function subscribe(string $channel, mixed $subscriber): string;

    /**
     * Unsubscribe from a channel.
     */
    public function unsubscribe(string $channel, string $subscriptionId): bool;

    /**
     * Get the number of subscribers for a channel.
     */
    public function getSubscriberCount(string $channel): int;

    /**
     * Get all available channels.
     */
    /** @return string[] */
    public function getChannels(): array;

    /**
     * Get broadcasting statistics.
     */
    /** @return array<string, mixed> */
    public function getStats(): array;

    /**
     * Clear all subscribers from a channel.
     */
    public function clearChannel(string $channel): void;
}
