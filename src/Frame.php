<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\WebSockets;

class Frame
{
    public const OPCODE_CONTINUATION = 0x0;
    public const OPCODE_TEXT = 0x1;
    public const OPCODE_BINARY = 0x2;
    public const OPCODE_CLOSE = 0x8;
    public const OPCODE_PING = 0x9;
    public const OPCODE_PONG = 0xA;

    private int $opcode;

    private string $payload;

    private bool $fin;

    private bool $masked;

    /** @var int[] */
    private array $mask;

    public function __construct(int $opcode, string $payload, bool $fin = true, bool $masked = false)
    {
        $this->opcode = $opcode;
        $this->payload = $payload;
        $this->fin = $fin;
        $this->masked = $masked;
        $this->mask = $masked ? [mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)] : [];
    }

    public function getOpcode(): int
    {
        return $this->opcode;
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function getType(): string
    {
        return match ($this->opcode) {
            self::OPCODE_TEXT => 'text',
            self::OPCODE_BINARY => 'binary',
            self::OPCODE_CLOSE => 'close',
            self::OPCODE_PING => 'ping',
            self::OPCODE_PONG => 'pong',
            self::OPCODE_CONTINUATION => 'continuation',
            default => 'unknown'
        };
    }

    public function isFin(): bool
    {
        return $this->fin;
    }

    public function isMasked(): bool
    {
        return $this->masked;
    }

    /** @return int[] */
    public function getMask(): array
    {
        return $this->mask;
    }

    public function getLength(): int
    {
        return strlen($this->payload);
    }

    public function toBinary(): string
    {
        $frame = '';

        // First byte: FIN (1 bit) + RSV (3 bits) + Opcode (4 bits)
        $firstByte = ($this->fin ? 0x80 : 0x00) | ($this->opcode & 0x0F);
        $frame .= chr($firstByte);

        // Second byte: MASK (1 bit) + Payload length (7 bits)
        $payloadLength = strlen($this->payload);
        $maskBit = $this->masked ? 0x80 : 0x00;

        if ($payloadLength < 126) {
            $frame .= chr($maskBit | $payloadLength);
        } elseif ($payloadLength < 65536) {
            $frame .= chr($maskBit | 126);
            $frame .= pack('n', $payloadLength);
        } else {
            $frame .= chr($maskBit | 127);
            $frame .= pack('J', $payloadLength);
        }

        // Masking key (if masked)
        if ($this->masked) {
            foreach ($this->mask as $byte) {
                $frame .= chr($byte);
            }
        }

        // Payload data (masked if necessary)
        if ($this->masked) {
            $maskedPayload = '';
            for ($i = 0; $i < strlen($this->payload); $i++) {
                $maskedPayload .= chr(ord($this->payload[$i]) ^ $this->mask[$i % 4]);
            }
            $frame .= $maskedPayload;
        } else {
            $frame .= $this->payload;
        }

        return $frame;
    }

    public static function fromBinary(string $data): self
    {
        if (strlen($data) < 2) {
            throw new \InvalidArgumentException('Invalid frame data');
        }

        $firstByte = ord($data[0]);
        $secondByte = ord($data[1]);

        $fin = (bool) ($firstByte & 0x80);
        $opcode = $firstByte & 0x0F;
        $masked = (bool) ($secondByte & 0x80);
        $payloadLength = $secondByte & 0x7F;

        $offset = 2;

        // Extended payload length
        if ($payloadLength === 126) {
            $unpackResult = unpack('n', substr($data, $offset, 2));
            $payloadLength = $unpackResult !== false ? $unpackResult[1] : 0;
            $offset += 2;
        } elseif ($payloadLength === 127) {
            $unpackResult = unpack('J', substr($data, $offset, 8));
            $payloadLength = $unpackResult !== false ? $unpackResult[1] : 0;
            $offset += 8;
        }

        // Masking key
        $mask = [];
        if ($masked) {
            for ($i = 0; $i < 4; $i++) {
                $mask[] = ord($data[$offset + $i]);
            }
            $offset += 4;
        }

        // Payload
        $payload = substr($data, $offset, $payloadLength);

        // Unmask payload if necessary
        if ($masked) {
            $unmaskedPayload = '';
            for ($i = 0; $i < strlen($payload); $i++) {
                $unmaskedPayload .= chr(ord($payload[$i]) ^ $mask[$i % 4]);
            }
            $payload = $unmaskedPayload;
        }

        $frame = new self($opcode, $payload, $fin, $masked);
        if ($masked) {
            $frame->mask = $mask;
        }

        return $frame;
    }

    public function isControlFrame(): bool
    {
        return ($this->opcode & 0x08) !== 0;
    }

    public function isDataFrame(): bool
    {
        return !$this->isControlFrame();
    }
}
