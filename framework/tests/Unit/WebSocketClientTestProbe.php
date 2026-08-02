<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Exception\InvalidStateException;
use Hilos\Core\Exception\UnsupportedOperationException;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Socket\Client\WebSocketClient;
use Hilos\Socket\SocketException;
use Hilos\Socket\WebSocket\Exception\HandshakeFailedException;
use Hilos\Socket\WebSocket\Exception\InvalidFrameException;
use Hilos\Socket\WebSocket\Exception\InvalidFrameSequenceException;
use Hilos\Socket\WebSocket\Exception\ReservedOpcodeException;
use Hilos\Socket\WebSocket\Exception\UnknownOpcodeException;
use Hilos\Socket\WebSocket\Exception\UnsupportedProtocolVersionException;
use ReflectionClass;
use ReflectionException;

/**
 * WebSocketClientTestProbe - socketless WebSocketClient for unit tests.
 *
 * Skips the socket and env wiring of the real client so tests can drive the
 * handshake and the shared AbstractClient header parsing with raw wire bytes.
 */
final class WebSocketClientTestProbe extends WebSocketClient
{
    /** @var array<string, string> Headers captured from onHandshake() */
    public array $capturedHeaders = [];

    /** @var array<string, string> Cookies captured from onHandshake() */
    public array $capturedCookies = [];

    /** @var string Accept key captured from onHandshake() */
    public string $capturedAcceptKey = '';

    /**
     * Build a probe without invoking the socket/env constructor chain.
     *
     * The real client constructor requires a live socket and Hilos::$env;
     * header parsing and handshake tests need neither, so the probe is
     * instantiated without running any constructor.
     *
     * @return self Socketless probe instance
     * @throws InvalidStateException When reflection cannot instantiate the probe
     */
    public static function createSocketless(): self
    {
        try {
            return new ReflectionClass(self::class)->newInstanceWithoutConstructor();
        } catch (ReflectionException $exception) {
            throw new InvalidStateException('Cannot instantiate the socketless probe', 0, $exception);
        }
    }

    /**
     * Append raw wire bytes and run read-buffer processing once.
     *
     * @param string $bytes Raw bytes as received from the socket
     * @throws HandshakeFailedException If WebSocket handshake fails
     * @throws InvalidFrameSequenceException If frame sequence is invalid
     * @throws ReservedOpcodeException If reserved opcode is received
     * @throws SocketException If socket read fails
     * @throws UnknownOpcodeException If unknown opcode is received
     * @throws UnsupportedProtocolVersionException If WebSocket version is unsupported
     * @throws InvalidFrameException If frame payload is invalid
     * @throws AgentUnknownActionException When action name is not allowed
     * @throws UnsupportedOperationException When an internal signal branch is unreachable
     */
    public function feed(string $bytes): void
    {
        $this->readBuffer .= $bytes;
        $this->processReadBuffer();
    }

    /**
     * @return string Bytes queued for the client so far (e.g. the 101 response)
     */
    public function outboundBytes(): string
    {
        return $this->writeBuffer;
    }

    /**
     * @return bool True when the WebSocket handshake completed
     */
    public function handshakeDone(): bool
    {
        return $this->handshakeCompleted;
    }

    /**
     * Expose the protected AbstractClient::parseHeaders() for direct testing.
     *
     * @param list<string> $lines Request lines from explode of raw request
     * @return array<string, string> Lowercase header name to value map
     */
    public function exposeParseHeaders(array $lines): array
    {
        return $this->parseHeaders($lines);
    }

    /**
     * Expose the protected AbstractClient::parseCookies() for direct testing.
     *
     * @param array<string, string> $headers HTTP headers map
     * @return array<string, string> Cookie name => value pairs
     */
    public function exposeParseCookies(array $headers): array
    {
        return $this->parseCookies($headers);
    }

    /**
     * Fixed IP for tests; no socket access.
     *
     * @return string Loopback client IP
     */
    protected function getClientIp(): string
    {
        return '127.0.0.1';
    }

    /**
     * Captures handshake headers, cookies, and the accept key for assertions.
     *
     * @param array<string, string> $headers HTTP headers from handshake request (lowercase header names)
     * @param string $acceptKey Daemon-minted connection identifier (not the RFC Sec-WebSocket-Accept value)
     * @param array<string, string> $cookies Parsed cookies from Cookie header
     * @param string $clientIp Client IP (IPv4 or IPv6, empty if unavailable)
     * @param RequestQueryParams $queryParams Query parameters from request URL
     */
    protected function onHandshake(
        array $headers,
        string $acceptKey,
        array $cookies,
        string $clientIp,
        RequestQueryParams $queryParams,
    ): void {
        $this->capturedHeaders = $headers;
        $this->capturedCookies = $cookies;
        $this->capturedAcceptKey = $acceptKey;
    }
}
