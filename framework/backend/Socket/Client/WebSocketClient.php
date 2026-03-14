<?php

declare(strict_types=1);

namespace Hilos\Socket\Client;

use Hilos\Constants\HttpConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Socket\Client\Interface\WebSocketClientInterface;
use Hilos\Socket\SocketException;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupUpdateSubscriptionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Socket\WebSocket\Exception\HandshakeFailedException;
use Hilos\Socket\WebSocket\Exception\InvalidFrameException;
use Hilos\Socket\WebSocket\Exception\InvalidFrameSequenceException;
use Hilos\Socket\WebSocket\Exception\ReservedOpcodeException;
use Hilos\Socket\WebSocket\Exception\UnknownOpcodeException;
use Hilos\Socket\WebSocket\Exception\UnsupportedProtocolVersionException;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\WebSocketFrameDTO;
use Hilos\Utils\Helpers\JsonHelper;
use Hilos\Core\Exception\InvalidStateException;
use Hilos\Core\Exception\UnsupportedOperationException;

/**
 * WebSocketClient - Represents a single WebSocket connection.
 *
 * Handles WebSocket protocol frame parsing and writing.
 * Created by WebSocketServer when accepting new connections.
 */
abstract class WebSocketClient extends AbstractClient implements WebSocketClientInterface
{

    /** @var string Accept key identifier (from handshake) */
    public string $acceptKey {
        get {
            return $this->acceptKeyValue;
        }
        set {
            $this->acceptKeyValue = $value;
        }
    }

    /** @var string Backing storage for accept key */
    private string $acceptKeyValue = '';

    /** WebSocket protocol magic string for handshake */
    private const string WS_MAGIC_STRING = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** WebSocket frame opcodes */
    private const int OPCODE_CONTINUATION = 0x0;     // Continuation frame
    private const int OPCODE_TEXT = 0x1;             // Text frame
    private const int OPCODE_BINARY = 0x2;           // Binary frame
    private const int OPCODE_CLOSE = 0x8;            // Close frame
    private const int OPCODE_PING = 0x9;             // Ping frame
    private const int OPCODE_PONG = 0xA;             // Pong frame

    /** Payload length constants for WebSocket frames */
    private const int PAYLOAD_LEN_16BIT = 126;       // Use 16-bit length field
    private const int PAYLOAD_LEN_64BIT = 127;       // Use 64-bit length field
    private const int PAYLOAD_LEN_16BIT_MAX = 65535; // Maximum value for 16-bit length
    private const int PAYLOAD_LEN_MASK = 0x7F;       // Mask to extract payload length (7 bits)

    /** Frame header parsing bit masks */
    private const int FIN_MASK = 0x80;               // FIN bit mask (bit 7)
    private const int OPCODE_MASK = 0x0F;            // Opcode mask (bits 0-3)
    private const int MASKED_MASK = 0x80;            // Masked bit mask (bit 7 of second byte)
    private const int MASK_KEY_LENGTH = 4;           // Masking key length in bytes

    /** Frame header length constants */
    private const int HEADER_LEN_BASE = 2;           // Base header length (2 bytes)
    private const int HEADER_LEN_16BIT = 4;          // Header length with 16-bit payload length
    private const int HEADER_LEN_64BIT = 10;         // Header length with 64-bit payload length

    /** Pack/unpack format strings for binary encoding */
    private const string PACK_FORMAT_16BIT = 'n';    // Unsigned short (16-bit, big-endian)
    private const string PACK_FORMAT_64BIT = 'J';    // Unsigned long long (64-bit, machine byte order)

    /** @var bool Whether WebSocket handshake is completed */
    protected bool $handshakeCompleted = false;

    /** @var bool Whether we are currently receiving a fragmented message */
    private bool $isReceivingFragmented = false;

    /** @var int Original opcode for fragmented message (TEXT or BINARY) */
    private int $fragmentedOpcode = 0;

    /** @var string Accumulated payload for fragmented message */
    private string $fragmentedPayload = '';


