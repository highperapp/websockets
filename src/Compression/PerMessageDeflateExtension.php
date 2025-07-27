<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\WebSockets\Compression;

use HighPerApp\HighPer\Compression\CompressionManager;
use Amp\Websocket\Server\WebsocketCompressionContext;
use Amp\Websocket\Server\WebsocketCompressionContextFactory;
use Psr\Log\LoggerInterface;

/**
 * Enhanced Per-Message Deflate Extension
 *
 * Integrates with HighPer compression library for advanced WebSocket compression
 * with support for multiple algorithms and adaptive compression strategies
 */
class PerMessageDeflateExtension implements WebsocketCompressionContextFactory
{
    private CompressionManager $compressionEngine;
    private LoggerInterface $logger;
    private array $config;
    private array $negotiatedParams = [];

    // RFC 7692 Parameters
    private const PARAM_SERVER_NO_CONTEXT_TAKEOVER = 'server_no_context_takeover';
    private const PARAM_CLIENT_NO_CONTEXT_TAKEOVER = 'client_no_context_takeover';
    private const PARAM_SERVER_MAX_WINDOW_BITS = 'server_max_window_bits';
    private const PARAM_CLIENT_MAX_WINDOW_BITS = 'client_max_window_bits';

    public function __construct(CompressionManager $compressionEngine, LoggerInterface $logger, array $config = [])
    {
        $this->compressionEngine = $compressionEngine;
        $this->logger = $logger;
        $this->config = array_merge([
            'server_max_window_bits' => 15,
            'client_max_window_bits' => 15,
            'server_no_context_takeover' => false,
            'client_no_context_takeover' => false,
            'min_compression_size' => 64,
            'compression_level' => 6,
            'compression_threshold' => 0.8, // Only compress if savings > 20%
            'enable_adaptive_compression' => true,
            'algorithm_selection' => 'auto', // auto, gzip, deflate, zstd, lz4
            'memory_limit_mb' => 32,
            'enable_streaming' => true
        ], $config);
    }

    /**
     * Negotiate compression parameters with client
     */
    public function negotiate(string $extensionHeader): ?array
    {
        $this->logger->debug('Negotiating per-message-deflate compression', [
            'extension_header' => $extensionHeader
        ]);

        $params = $this->parseExtensionHeader($extensionHeader);
        $negotiated = $this->negotiateParameters($params);

        if ($negotiated === null) {
            $this->logger->info('Per-message-deflate negotiation failed');
            return null;
        }

        $this->negotiatedParams = $negotiated;

        $this->logger->info('Per-message-deflate negotiation successful', [
            'negotiated_params' => $negotiated
        ]);

        return $negotiated;
    }

    /**
     * Create compression context for a WebSocket connection
     */
    public function createCompressionContext(array $negotiatedParams): WebsocketCompressionContext
    {
        return new EnhancedCompressionContext(
            $this->compressionEngine,
            $this->logger,
            $negotiatedParams,
            $this->config
        );
    }

    /**
     * Get extension name
     */
    public function getName(): string
    {
        return 'permessage-deflate';
    }

    /**
     * Get default extension parameters
     */
    public function getDefaultParameters(): array
    {
        return [
            self::PARAM_SERVER_MAX_WINDOW_BITS => $this->config['server_max_window_bits'],
            self::PARAM_CLIENT_MAX_WINDOW_BITS => $this->config['client_max_window_bits'],
            self::PARAM_SERVER_NO_CONTEXT_TAKEOVER => $this->config['server_no_context_takeover'],
            self::PARAM_CLIENT_NO_CONTEXT_TAKEOVER => $this->config['client_no_context_takeover']
        ];
    }

    /**
     * Parse WebSocket extension header
     */
    private function parseExtensionHeader(string $header): array
    {
        $params = [];
        $parts = explode(';', $header);

        // Skip the extension name (first part)
        array_shift($parts);

        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }

