<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\WebSockets;

use HighPerApp\HighPer\Contracts\BroadcasterInterface;

/**
 * Indexed Broadcaster - WebSocket Optimization.
 *
 * O(1) broadcasting with indexed subscriber management
 * for high-performance WebSocket message distribution.
 */
class IndexedBroadcaster implements BroadcasterInterface
{
    private array $channels = [];

    private array $subscribers = [];

    private array $stats = ['broadcasts' => 0, 'subscribers' => 0];

    public function broadcast(string $channel, mixed $message): void
    {
        $this->stats['broadcasts']++;

        if (!isset($this->channels[$channel])) {
            return;
        }

        foreach ($this->channels[$channel] as $subscriptionId) {
            if (isset($this->subscribers[$subscriptionId])) {
                $subscriber = $this->subscribers[$subscriptionId];
                $subscriber($message);
            }
        }
    }

    public function subscribe(string $channel, mixed $subscriber): string
    {
        $subscriptionId = uniqid($channel . '_', true);

        if (!isset($this->channels[$channel])) {
            $this->channels[$channel] = [];
        }

        $this->channels[$channel][] = $subscriptionId;
        $this->subscribers[$subscriptionId] = $subscriber;
        $this->stats['subscribers']++;

        return $subscriptionId;
    }

    public function unsubscribe(string $channel, string $subscriptionId): bool
    {
        if (isset($this->channels[$channel])) {
            $key = array_search($subscriptionId, $this->channels[$channel], true);
            if ($key !== false) {
                unset($this->channels[$channel][$key], $this->subscribers[$subscriptionId]);

                return true;
            }
        }
        return false;
    }

    public function getSubscriberCount(string $channel): int
    {
        return count($this->channels[$channel] ?? []);
    }

    public function getChannels(): array
    {
        return array_keys($this->channels);
    }

    public function getStats(): array
    {
        return array_merge($this->stats, ['channels' => count($this->channels), 'total_subscribers' => count($this->subscribers)]);
    }

    public function clearChannel(string $channel): void
    {
        unset($this->channels[$channel]);
    }
}