    /**
     * Process read buffer - parse WebSocket frames
     *
     * @throws HandshakeFailedException If WebSocket handshake fails
     * @throws InvalidFrameSequenceException If frame sequence is invalid
     * @throws ReservedOpcodeException If reserved opcode is received
     * @throws SocketException If socket read fails
     * @throws UnknownOpcodeException If unknown opcode is received
     * @throws UnsupportedProtocolVersionException If WebSocket version is unsupported
     * @throws InvalidFrameException If frame payload is invalid
     */
    protected function processReadBuffer(): void
    {
        // Handle WebSocket handshake first
        if (!$this->handshakeCompleted) {
            $this->handleHandshake();
            return;
        }

        // Parse WebSocket frames
        while (strlen($this->readBuffer) >= self::HEADER_LEN_BASE) {
            $frame = $this->parseFrame();
            if ($frame === null) {
                break; // Incomplete frame, wait for more data
            }

            try {
                $this->handleFrame($frame);
            } catch (UnknownOpcodeException|ReservedOpcodeException|InvalidFrameException $exception) {
                // Log and close connection on unknown/reserved opcode
                $this->shouldClose = true;
                throw $exception;
            }
        }
    }

    /**
     * Handle WebSocket frame based on opcode
     *
     * @param WebSocketFrameDTO $frame Parsed frame data
     * @throws UnknownOpcodeException When opcode is unknown
     * @throws ReservedOpcodeException When opcode is reserved
     * @throws InvalidFrameSequenceException When frame sequence is invalid
     * @throws InvalidFrameException When frame payload is invalid
     */
    private function handleFrame(WebSocketFrameDTO $frame): void
    {
        $opcode = $frame->opcode;

        switch ($opcode) {
            case self::OPCODE_CONTINUATION:
                // Continuation frame - part of fragmented message
                if (!$this->isReceivingFragmented) {
                    throw new InvalidFrameSequenceException("Continuation frame received without initial fragmented frame");
                }

                $this->fragmentedPayload .= $frame->payload;

                if ($frame->fin) {
                    // Last frame of fragmented message
                    $this->isReceivingFragmented = false;
                    $opcode = $this->fragmentedOpcode;
                    $this->fragmentedOpcode = 0;
                    $payload = $this->fragmentedPayload;
                    $this->fragmentedPayload = '';

                    // Process complete fragmented message
                    if ($opcode === self::OPCODE_TEXT) {
                        $this->onFrame($payload);
                    } elseif ($opcode === self::OPCODE_BINARY) {
                        $this->onFrameBinary($payload);
                    }
                }
                break;

            case self::OPCODE_TEXT:
                // Text frame
                if (!$frame->fin) {
                    // Start of fragmented message
                    $this->isReceivingFragmented = true;
                    $this->fragmentedOpcode = self::OPCODE_TEXT;
                    $this->fragmentedPayload = $frame->payload;
                } else {
                    // Check if text is "ping" - respond with pong
                    if ($frame->payload === 'ping') {
                        $this->sendPong('');
                    } else {
                        // Complete text frame
                        $this->onFrame($frame->payload);
                    }
                }
                break;

            case self::OPCODE_BINARY:
                // Binary frame
                if (!$frame->fin) {
                    // Start of fragmented message
                    $this->isReceivingFragmented = true;
                    $this->fragmentedOpcode = self::OPCODE_BINARY;
                    $this->fragmentedPayload = $frame->payload;
                } else {
                    // Regular binary frame
                    $this->onFrameBinary($frame->payload);
                }
                break;

            case self::OPCODE_CLOSE:
                // Close frame
                $this->shouldClose = true;
                break;

            case self::OPCODE_PING:
                // Ping frame - respond with Pong
                $this->sendPong($frame->payload);
                break;

            case self::OPCODE_PONG:
                // Pong frame - acknowledgment of ping
                // Handle pong if needed (e.g., update last ping time)
                break;

            case 0x3:
            case 0x4:
            case 0x5:
            case 0x6:
            case 0x7:
            case 0xB:
            case 0xC:
            case 0xD:
            case 0xE:
            case 0xF:
                // Reserved opcodes - throw exception
                throw new ReservedOpcodeException($opcode);

            default:
                // Unknown opcode - should not happen
                throw new UnknownOpcodeException($opcode);
        }
    }