            if (str_contains($part, '=')) {
                [$key, $value] = explode('=', $part, 2);
                $params[trim($key)] = trim($value);
            } else {
                $params[trim($part)] = true;
            }
        }

        return $params;
    }

    /**
     * Negotiate compression parameters
     */
    private function negotiateParameters(array $clientParams): ?array
    {
        $negotiated = [];

        // Negotiate server_max_window_bits
        $serverMaxBits = $this->config['server_max_window_bits'];
        if (isset($clientParams[self::PARAM_SERVER_MAX_WINDOW_BITS])) {
            $requestedBits = (int)$clientParams[self::PARAM_SERVER_MAX_WINDOW_BITS];
            if ($requestedBits >= 8 && $requestedBits <= 15) {
                $serverMaxBits = min($serverMaxBits, $requestedBits);
            }
        }
        $negotiated[self::PARAM_SERVER_MAX_WINDOW_BITS] = $serverMaxBits;

        // Negotiate client_max_window_bits
        $clientMaxBits = $this->config['client_max_window_bits'];
        if (isset($clientParams[self::PARAM_CLIENT_MAX_WINDOW_BITS])) {
            $requestedBits = (int)$clientParams[self::PARAM_CLIENT_MAX_WINDOW_BITS];
            if ($requestedBits >= 8 && $requestedBits <= 15) {
                $clientMaxBits = min($clientMaxBits, $requestedBits);
            }
        }
        $negotiated[self::PARAM_CLIENT_MAX_WINDOW_BITS] = $clientMaxBits;

        // Negotiate server_no_context_takeover
        $negotiated[self::PARAM_SERVER_NO_CONTEXT_TAKEOVER] = 
            $this->config['server_no_context_takeover'] || 
            isset($clientParams[self::PARAM_SERVER_NO_CONTEXT_TAKEOVER]);

        // Negotiate client_no_context_takeover
        $negotiated[self::PARAM_CLIENT_NO_CONTEXT_TAKEOVER] = 
            $this->config['client_no_context_takeover'] || 
            isset($clientParams[self::PARAM_CLIENT_NO_CONTEXT_TAKEOVER]);

        return $negotiated;
    }

    /**
     * Generate extension response header
     */
    public function generateResponseHeader(array $negotiatedParams): string
    {
        $parts = ['permessage-deflate'];

        if ($negotiatedParams[self::PARAM_SERVER_NO_CONTEXT_TAKEOVER]) {
            $parts[] = self::PARAM_SERVER_NO_CONTEXT_TAKEOVER;
        }

        if ($negotiatedParams[self::PARAM_CLIENT_NO_CONTEXT_TAKEOVER]) {
            $parts[] = self::PARAM_CLIENT_NO_CONTEXT_TAKEOVER;
        }

        if ($negotiatedParams[self::PARAM_SERVER_MAX_WINDOW_BITS] !== 15) {
            $parts[] = self::PARAM_SERVER_MAX_WINDOW_BITS . '=' . $negotiatedParams[self::PARAM_SERVER_MAX_WINDOW_BITS];
        }

        if ($negotiatedParams[self::PARAM_CLIENT_MAX_WINDOW_BITS] !== 15) {
            $parts[] = self::PARAM_CLIENT_MAX_WINDOW_BITS . '=' . $negotiatedParams[self::PARAM_CLIENT_MAX_WINDOW_BITS];
        }

        return implode('; ', $parts);
    }

    /**
     * Check if compression should be applied based on message characteristics
     */
    public function shouldCompress(string $payload, array $context = []): bool
    {
        // Don't compress small messages
        if (strlen($payload) < $this->config['min_compression_size']) {
            return false;
        }

        // Use compression engine's smart algorithm selection
        if ($this->config['enable_adaptive_compression']) {
            $analysis = $this->compressionEngine->analyzeContent($payload);
            return $analysis['compression_benefit'] > $this->config['compression_threshold'];
        }

        return true;
    }

    /**
     * Get compression statistics
     */
    public function getStats(): array
    {
        return [
            'negotiated_params' => $this->negotiatedParams,
            'config' => $this->config,
            'compression_engine_stats' => $this->compressionEngine->getStats()
        ];
    }
}