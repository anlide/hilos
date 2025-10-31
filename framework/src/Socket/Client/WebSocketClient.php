<?php

declare(strict_types=1);

namespace Hilos\Socket\Client;

use Hilos\Socket\Client\Interface\WebSocketClientInterface;

/**
 * WebSocketClient - Represents a single WebSocket connection
 *
 * Handles WebSocket protocol frame parsing and writing.
 * Created by WebSocketServer when accepting new connections.
 */
abstract class WebSocketClient extends AbstractClient implements WebSocketClientInterface
{
    /** @var bool Whether WebSocket handshake is completed */
    protected bool $handshakeCompleted = false;

    /**
     * Process read buffer - parse WebSocket frames
     */
    protected function processReadBuffer(): void
    {
        // Handle WebSocket handshake first
        if (!$this->handshakeCompleted) {
            $this->handleHandshake();
            return;
        }

        // Parse WebSocket frames
        while (strlen($this->readBuffer) >= 2) {
            $frame = $this->parseFrame();
            if ($frame === null) {
                break; // Incomplete frame, wait for more data
            }

            if ($frame['opcode'] === 0x8) { // Close frame
                $this->shouldClose = true;
                return;
            }

            if ($frame['opcode'] === 0x9) { // Ping frame
                $this->sendPong($frame['payload']);
                continue;
            }

            if ($frame['opcode'] === 0xA) { // Pong frame
                // Handle pong if needed
                continue;
            }

            // Process data frame (text or binary)
            if ($frame['opcode'] === 0x1 || $frame['opcode'] === 0x2) {
                $this->onFrame($frame['payload'], $frame['opcode'] === 0x1);
            }
        }
    }

    /**
     * Handle WebSocket handshake
     */
    private function handleHandshake(): void
    {
        // Check if we have complete HTTP request
        if (!str_contains($this->readBuffer, "\r\n\r\n")) {
            return; // Incomplete request
        }
        
        $request = substr($this->readBuffer, 0, strpos($this->readBuffer, "\r\n\r\n") + 4);
        $this->readBuffer = substr($this->readBuffer, strlen($request));

        // Parse headers
        $headers = $this->parseHeaders($request);
        
        // Check if it's a WebSocket upgrade request
        if (!isset($headers['Upgrade']) || strtolower($headers['Upgrade']) !== 'websocket') {
            $this->shouldClose = true;
            return;
        }

        // Generate WebSocket-Accept
        $key = $headers['Sec-WebSocket-Key'] ?? '';
        if (empty($key)) {
            $this->shouldClose = true;
            return;
        }
        
        $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        // Send handshake response
        $response = "HTTP/1.1 101 Switching Protocols\r\n";
        $response .= "Upgrade: websocket\r\n";
        $response .= "Connection: Upgrade\r\n";
        $response .= "Sec-WebSocket-Accept: $accept\r\n";
        $response .= "\r\n";

        $this->writeBuffer .= $response;
        $this->handshakeCompleted = true;
    }

    /**
     * Parse HTTP headers from request
     *
     * @param string $request HTTP request
     * @return array Headers as key-value pairs
     */
    private function parseHeaders(string $request): array
    {
        $headers = [];
        $lines = explode("\r\n", $request);
        
        // Skip request line
        array_shift($lines);

        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }

            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $headers;
    }

    /**
     * Parse WebSocket frame
     *
     * @return ?array Frame data or null if incomplete
     */
    private function parseFrame(): ?array
    {
        if (strlen($this->readBuffer) < 2) {
            return null;
        }

        $byte1 = ord($this->readBuffer[0]);
        $byte2 = ord($this->readBuffer[1]);

        $fin = ($byte1 >> 7) & 0x1;
        $opcode = $byte1 & 0xF;
        $masked = ($byte2 >> 7) & 0x1;
        $payloadLen = $byte2 & 0x7F;

        // Extended payload length
        $headerLen = 2;
        if ($payloadLen === 126) {
            if (strlen($this->readBuffer) < 4) {
                return null;
            }
            $payloadLen = unpack('n', substr($this->readBuffer, 2, 2))[1];
            $headerLen = 4;
        } elseif ($payloadLen === 127) {
            if (strlen($this->readBuffer) < 10) {
                return null;
            }
            $payloadLen = unpack('J', substr($this->readBuffer, 2, 8))[1];
            $headerLen = 10;
        }

        // Masking key (4 bytes)
        $maskLen = $masked ? 4 : 0;
        if ($masked && strlen($this->readBuffer) < $headerLen + $maskLen) {
            return null;
        }

        $maskKey = $masked ? substr($this->readBuffer, $headerLen, 4) : '';

        // Payload
        $payloadStart = $headerLen + $maskLen;
        if (strlen($this->readBuffer) < $payloadStart + $payloadLen) {
            return null; // Incomplete frame
        }

        $payload = substr($this->readBuffer, $payloadStart, $payloadLen);

        // Remove frame from buffer
        $this->readBuffer = substr($this->readBuffer, $payloadStart + $payloadLen);

        // Unmask payload if needed
        if ($masked) {
            $unmasked = '';
            for ($i = 0; $i < strlen($payload); $i++) {
                $unmasked .= $payload[$i] ^ $maskKey[$i % 4];
            }
            $payload = $unmasked;
        }

        return [
            'fin' => $fin,
            'opcode' => $opcode,
            'masked' => $masked,
            'payload' => $payload,
        ];
    }

    /**
     * Send WebSocket frame
     *
     * @param string $data Data to send
     * @param bool $text Whether to send as text (true) or binary (false)
     */
    public function sendFrame(string $data, bool $text = true): void
    {
        $length = strlen($data);
        $header = '';

        // FIN + opcode (text = 0x1, binary = 0x2)
        $header .= chr(0x80 | ($text ? 0x1 : 0x2));

        // Payload length
        if ($length < 126) {
            $header .= chr($length);
        } elseif ($length < 65536) {
            $header .= chr(126);
            $header .= pack('n', $length);
        } else {
            $header .= chr(127);
            $header .= pack('J', $length);
        }

        $this->writeBuffer .= $header . $data;
    }

    /**
     * Send ping frame
     *
     * @param string $data Payload data
     */
    private function sendPong(string $data): void
    {
        $length = strlen($data);
        $header = '';

        // FIN + Pong opcode (0xA)
        $header .= chr(0x80 | 0xA);

        // Payload length
        if ($length < 126) {
            $header .= chr($length);
        } elseif ($length < 65536) {
            $header .= chr(126);
            $header .= pack('n', $length);
        } else {
            $header .= chr(127);
            $header .= pack('J', $length);
        }

        $this->writeBuffer .= $header . $data;
    }

    /**
     * Handle received WebSocket frame
     *
     * Must be implemented by child classes to process incoming frames.
     *
     * @param string $payload Frame payload
     * @param bool $isText Whether payload is text (true) or binary (false)
     */
    abstract protected function onFrame(string $payload, bool $isText): void;
}