    /**
     * Handle WebSocket handshake (validate upgrade, send 101 response).
     *
     * @throws HandshakeFailedException When handshake validation fails
     * @throws SocketException When socket error occurs
     * @throws UnsupportedProtocolVersionException When protocol version is not 13
     */
    private function handleHandshake(): void
    {
        // Check if we have complete HTTP request
        if (!str_contains($this->readBuffer, HttpConstants::HTTP_DELIMITER)) {
            return; // Incomplete request
        }

        $delimiterPos = strpos($this->readBuffer, HttpConstants::HTTP_DELIMITER);
        $delimiterLen = strlen(HttpConstants::HTTP_DELIMITER);
        $request = substr($this->readBuffer, 0, $delimiterPos + $delimiterLen);
        $this->readBuffer = substr($this->readBuffer, strlen($request));

        // Parse request line to extract path and query parameters
        $requestLine = $this->parseRequestLine($request);
        $queryParams = $this->parseQueryParams($requestLine['path'] ?? '');

        // Parse headers
        $headers = $this->parseHeaders($request);

        // Check if it's a WebSocket upgrade request
        if (!isset($headers[HttpConstants::HEADER_UPGRADE]) ||
            strtolower($headers[HttpConstants::HEADER_UPGRADE]) !== HttpConstants::WEBSOCKET_PROTOCOL) {
            throw new HandshakeFailedException("Missing or invalid Upgrade header");
        }

        // Check WebSocket protocol version (RFC 6455 requires version 13)
        $version = $headers[HttpConstants::HEADER_SEC_WEBSOCKET_VERSION] ?? '';
        if ($version !== '13') {
            throw new UnsupportedProtocolVersionException($version ?: 'not specified');
        }

        // Generate WebSocket-Accept header value
        // RFC 6455 Section 4.2.2: The server must concatenate the client's Sec-WebSocket-Key
        // with the magic string "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", compute SHA1 hash,
        // and encode it as base64 to get the Sec-WebSocket-Accept value.
        $key = $headers[HttpConstants::HEADER_SEC_WEBSOCKET_KEY] ?? '';
        if (empty($key)) {
            throw new HandshakeFailedException("Missing Sec-WebSocket-Key header");
        }

        // Concatenate key + magic string, compute SHA1 (binary output), encode as base64
        $acceptKey = base64_encode(sha1($key . self::WS_MAGIC_STRING, true));

        // Call onHandshake callback before completing handshake
        $this->handleHandshakeInternal(
            $headers,
            $acceptKey,
            $this->parseCookies($headers),
            $this->getClientIp(),
            $queryParams,
        );

        // Send handshake response
        $response = HttpConstants::HTTP_VERSION . " 101 Switching Protocols" . HttpConstants::HTTP_LINE_SEPARATOR;
        $response .= HttpConstants::HEADER_UPGRADE . ": " . HttpConstants::WEBSOCKET_PROTOCOL . HttpConstants::HTTP_LINE_SEPARATOR;
        $response .= HttpConstants::HEADER_CONNECTION . ": " . HttpConstants::HEADER_UPGRADE . HttpConstants::HTTP_LINE_SEPARATOR;
        $response .= HttpConstants::HEADER_SEC_WEBSOCKET_ACCEPT . ": " . $acceptKey . HttpConstants::HTTP_LINE_SEPARATOR;
        $response .= HttpConstants::HTTP_LINE_SEPARATOR;

        $this->writeBuffer .= $response;
        $this->handshakeCompleted = true;
    }

