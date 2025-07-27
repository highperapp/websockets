<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\WebSockets\Compression;

use HighPerApp\HighPer\Compression\CompressionManager;
use Amp\Websocket\Server\WebsocketCompressionContext;
use Psr\Log\LoggerInterface;

/**
 * Enhanced Compression Context
 *
 * Provides WebSocket message compression using the HighPer compression library
 * with support for multiple algorithms and adaptive compression
 */
class EnhancedCompressionContext implements WebsocketCompressionContext
{
    private CompressionManager $compressionEngine;
    private LoggerInterface $logger;
    private array $negotiatedParams;
    private array $config;
    private array $stats = [];
    private ?array $serverContext = null;
    private ?array $clientContext = null;

    public function __construct(
        CompressionManager $compressionEngine,
        LoggerInterface $logger,
        array $negotiatedParams,
        array $config
    ) {
        $this->compressionEngine = $compressionEngine;
        $this->logger = $logger;
        $this->negotiatedParams = $negotiatedParams;
        $this->config = $config;
        
        $this->initializeStats();
        $this->initializeContexts();
    }

    /**
     * Compress outgoing message (server to client)
     */
    public function compress(string $payload, bool $final = true): string
    {
        $startTime = microtime(true);
        
        try {
            // Check if compression should be applied
            if (!$this->shouldCompress($payload)) {
                $this->updateStats('skipped', strlen($payload), strlen($payload), microtime(true) - $startTime);
                return $payload;
            }

            // Select compression algorithm
            $algorithm = $this->selectCompressionAlgorithm($payload);
            
            // Prepare compression options
            $options = [
                'algorithm' => $algorithm,
                'level' => $this->config['compression_level'],
                'window_bits' => $this->negotiatedParams['server_max_window_bits'] ?? 15,
                'streaming' => $this->config['enable_streaming']
            ];

            // Apply per-message-deflate specific handling
            if ($this->config['enable_streaming'] && !$this->negotiatedParams['server_no_context_takeover']) {
                $options['context'] = &$this->serverContext;
            }

            // Compress the payload
            $compressed = $this->compressionEngine->compress($payload, $options);
            
            // Update compression context if needed
            if (isset($options['context'])) {
                $this->serverContext = $options['context'];
            } elseif ($this->negotiatedParams['server_no_context_takeover']) {
                $this->serverContext = null; // Reset context
            }

            $compressionTime = microtime(true) - $startTime;
            $compressionRatio = strlen($compressed) / strlen($payload);

            $this->updateStats('compressed', strlen($payload), strlen($compressed), $compressionTime);

            $this->logger->debug('WebSocket message compressed', [
                'original_size' => strlen($payload),
                'compressed_size' => strlen($compressed),
                'compression_ratio' => $compressionRatio,
                'algorithm' => $algorithm,
                'compression_time_ms' => round($compressionTime * 1000, 2)
            ]);

            return $compressed;

        } catch (\Throwable $e) {
            $this->logger->error('WebSocket compression failed', [
                'error' => $e->getMessage(),
                'payload_size' => strlen($payload)
            ]);

            $this->updateStats('failed', strlen($payload), strlen($payload), microtime(true) - $startTime);
            return $payload; // Return original payload on failure
        }
    }

    /**
     * Decompress incoming message (client to server)
     */
    public function decompress(string $payload): string
    {
        $startTime = microtime(true);
        
        try {
            // Prepare decompression options
            $options = [
                'window_bits' => $this->negotiatedParams['client_max_window_bits'] ?? 15,
                'streaming' => $this->config['enable_streaming']
            ];

            // Apply per-message-deflate specific handling
            if ($this->config['enable_streaming'] && !$this->negotiatedParams['client_no_context_takeover']) {
                $options['context'] = &$this->clientContext;
            }

            // Decompress the payload
            $decompressed = $this->compressionEngine->decompress($payload, $options);

            // Update decompression context if needed
            if (isset($options['context'])) {
                $this->clientContext = $options['context'];
            } elseif ($this->negotiatedParams['client_no_context_takeover']) {
                $this->clientContext = null; // Reset context
            }

            $decompressionTime = microtime(true) - $startTime;

            $this->updateStats('decompressed', strlen($payload), strlen($decompressed), $decompressionTime);

            $this->logger->debug('WebSocket message decompressed', [
                'compressed_size' => strlen($payload),
                'decompressed_size' => strlen($decompressed),
                'decompression_time_ms' => round($decompressionTime * 1000, 2)
            ]);

            return $decompressed;

        } catch (\Throwable $e) {
            $this->logger->error('WebSocket decompression failed', [
                'error' => $e->getMessage(),
                'payload_size' => strlen($payload)
            ]);

            $this->updateStats('decompress_failed', strlen($payload), strlen($payload), microtime(true) - $startTime);
            throw $e; // Re-throw as decompression failure is critical
        }
    }

