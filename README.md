# HighPer WebSockets

[![PHP Version](https://img.shields.io/badge/PHP-8.3%2B-blue.svg)](https://php.net)
[![Performance](https://img.shields.io/badge/Performance-Indexed-orange.svg)](https://github.com/highperapp/websockets)
[![WebSocket](https://img.shields.io/badge/WebSocket-RFC6455-green.svg)](https://tools.ietf.org/html/rfc6455)
[![Tests](https://img.shields.io/badge/Tests-100%25-success.svg)](https://github.com/highperapp/websockets)

**High-performance WebSocket server with O(1) indexed broadcasting and zero-downtime connection preservation. Works standalone or with HighPer Framework.**

> 🔄 **Standalone Library**: Works independently in any PHP application - no framework required!

## 🚀 **Features**

### ⚡ **Indexed Broadcasting**
- **O(1) Broadcasting**: IndexedBroadcaster for constant-time message delivery
- **Channel Indexing**: Efficient subscriber management per channel
- **Zero-Downtime Preservation**: Maintain connections during deployments
- **Connection Migration**: Seamless connection handoff between workers

### 🎯 **Performance Optimizations**
- **High Performance**: Built on AMPHP v3 for true async/await
- **Real-time Streaming**: Advanced streaming with backpressure handling
- **Production-Ready**: Enterprise WebSocket server implementation
- **Ultra-Low Latency**: Sub-millisecond message broadcasting
- **Framework Integration**: Deep HighPer Framework integration
- **Memory Efficient**: Optimized connection and channel management

## Installation

```bash
composer require highperapp/websockets
```

## Quick Start

```php
<?php
require 'vendor/autoload.php';

use HighPerApp\HighPer\WebSocket\WebSocketHandler;
use HighPerApp\HighPer\WebSocket\StreamingWebSocketHandler;

// Create WebSocket handler
$handler = new StreamingWebSocketHandler([
    'enable_streaming' => true,
    'backpressure_limit' => 1000
]);

// Start WebSocket server
$server = new WebSocketServer($handler);
$server->start('0.0.0.0', 8080);
```

## Requirements

- PHP 8.2+
- AMPHP v3+
- ext-json

## License

MIT