    /**
     * Parse request line (first line of HTTP request).
     *
     * @param string $request Raw HTTP request string
     * @return array<string, string> Parsed request line with keys: method, path, version
     */
    private function parseRequestLine(string $request): array
    {
        $lines = explode(HttpConstants::HTTP_LINE_SEPARATOR, $request);
        $firstLine = $lines[0] ?? '';

        // Parse: GET /path?query=params HTTP/1.1
        $parts = explode(' ', $firstLine);

        return [
            'method' => $parts[0] ?? 'GET',
            'path' => $parts[1] ?? '/',
            'version' => $parts[2] ?? 'HTTP/1.1',
        ];
    }

    /**
     * Parse query parameters from path.
     *
     * @param string $path Path with optional query string (e.g. /path?key=value)
     * @return array<string, mixed> Query parameters as key-value pairs
     */
    private function parseQueryParams(string $path): array
    {
        $queryParams = [];
        $queryPos = strpos($path, '?');

        if ($queryPos !== false) {
            $queryString = substr($path, $queryPos + 1);
            parse_str($queryString, $queryParams);
        }

        return $queryParams;
    }

    /**
     * Parse HTTP headers from request.
     *
     * @param string $request Raw HTTP request string
     * @return array<string, string> Headers as key-value pairs
     */
    private function parseHeaders(string $request): array
    {
        $headers = [];
        $lines = explode(HttpConstants::HTTP_LINE_SEPARATOR, $request);

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
     * @return ?WebSocketFrameDTO Frame data or null if frame is incomplete (waiting for more data)
     */
    private function parseFrame(): ?WebSocketFrameDTO
    {
        if (strlen($this->readBuffer) < self::HEADER_LEN_BASE) {
            return null;
        }

        $byte1 = ord($this->readBuffer[0]);
        $byte2 = ord($this->readBuffer[1]);

        $fin = ($byte1 & self::FIN_MASK) >> 7;
        $opcode = $byte1 & self::OPCODE_MASK;
        $masked = ($byte2 & self::MASKED_MASK) >> 7;
        $payloadLen = $byte2 & self::PAYLOAD_LEN_MASK;

        // Extended payload length
        $headerLen = self::HEADER_LEN_BASE;
        if ($payloadLen === self::PAYLOAD_LEN_16BIT) {
            if (strlen($this->readBuffer) < self::HEADER_LEN_16BIT) {
                return null;
            }
            $payloadLen = unpack(self::PACK_FORMAT_16BIT, substr($this->readBuffer, self::HEADER_LEN_BASE, 2))[1];
            $headerLen = self::HEADER_LEN_16BIT;
        } elseif ($payloadLen === self::PAYLOAD_LEN_64BIT) {
            if (strlen($this->readBuffer) < self::HEADER_LEN_64BIT) {
                return null;
            }
            $payloadLen = unpack(self::PACK_FORMAT_64BIT, substr($this->readBuffer, self::HEADER_LEN_BASE, 8))[1];
            $headerLen = self::HEADER_LEN_64BIT;
        }

        // Masking key (4 bytes)
        $maskLen = $masked ? self::MASK_KEY_LENGTH : 0;
        if ($masked && strlen($this->readBuffer) < $headerLen + $maskLen) {
            return null;
        }

        $maskKey = $masked ? substr($this->readBuffer, $headerLen, self::MASK_KEY_LENGTH) : '';

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
                $unmasked .= $payload[$i] ^ $maskKey[$i % self::MASK_KEY_LENGTH];
            }
            $payload = $unmasked;
        }