    /**
     * Get RSV1 bit (indicates compression)
     */
    public function getRsv1(): bool
    {
        return true; // This context handles compression
    }

    /**
     * Check if compression should be applied
     */
    private function shouldCompress(string $payload): bool
    {
        // Don't compress small messages
        if (strlen($payload) < $this->config['min_compression_size']) {
            return false;
        }

        // Use adaptive compression if enabled
        if ($this->config['enable_adaptive_compression']) {
            $analysis = $this->compressionEngine->analyzeContent($payload);
            return $analysis['compression_benefit'] > $this->config['compression_threshold'];
        }

        return true;
    }

    /**
     * Select optimal compression algorithm
     */
    private function selectCompressionAlgorithm(string $payload): string
    {
        if ($this->config['algorithm_selection'] === 'auto') {
            return $this->compressionEngine->selectOptimalAlgorithm($payload, [
                'content_type' => 'application/octet-stream',
                'priority' => 'latency', // WebSocket prioritizes low latency
                'size_threshold' => $this->config['min_compression_size']
            ]);
        }

        return $this->config['algorithm_selection'];
    }

    /**
     * Initialize compression contexts
     */
    private function initializeContexts(): void
    {
        if (!$this->negotiatedParams['server_no_context_takeover']) {
            $this->serverContext = $this->compressionEngine->createCompressionContext([
                'algorithm' => 'deflate',
                'window_bits' => $this->negotiatedParams['server_max_window_bits'] ?? 15
            ]);
        }

        if (!$this->negotiatedParams['client_no_context_takeover']) {
            $this->clientContext = $this->compressionEngine->createCompressionContext([
                'algorithm' => 'deflate',
                'window_bits' => $this->negotiatedParams['client_max_window_bits'] ?? 15
            ]);
        }
    }

    /**
     * Initialize statistics tracking
     */
    private function initializeStats(): void
    {
        $this->stats = [
            'messages_compressed' => 0,
            'messages_decompressed' => 0,
            'messages_skipped' => 0,
            'compression_failures' => 0,
            'decompression_failures' => 0,
            'total_bytes_in' => 0,
            'total_bytes_out' => 0,
            'total_compression_time' => 0.0,
            'total_decompression_time' => 0.0,
            'average_compression_ratio' => 0.0,
            'bytes_saved' => 0
        ];
    }

    /**
     * Update compression statistics
     */
    private function updateStats(string $operation, int $inputSize, int $outputSize, float $time): void
    {
        switch ($operation) {
            case 'compressed':
                $this->stats['messages_compressed']++;
                $this->stats['total_compression_time'] += $time;
                $this->stats['bytes_saved'] += max(0, $inputSize - $outputSize);
                break;
                
            case 'decompressed':
                $this->stats['messages_decompressed']++;
                $this->stats['total_decompression_time'] += $time;
                break;
                
            case 'skipped':
                $this->stats['messages_skipped']++;
                break;
                
            case 'failed':
                $this->stats['compression_failures']++;
                break;
                
            case 'decompress_failed':
                $this->stats['decompression_failures']++;
                break;
        }

        $this->stats['total_bytes_in'] += $inputSize;
        $this->stats['total_bytes_out'] += $outputSize;

        // Update average compression ratio
        if ($this->stats['total_bytes_in'] > 0) {
            $this->stats['average_compression_ratio'] = 
                $this->stats['total_bytes_out'] / $this->stats['total_bytes_in'];
        }
    }

    /**
     * Get compression statistics
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'negotiated_params' => $this->negotiatedParams,
            'config' => $this->config,
            'server_context_active' => $this->serverContext !== null,
            'client_context_active' => $this->clientContext !== null
        ]);
    }

    /**
     * Reset compression contexts (for testing/debugging)
     */
    public function resetContexts(): void
    {
        $this->serverContext = null;
        $this->clientContext = null;
        $this->initializeContexts();
    }

    /**
     * Close compression contexts and cleanup
     */
    public function close(): void
    {
        $this->serverContext = null;
        $this->clientContext = null;
        
        $this->logger->debug('WebSocket compression context closed', [
            'final_stats' => $this->getStats()
        ]);
    }
}