        return new WebSocketFrameDTO(
            fin: $fin,
            opcode: $opcode,
            masked: $masked,
            payload: $payload,
        );
    }

    /**
     * Send WebSocket text frame.
     *
     * @param string $data Text data to send (UTF-8)
     * @throws InvalidStateException When handshake not completed
     */
    public function sendFrame(string $data): void
    {
        // Check if handshake is completed
        if (!$this->handshakeCompleted) {
            throw new InvalidStateException("Cannot send frame: WebSocket handshake not completed");
        }

        $header = $this->buildFrameHeader(strlen($data), self::OPCODE_TEXT);
        $this->writeBuffer .= $header . $data;
    }

    /**
     * Send WebSocket binary frame.
     *
     * @param string $data Binary data to send
     */
    public function sendFrameBinary(string $data): void
    {
        $header = $this->buildFrameHeader(strlen($data), self::OPCODE_BINARY);
        $this->writeBuffer .= $header . $data;
    }

    /**
     * Send pong frame (response to ping).
     *
     * @param string $data Payload data
     */
    private function sendPong(string $data): void
    {
        $header = $this->buildFrameHeader(strlen($data), self::OPCODE_PONG);
        $this->writeBuffer .= $header . $data;
    }

    /**
     * Build WebSocket frame header.
     *
     * @param int $length Payload length
     * @param int $opcode Frame opcode
     * @return string Frame header bytes
     */
    private function buildFrameHeader(int $length, int $opcode): string
    {
        $header = '';

        // FIN (bit 7) + opcode (bits 0-3)
        $header .= chr(self::FIN_MASK | $opcode);

        // Payload length encoding
        if ($length < self::PAYLOAD_LEN_16BIT) {
            // 7-bit length (0-125)
            $header .= chr($length);
        } elseif ($length <= self::PAYLOAD_LEN_16BIT_MAX) {
            // 16-bit length (126 + 2 bytes)
            $header .= chr(self::PAYLOAD_LEN_16BIT);
            $header .= pack(self::PACK_FORMAT_16BIT, $length);
        } else {
            // 64-bit length (127 + 8 bytes)
            $header .= chr(self::PAYLOAD_LEN_64BIT);
            $header .= pack(self::PACK_FORMAT_64BIT, $length);
        }

        return $header;
    }

    /**
     * Handle received WebSocket text frame.
     *
     * @param string $payload Frame payload (UTF-8 text)
     * @throws InvalidFrameException When frame payload is invalid
     */
    protected function onFrame(string $payload): void
    {
        $acceptKey = $this->acceptKey;
        $decoded = JsonHelper::tryDecode($payload);
        if (!is_array($decoded)) {
            throw new InvalidFrameException('Invalid JSON payload');
        }

        $type = $decoded[SignalPayloadConstants::FIELD_TYPE] ?? null;
        if (!is_string($type)) {
            throw new InvalidFrameException('Message type is required');
        }

        switch ($type) {
            case SignalTypeConstants::ACTION: {
                $actionName = isset($decoded[SignalPayloadConstants::FIELD_ACTION])
                    && is_string($decoded[SignalPayloadConstants::FIELD_ACTION])
                    ? $decoded[SignalPayloadConstants::FIELD_ACTION]
                    : throw new InvalidFrameException("Action name is required for {$type} signal");
                if ($actionName === '') {
                    throw new InvalidFrameException("Action name is empty for {$type} signal");
                }

                $this->onActionValidated($actionName);

                $actionData = is_array($decoded[SignalPayloadConstants::FIELD_DATA] ?? null)
                    ? $decoded[SignalPayloadConstants::FIELD_DATA]
                    : [];

                $dto = new WebSocketActionSignalDTO(
                    acceptKey: $acceptKey,
                    action: $actionName,
                    data: $actionData,
                );

                Hilos::$sr->queueSignal(
                    new SignalSource(SignalSource::WEBSOCKET),
                    new SignalType(SignalTypeConstants::ACTION),
                    new SignalName($actionName),
                    $dto,
                );
                $this->onActionQueued($actionName, $dto);
                break;
            }

            case SignalTypeConstants::PAGE_SUBSCRIBE:
            case SignalTypeConstants::PAGE_UPDATE_SUBSCRIPTION: {
                $page = isset($decoded[SignalPayloadConstants::FIELD_PAGE])
                    && is_string($decoded[SignalPayloadConstants::FIELD_PAGE])
                    ? $decoded[SignalPayloadConstants::FIELD_PAGE]
                    : throw new InvalidFrameException("Page is required for {$type} signal");
                if ($page === '') {
                    throw new InvalidFrameException("Page is empty for {$type} signal");
                }

                $params = is_array($decoded[SignalPayloadConstants::FIELD_PARAMS] ?? null) ? $decoded[SignalPayloadConstants::FIELD_PARAMS] : [];
                $dto = match ($type) {
                    SignalTypeConstants::PAGE_SUBSCRIBE => new WebSocketPageSubscribeSignalDTO(
                        acceptKey: $acceptKey,
                        page: $page,
                        params: $params,
                    ),
                    SignalTypeConstants::PAGE_UPDATE_SUBSCRIPTION => new WebSocketPageUpdateSubscriptionSignalDTO(
                        acceptKey: $acceptKey,
                        page: $page,
                        params: $params,
                    ),
                    default => throw new UnsupportedOperationException("Unsupported page signal type: {$type}"),
                };

                Hilos::$sr->queueSignal(
                    new SignalSource(SignalSource::WEBSOCKET),
                    new SignalType($type),
                    new SignalName($page),
                    $dto,
                );

                match ($type) {
                    SignalTypeConstants::PAGE_SUBSCRIBE => $this->onPageSubscribeParsed($page, $dto),
                    SignalTypeConstants::PAGE_UPDATE_SUBSCRIPTION => $this->onPageUpdateSubscriptionParsed($page, $dto),
                    default => throw new UnsupportedOperationException("Unsupported page signal type: {$type}"),
                };
                break;
            }

            case SignalTypeConstants::PAGE_UNSUBSCRIBE: {
                $page = isset($decoded[SignalPayloadConstants::FIELD_PAGE])
                    && is_string($decoded[SignalPayloadConstants::FIELD_PAGE])
                    ? $decoded[SignalPayloadConstants::FIELD_PAGE]
                    : throw new InvalidFrameException("Page is required for {$type} signal");
                if ($page === '') {
                    throw new InvalidFrameException("Page is empty for {$type} signal");
                }

                $dto = new WebSocketPageUnsubscribeSignalDTO(
                    acceptKey: $acceptKey,
                );

                Hilos::$sr->queueSignal(
                    new SignalSource(SignalSource::WEBSOCKET),
                    new SignalType(SignalTypeConstants::PAGE_UNSUBSCRIBE),
                    new SignalName($page),
                    $dto,
                );

                $this->onPageUnsubscribeParsed($page, $dto);
                break;
            }

            case SignalTypeConstants::GROUP_SUBSCRIBE:
            case SignalTypeConstants::GROUP_UPDATE_SUBSCRIPTION:
            case SignalTypeConstants::GROUP_UNSUBSCRIBE: {
                $group = isset($decoded[SignalPayloadConstants::FIELD_GROUP])
                    && is_string($decoded[SignalPayloadConstants::FIELD_GROUP])
                    ? $decoded[SignalPayloadConstants::FIELD_GROUP]
                    : throw new InvalidFrameException("Group is required for {$type} signal");
                if ($group === '') {
                    throw new InvalidFrameException("Group is empty for {$type} signal");
                }

                $params = is_array($decoded[SignalPayloadConstants::FIELD_PARAMS] ?? null) ? $decoded[SignalPayloadConstants::FIELD_PARAMS] : [];
                $dto = match ($type) {
                    SignalTypeConstants::GROUP_SUBSCRIBE => new WebSocketGroupSubscribeSignalDTO(
                        acceptKey: $acceptKey,
                        group: $group,
                        params: $params,
                    ),
                    SignalTypeConstants::GROUP_UPDATE_SUBSCRIPTION => new WebSocketGroupUpdateSubscriptionSignalDTO(
                        acceptKey: $acceptKey,
                        group: $group,
                        params: $params,
                    ),
                    SignalTypeConstants::GROUP_UNSUBSCRIBE => new WebSocketGroupUnsubscribeSignalDTO(
                        acceptKey: $acceptKey,
                        group: $group,
                    ),
                    default => throw new UnsupportedOperationException("Unsupported group signal type: {$type}"),
                };

                Hilos::$sr->queueSignal(
                    new SignalSource(SignalSource::WEBSOCKET),
                    new SignalType($type),
                    new SignalName($group),
                    $dto,
                );

                match ($type) {
                    SignalTypeConstants::GROUP_SUBSCRIBE => $this->onGroupSubscribeParsed($group, $dto),
                    SignalTypeConstants::GROUP_UPDATE_SUBSCRIPTION => $this->onGroupUpdateSubscriptionParsed($group, $dto),
                    SignalTypeConstants::GROUP_UNSUBSCRIBE => $this->onGroupUnsubscribeParsed($group, $dto),
                    default => throw new UnsupportedOperationException("Unsupported group signal type: {$type}"),
                };
                break;
            }

            default:
                $this->onUnknownFrame($type, $payload);
        }
    }

    /**
     * Hook: called after page subscribe payload is parsed and queued.
     *
     * @param string $page Page identifier
     * @param WebSocketPageSubscribeSignalDTO $dto Subscribe signal (acceptKey, params)
     */
    protected function onPageSubscribeParsed(string $page, WebSocketPageSubscribeSignalDTO $dto): void
    {
        // Default: no-op
    }

    /**
     * Hook: called after page update subscription payload is parsed and queued.
     *
     * @param string $page Page identifier
     * @param WebSocketPageUpdateSubscriptionSignalDTO $dto Update subscription signal (acceptKey, params)
     */
    protected function onPageUpdateSubscriptionParsed(string $page, WebSocketPageUpdateSubscriptionSignalDTO $dto): void
    {
        // Default: no-op
    }

    /**
     * Hook: called after page unsubscribe payload is parsed and queued.
     *
     * @param string $page Page identifier
     * @param WebSocketPageUnsubscribeSignalDTO $dto Unsubscribe signal (acceptKey)
     */
    protected function onPageUnsubscribeParsed(string $page, WebSocketPageUnsubscribeSignalDTO $dto): void
    {
        // Default: no-op
    }

    /**
     * Hook: called after group subscribe payload is parsed and queued.
     *
     * @param string $group Group identifier
     * @param WebSocketGroupSubscribeSignalDTO $dto Subscribe signal (acceptKey, params)
     */
    protected function onGroupSubscribeParsed(string $group, WebSocketGroupSubscribeSignalDTO $dto): void
    {
        // Default: no-op
    }

    /**
     * Hook: called after group update subscription payload is parsed and queued.
     *
     * @param string $group Group identifier
     * @param WebSocketGroupUpdateSubscriptionSignalDTO $dto Update subscription signal (acceptKey, params)
     */
    protected function onGroupUpdateSubscriptionParsed(string $group, WebSocketGroupUpdateSubscriptionSignalDTO $dto): void
    {
        // Default: no-op
    }

    /**
     * Hook: called after group unsubscribe payload is parsed and queued.
     *
     * @param string $group Group identifier
     * @param WebSocketGroupUnsubscribeSignalDTO $dto Unsubscribe signal (acceptKey)
     */
    protected function onGroupUnsubscribeParsed(string $group, WebSocketGroupUnsubscribeSignalDTO $dto): void
    {
        // Default: no-op
    }

    /**
     * Hook: validate action name from parsed payload.
     *
     * @param string $actionName Action name (e.g. message, rename)
     */
    protected function onActionValidated(string $actionName): void
    {
        throw new UnsupportedOperationException("Unknown websocket action type: {$actionName}");
    }

    /**
     * Hook: called after action payload is parsed and queued.
     *
     * @param string $actionName Action name (e.g. message, rename)
     * @param WebSocketActionSignalDTO $dto Action signal payload (acceptKey, action, data)
     */
    protected function onActionQueued(string $actionName, WebSocketActionSignalDTO $dto): void
    {
        // Default: no-op
    }

    /**
     * Hook: called when message type is unknown.
     *
     * @param string $type Frame type (opcode)
     * @param string $payload Raw frame payload
     * @throws InvalidFrameException When frame type is unknown
     */
    protected function onUnknownFrame(string $type, string $payload): void
    {
        throw new InvalidFrameException("Unknown message type: {$type}");
    }

    /**
     * Handle received WebSocket binary frame.
     *
     * @param string $payload Frame payload (binary data)
     */
    protected function onFrameBinary(string $payload): void
    {
        $dto = new WebSocketFrameBinarySignalDTO(
            acceptKey: $this->acceptKey,
            payload: $payload,
        );

        Hilos::$sr->queueSignal(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::FRAME_BINARY),
            new SignalName(SignalName::EMPTY),
            $dto,
        );
        $this->onFrameBinaryQueued($dto);
    }

    /**
     * Hook: called after binary frame payload is queued.
     *
     * @param WebSocketFrameBinarySignalDTO $dto Binary frame signal (acceptKey, payload)
     */
    protected function onFrameBinaryQueued(WebSocketFrameBinarySignalDTO $dto): void
    {
        // Default: no-op
    }

    /**
     * Handle handshake with framework hook dispatch.
     *
     * This method is final to ensure framework-level handshake logic is always executed.
     * Child classes should override onHandshake() for custom behavior.
     *
     * @param array<string, string> $headers HTTP headers from handshake request
     * @param string $acceptKey Sec-WebSocket-Accept value (connection identifier)
     * @param array<string, string> $cookies Parsed cookies from Cookie header
     * @param string $clientIp Client IP (IPv4 or IPv6, empty if unavailable)
     * @param array<string, string|array> $queryParams Query parameters from request URL
     */
    final protected function handleHandshakeInternal(
        array $headers,
        string $acceptKey,
        array $cookies,
        string $clientIp,
        array $queryParams,
    ): void {
        $this->acceptKey = $acceptKey;
        $this->onHandshake($headers, $acceptKey, $cookies, $clientIp, $queryParams);

        $dto = new WebSocketHandshakeSignalDTO(
            headers: $headers,
            acceptKey: $acceptKey,
            cookies: $cookies,
            clientIp: $clientIp,
            queryParams: $queryParams,
        );

        Hilos::$sr->queueSignal(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::HANDSHAKE),
            new SignalName(SignalTypeConstants::HANDSHAKE),
            $dto,
        );
    }

    /**
     * Called when WebSocket handshake is completed.
     *
     * Called after successful handshake validation but before sending the response.
     * Can be used to inspect headers, cookies, client IP, etc.
     *
     * @param array<string, string> $headers HTTP headers from handshake request
     * @param string $acceptKey Sec-WebSocket-Accept value (connection identifier)
     * @param array<string, string> $cookies Parsed cookies from Cookie header
     * @param string $clientIp Client IP (IPv4 or IPv6, empty if unavailable)
     * @param array<string, string|array> $queryParams Query parameters from request URL
     */
    abstract protected function onHandshake(
        array $headers,
        string $acceptKey,
        array $cookies,
        string $clientIp,
        array $queryParams,
    ): void;

    /**
     * Tick method - called on each server tick.
     */
    public function onTick(): void
    {
        // No periodic operations by default for WebSocket clients
        // Can be overridden in child classes if needed
    }

    /**
     * Called when socket connection is successfully closed.
     */
    protected function onClose(): void
    {
        // Reset fragmented message state
        $this->isReceivingFragmented = false;
        $this->fragmentedOpcode = 0;
        $this->fragmentedPayload = '';

        // WebSocket client cleanup if needed
        // Can be overridden in child classes
        if ($this->acceptKey === '') {
            return;
        }

        $closeDto = new WebSocketCloseSignalDTO(acceptKey: $this->acceptKey);
        Hilos::$sr->queueSignal(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::CONNECTION_CLOSE),
            new SignalName(SignalName::EMPTY),
            $closeDto,
        );

        $pageDto = new WebSocketPageUnsubscribeSignalDTO(
            acceptKey: $this->acceptKey,
        );

        Hilos::$sr->queueSignal(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::PAGE_UNSUBSCRIBE),
            new SignalName(SignalName::EMPTY),
            $pageDto,
        );

        Hilos::$sr->unsubscribeFromAll($this->acceptKey);
    }
}
