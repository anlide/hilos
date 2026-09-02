<?php

declare(strict_types=1);

namespace Hilos\Socket\Client;

use Hilos\Auth\Session\SessionRotationTicket;
use Hilos\Auth\Session\SessionToken;
use Hilos\Auth\Throttle\ThrottleIdentity;
use Hilos\Constants\AppEnv;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\WebSocketConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Daemon\ConnectionDropper;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\ProtectedModeAdmissionRecorder;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\ProtectedMode\ProtectedModeAdmissionConstants;
use Hilos\ProtectedMode\ProtectedModeStubCopy;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\Exception\RtBaseException;
use Hilos\Runtime\View\Item\HilosSessionRotation;
use Hilos\Socket\Client\Interface\WebSocketClientInterface;
use Hilos\Socket\SocketException;
use Hilos\Socket\WebSocket\DTO\HandshakeWelcomeSignalData;
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
use Hilos\Socket\WebSocket\DTO\WebSocketTableViewportSignalDTO;
use Hilos\Socket\WebSocket\Exception\HandshakeFailedException;
use Hilos\Socket\WebSocket\Exception\InvalidFrameException;
use Hilos\Socket\WebSocket\Exception\InvalidFrameSequenceException;
use Hilos\Socket\WebSocket\Exception\ReservedOpcodeException;
use Hilos\Socket\WebSocket\Exception\UnknownOpcodeException;
use Hilos\Socket\WebSocket\Exception\UnsupportedProtocolVersionException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\WebSocketFrameDTO;
use Hilos\Utils\Helpers\HttpHeaderHelper;
use Hilos\Utils\Helpers\JsonHelper;
use Hilos\Utils\Helpers\RandomHelper;
use Hilos\Utils\Logger;
use Hilos\Core\Exception\InvalidStateException;
use Hilos\Core\Exception\UnsupportedOperationException;
use Hilos\Environment\Exception\EnvException;
use Random\RandomException;
use Throwable;
use Hilos\Runtime\View\Collection\HilosSessionRotations;

/**
 * WebSocketClient - Represents a single WebSocket connection.
 *
 * Handles WebSocket protocol frame parsing and writing.
 * Created by WebSocketServer when accepting new connections.
 */
abstract class WebSocketClient extends AbstractClient implements WebSocketClientInterface
{

    /** @var string Daemon-minted connection identifier, assigned at handshake (not the RFC Sec-WebSocket-Accept value) */
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

    /**
     * @var ?string Hash of this connection's session token, computed on the 101; null before the
     * handshake and for a connection that carries no session at all.
     *
     * The accept key above names this socket and dies with it; this names the browser behind it and
     * outlives a reload. It is what lets the master recognize the operator who started a destructive
     * operation in a tab that is not the tab they started it from, and what a session-addressed
     * frame is matched against on the way out (HIL-655). The hash is held rather than the token:
     * this object lives for the length of the connection in the master process, and the token is
     * the key to the account.
     *
     * Readable from outside because the fan-out asks each client whether the frame is for it; the
     * write stays private, because only the 101 knows which browser this socket came from.
     */
    public ?string $sessionTokenHash {
        get {
            return $this->sessionTokenHashValue;
        }
    }

    /** @var ?string Backing storage for the session token hash */
    private ?string $sessionTokenHashValue = null;

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
    private const int HIGH_BIT_SHIFT = 7;            // Shift that turns bit 7 (FIN, MASK) into a 0/1 flag

    /** Frame header length constants */
    private const int HEADER_LEN_BASE = 2;           // Base header length (2 bytes)
    private const int HEADER_LEN_16BIT = 4;          // Header length with 16-bit payload length
    private const int HEADER_LEN_64BIT = 10;         // Header length with 64-bit payload length

    /** Pack/unpack format strings for binary encoding */
    private const string PACK_FORMAT_16BIT = 'n';    // Unsigned short (16-bit, big-endian)
    private const string PACK_FORMAT_64BIT = 'J';    // Unsigned long long (64-bit, machine byte order)

    /** @var int Byte length of the minted connection identifier (128-bit) */
    private const int ACCEPT_KEY_RANDOM_BYTES = 16;

    /** @var bool Whether WebSocket handshake is completed */
    protected bool $handshakeCompleted = false;

    /**
     * @var ?ConnectionDropper Master seam closing OTHER connections, wired in by the server
     * when this client is accepted; null in a socketless probe and in any host that never
     * registered one, where a rotation simply drops nobody.
     */
    private ?ConnectionDropper $connectionDropper = null;

    /**
     * @var ?ProtectedModeAdmissionRecorder Master seam recording an admitted verifier, wired in by the server
     *
     * Null leaves the whole admission inert, exactly like the dropper above: no seam, no
     * verifier let in, and the connection simply stays on the maintenance stub.
     */
    private ?ProtectedModeAdmissionRecorder $protectedModeAdmissionRecorder = null;

    /**
     * @var list<string> Accept keys a spent rotation left to drop, held until this
     * connection's own outbound buffer empties ({@see spendRotation()}); empty at every
     * other moment of a connection's life.
     */
    private array $pendingRotationDrops = [];

    /**
     * @var ?string Peer address this connection was accepted from, read once on the 101
     *
     * Held rather than asked for per frame: the peer of an established connection cannot
     * change, and `socket_getpeername()` is a syscall the master would otherwise pay on
     * every action frame it forwards.
     */
    private ?string $handshakeClientIp = null;

    /**
     * @var ?string Digest of this connection's session token, computed once on the 101
     *
     * The token itself deliberately never leaves the master: an action payload is written
     * to the analytics store verbatim, and a session token recorded there would be a
     * credential anyone reading analytics could replay. The digest identifies the browser
     * for throttling exactly as well and cannot be presented as a session (HIL-420).
     */
    private ?string $sessionIdentity = null;

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
     * @throws AgentUnknownActionException When action name is not allowed
     * @throws UnsupportedOperationException When an internal signal branch is unreachable
     * @throws EnvException When the build timestamp env value cannot be read
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws InvalidFormatException When the upgrade request's query string carries a non-string value
     * @throws InvalidArgumentException When a signal the frame turns into cannot be named
     */
    protected function processReadBuffer(): void
    {
        if (!$this->handshakeCompleted) {
            $this->handleHandshake();
            return;
        }

        while (strlen($this->readBuffer) >= self::HEADER_LEN_BASE) {
            $frame = $this->parseFrame();
            if ($frame === null) {
                break;
            }

            $this->handleFrame($frame);
        }
    }

    /**
     * Handle WebSocket frame based on opcode.
     *
     * @param WebSocketFrameDTO $frame Parsed frame data
     * @throws UnknownOpcodeException When opcode is unknown
     * @throws ReservedOpcodeException When opcode is reserved
     * @throws InvalidFrameSequenceException When frame sequence is invalid
     * @throws InvalidFrameException When text/binary payload is invalid
     * @throws AgentUnknownActionException When action name is not allowed
     * @throws UnsupportedOperationException When an internal signal branch is unreachable
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
                    if ($frame->payload === WebSocketConstants::KEEPALIVE_TEXT_PING) {
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
     * Mints the random connection identifier (acceptKey) and appends the
     * framework welcome frame right behind the 101 response bytes.
     *
     * @throws HandshakeFailedException When handshake validation fails
     * @throws SocketException When socket error occurs
     * @throws UnsupportedProtocolVersionException When protocol version is not 13
     * @throws EnvException When the build timestamp, cookie or environment env values cannot be read
     * @throws RandomException When the secure random source refuses this connection's secrets
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
        $queryParams = $this->parseQueryParams($requestLine[HttpConstants::REQUEST_KEY_PATH]);

        $lines = explode(HttpConstants::HTTP_LINE_SEPARATOR, $request);
        $headers = $this->parseHeaders($lines);

        // Check if it's a WebSocket upgrade request
        $upgrade = HttpHeaderHelper::get($headers, HttpConstants::HEADER_UPGRADE);
        if ($upgrade === null || strtolower($upgrade) !== HttpConstants::WEBSOCKET_PROTOCOL) {
            throw new HandshakeFailedException("Missing or invalid Upgrade header");
        }

        // Check WebSocket protocol version (RFC 6455 requires version 13)
        $version = HttpHeaderHelper::get($headers, HttpConstants::HEADER_SEC_WEBSOCKET_VERSION);
        if ($version !== WebSocketConstants::PROTOCOL_VERSION) {
            throw new UnsupportedProtocolVersionException($version ?? 'not specified');
        }

        // Generate WebSocket-Accept header value
        // RFC 6455 Section 4.2.2: The server must concatenate the client's Sec-WebSocket-Key
        // with the magic string "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", compute SHA1 hash,
        // and encode it as base64 to get the Sec-WebSocket-Accept value. The value is
        // derivable from the client-chosen key, so it serves the 101 header ONLY and is
        // never the connection identity.
        // A blank header is refused alongside an absent one: RFC 6455 requires a
        // base64 nonce, and hashing "" would still mint a well-formed accept value.
        // Spelled out rather than left to empty(), which also calls '0' empty.
        $key = HttpHeaderHelper::get($headers, HttpConstants::HEADER_SEC_WEBSOCKET_KEY);
        if ($key === null || $key === '') {
            throw new HandshakeFailedException("Missing Sec-WebSocket-Key header");
        }

        // Concatenate key + magic string, compute SHA1 (binary output), encode as base64
        $secWebSocketAccept = base64_encode(sha1($key . WebSocketConstants::RFC6455_ACCEPT_MAGIC, true));

        $cookies = $this->parseCookies($headers);
        $sessionCookieName = Hilos::$env->string(EnvConstants::HILOS_SESSION_COOKIE_NAME);

        // A browser that just logged in comes back carrying the ticket its rotation was
        // announced with; anyone else carries nothing here and is served exactly as before.
        $rotateCookieName = SessionRotationTicket::cookieName($sessionCookieName);
        $presentedTicket = $cookies[$rotateCookieName] ?? null;
        $rotation = $presentedTicket === null ? null : self::claimRotation($presentedTicket);

        // Mint this connection's two secrets: the identifier, random and server-owned
        // so no client can choose or predict another connection's identity, and the
        // session token - the one the claimed rotation names, else the client's cookie
        // when it has the minted form, else a fresh mint. Both are in-memory (no I/O), so
        // the master stays light; the worker handshake reads them from the DTO, which is
        // why the rotated token has to be settled here and not after the response.
        // A secure source that refuses cannot be answered with a guessable secret, so
        // this connection is doomed and the refusal travels on: the manager stops the
        // node over it, and neither the 101 nor the welcome frame is assembled below.
        try {
            $acceptKey = self::mintAcceptKey();
            $sessionToken = $rotation?->sessionToken ?? self::resolveSessionToken($cookies, $sessionCookieName);
        } catch (RandomException $exception) {
            $this->shouldClose = true;
            throw $exception;
        }

        // Hashed here, where the token has just been settled, and never re-derived later: this runs
        // on the accept loop, so the one hash a connection costs the master is this one.
        $this->sessionTokenHashValue = $sessionToken === ''
            ? null
            : ProtectedModeRuntime::hashSessionToken($sessionToken);

        // The one thing a rotation carries besides the token: the announcement the socket it
        // replaces had not shown yet (HIL-423). It rides the handshake signal rather than
        // being looked up in the worker, because the ticket is spent here and nothing later
        // can tell this connection apart from a reload of the same session.
        /** @var ?string $inheritedAck */
        $inheritedAck = $rotation?->pendingAck;

        // Call onHandshake callback before completing handshake
        $this->handleHandshakeInternal(
            $headers,
            $acceptKey,
            $cookies,
            $this->resolveClientIp($headers),
            $queryParams,
            $sessionToken,
            $inheritedAck,
        );

        // Send handshake response
        $response = HttpConstants::HTTP_VERSION
            . ' ' . HttpConstants::HTTP_STATUS_SWITCHING_PROTOCOLS
            . ' ' . HttpConstants::HTTP_REASON_SWITCHING_PROTOCOLS
            . HttpConstants::HTTP_LINE_SEPARATOR;
        $response .= HttpConstants::HEADER_UPGRADE . ": " . HttpConstants::WEBSOCKET_PROTOCOL . HttpConstants::HTTP_LINE_SEPARATOR;
        $response .= HttpConstants::HEADER_CONNECTION . ": " . HttpConstants::HEADER_UPGRADE . HttpConstants::HTTP_LINE_SEPARATOR;
        $response .= HttpConstants::HEADER_SEC_WEBSOCKET_ACCEPT . ": " . $secWebSocketAccept . HttpConstants::HTTP_LINE_SEPARATOR;
        $response .= $this->buildSessionCookieHeader($sessionCookieName, $sessionToken);
        if ($presentedTicket !== null) {
            // Presented, therefore spent: whether it bought a rotation or was stale, garbage
            // or already burned, the browser must not carry it into the next handshake.
            $response .= $this->buildRotationCookieClearedHeader($rotateCookieName);
        }
        $response .= HttpConstants::HTTP_LINE_SEPARATOR;

        $this->writeBuffer .= $response;
        $this->handshakeCompleted = true;
        // Before the welcome, never after: the welcome tells this connection whether the mode
        // locks it out, and a verifier that presented a valid pass no longer is locked out.
        $this->admitProtectedModePass($queryParams);
        $this->sendHandshakeWelcome($acceptKey, $sessionCookieName);

        if ($rotation !== null) {
            $this->spendRotation($rotation);
        }
    }

    /**
     * Returns the rotation a presented ticket may still be traded for, or null.
     *
     * The one read the master makes on behalf of a login: a light in-memory lookup of the
     * framework collection an inbound RT sync filled ({@see HilosSessionRotations}), on the
     * same terms as the protected-mode row beside it. Null is the ordinary answer and means
     * "serve this handshake by the cookie rule" - the ticket is malformed, was never minted,
     * has already been spent, or its moment passed.
     *
     * A runtime failure is contained rather than propagated. This runs on the connection
     * accept path, where an exception would cost the visitor the handshake itself over
     * bookkeeping that only ever decides whether one login is completed early; the browser
     * that loses its rotation here comes back anonymous and logs in again, which is the same
     * safe failure a lost ticket already has.
     *
     * @param string $ticket Ticket value presented in the auxiliary cookie
     * @return ?HilosSessionRotation Live rotation, or null when the ticket buys nothing
     */
    private static function claimRotation(string $ticket): ?HilosSessionRotation
    {
        if (!SessionRotationTicket::isValid($ticket)) {
            return null;
        }

        try {
            return Hilos::$rt?->hilosSessionRotations->claimable($ticket);
        } catch (RtBaseException $exception) {
            Logger::error('Session rotation lookup failed', ['error' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * Burns a traded rotation now and holds its drops until the 101 has left.
     *
     * The two halves are deliberately not in the same moment. Burning is immediate, because
     * the ticket must buy a single handshake even inside its lifetime, and it is this read
     * that spent it. Dropping the session's other tabs waits for {@see onOutboundDrained()}:
     * queueing the 101 is not the same as sending it, and a tab dropped while the rotated
     * Set-Cookie still sits in this buffer could come back on the pre-rotation token, be
     * served a fresh anonymous session under it, and write that back over the jar - logging
     * the person out of the session they just signed into. The order the leaf rests on is
     * therefore program order here, not the few microseconds the read loop takes to reach
     * its write.
     *
     * Contained for the same reason as the lookup, and the failure is benign: the row that
     * was not burned is swept by the owning agent when its moment passes.
     *
     * @param HilosSessionRotation $rotation Rotation this handshake traded its ticket for
     */
    private function spendRotation(HilosSessionRotation $rotation): void
    {
        /** @var list<string> $keysToDrop */
        $keysToDrop = $rotation->acceptKeysToDrop;
        $this->pendingRotationDrops = $keysToDrop;

        try {
            Hilos::$rt?->hilosSessionRotations->actions->forget($rotation->ticket);
        } catch (RtBaseException $exception) {
            Logger::error('Session rotation could not be burned', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * Drops the connections a spent rotation left behind, now that its 101 is on the wire.
     *
     * Called from {@see write()} the moment this connection's buffer empties, which for the
     * rotated handshake is the moment its Set-Cookie has been handed to the kernel — see
     * {@see spendRotation()} for why the two are ordered rather than done together. A socket
     * that dies before it drains simply never drops them, and that is the right end: its
     * browser never received the new cookie either, so the tabs are no worse off than the
     * initiator, and they are anonymous until their own socket dies.
     *
     * The failure is benign for the same reason: a connection that could not be closed shows
     * a stale identity until it goes on its own.
     */
    private function onOutboundDrained(): void
    {
        $keysToDrop = $this->pendingRotationDrops;
        $this->pendingRotationDrops = [];

        try {
            foreach ($keysToDrop as $acceptKey) {
                $this->connectionDropper?->dropWebSocketConnection($acceptKey);
            }
        } catch (SocketException $exception) {
            Logger::error('Session rotation could not drop a connection', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * Writes the outbound buffer and releases anything that was waiting for it to empty.
     *
     * The only thing waiting today is a spent rotation's drops ({@see onOutboundDrained()}).
     * A partial write leaves them held for the next call, which is exactly the point.
     *
     * @throws SocketException If socket write fails
     * @throws HilosException When buffered wire input refuses to become a DTO
     */
    public function write(): void
    {
        parent::write();

        if ($this->pendingRotationDrops !== [] && $this->writeBuffer === '') {
            $this->onOutboundDrained();
        }
    }

    /**
     * Hands this connection the seam that force-closes another one.
     *
     * Called by the server that accepted it, so the client can drop the connections a
     * rotation left behind without knowing the daemon that owns the socket table - the
     * mirror of how the command channel is handed the same seam for its drop command.
     *
     * @param ConnectionDropper $connectionDropper Master seam that force-closes a connection
     */
    public function setConnectionDropper(ConnectionDropper $connectionDropper): void
    {
        $this->connectionDropper = $connectionDropper;
    }

    /**
     * Hands this connection the seam that records it as an admitted verifier.
     *
     * Given at accept for the same reason as the dropper above, so the connection code never
     * reaches back into the daemon to find the runtime row it may not write itself.
     *
     * @param ProtectedModeAdmissionRecorder $recorder Master seam that records an admitted verifier
     */
    public function setProtectedModeAdmissionRecorder(ProtectedModeAdmissionRecorder $recorder): void
    {
        $this->protectedModeAdmissionRecorder = $recorder;
    }

    /**
     * Mint a connection identifier: 128-bit random, base64url without padding.
     *
     * The RFC 6455 Sec-WebSocket-Accept value is derivable by the client from
     * its own Sec-WebSocket-Key, so it cannot serve as a trusted identity.
     *
     * The key addresses this connection everywhere it is spoken about - the pending
     * OAuth login, the protected-mode initiator, the connection-drop command - so it
     * is a secret and is drawn from the secure axis of RandomHelper (HIL-568).
     *
     * @return string Minted accept key (22 base64url characters)
     * @throws RandomException When the platform's secure random source refuses
     */
    private static function mintAcceptKey(): string
    {
        $random = RandomHelper::secureBytes(self::ACCEPT_KEY_RANDOM_BYTES);

        return rtrim(strtr(base64_encode($random), '+/', '-_'), '=');
    }

    /**
     * Resolve the session token for this connection: the value the client sent
     * when it has the minted form, a fresh token otherwise. A value that does
     * not pass is replaced silently - the client owns it, so a log line here is
     * noise anyone outside can write - and the client leaves the handshake with
     * a token that works instead of one the worker refuses on every reconnect.
     * In-memory only (random bytes, like the accept key), so the master stays
     * light; the worker reads the token from the DTO.
     *
     * @param array<string, string> $cookies Parsed request cookies
     * @param string $name Session cookie name
     * @return string Session token (the client's cookie value, or freshly minted)
     * @throws RandomException When the platform's secure random source refuses a mint
     */
    private static function resolveSessionToken(array $cookies, string $name): string
    {
        $sent = $cookies[$name] ?? null;

        return $sent !== null && SessionToken::isValid($sent) ? $sent : SessionToken::mint();
    }

    /**
     * Build the Set-Cookie header line carrying the session token on the 101.
     * Issued on every handshake, not only when the token is minted: the session
     * row's expiry slides on each handshake, so the cookie has to slide with it
     * or an active visitor loses the session the row still considers alive.
     * HttpOnly and SameSite=Strict always; Secure wherever the deployment is
     * production-like (HIL-582).
     *
     * @param string $name Cookie name
     * @param string $token Session token value
     * @return string Set-Cookie header line including the trailing separator
     * @throws EnvException When the cookie max-age or the environment name cannot be read
     */
    private function buildSessionCookieHeader(string $name, string $token): string
    {
        $cookie = $name . '=' . $token
            . '; Path=/; HttpOnly; SameSite=Strict'
            . '; Max-Age=' . Hilos::$env->int(EnvConstants::HILOS_SESSION_COOKIE_MAX_AGE);
        if (self::cookiesAreSecured()) {
            $cookie .= '; Secure';
        }

        return HttpConstants::HEADER_SET_COOKIE . ': ' . $cookie . HttpConstants::HTTP_LINE_SEPARATOR;
    }

    /**
     * Build the Set-Cookie header line that clears the auxiliary rotation cookie.
     *
     * The cookie the frontend wrote for one reconnect, expired on that reconnect. Its
     * attributes mirror the ones the frontend wrote it with, minus Secure: a cookie is
     * identified by name, domain and path, so the erasure lands whether or not the original
     * carried the flag - while sending Secure over plain http would have the browser drop the
     * erasure and leave the spent ticket in place.
     *
     * @param string $name Auxiliary cookie name
     * @return string Set-Cookie header line including the trailing separator
     */
    private function buildRotationCookieClearedHeader(string $name): string
    {
        return HttpConstants::HEADER_SET_COOKIE . ': ' . $name . '=; Path=/; SameSite=Strict; Max-Age=0'
            . HttpConstants::HTTP_LINE_SEPARATOR;
    }

    /**
     * Whether cookies this master sets carry the Secure attribute, read from APP_ENV.
     *
     * One behaviour instead of a switch (HIL-582). It used to be its own env key
     * defaulting to false, which meant every installation that never heard of the key
     * shipped its session cookie over plain http - including production ones. The
     * environment already says whether the deployment is production-like, and the
     * framework judges database seeds and test-only CLI commands by exactly that, so the
     * flag is derived rather than configured: prod and staging are secured, dev, local
     * and test are not.
     *
     * An APP_ENV nobody recognises is treated as not production-like, matching how the
     * enum answers everywhere else. The cost is named: an installation running prod
     * behind a non-TLS frontend loses the cookie and is cured by naming its environment
     * honestly. What is gained is that Secure can no longer be off in production without
     * anyone saying so.
     *
     * Since HIL-566 the read cannot answer "nothing": APP_ENV is a required catalog entry and
     * a node without it refuses to start, so the missing case never reaches a live handshake.
     *
     * @return bool True when the deployment is production-like (prod or staging)
     * @throws EnvException When APP_ENV cannot be read
     */
    private static function cookiesAreSecured(): bool
    {
        return AppEnv::fromString(Hilos::$env->string(EnvConstants::APP_ENV))?->isProductionLike() === true;
    }

    /**
     * Append the framework welcome frame right behind the 101 response bytes.
     *
     * First frame of every connection:
     * {type: 'handshake', data: {build, sessionCookieName, protectedMode}}.
     * `build` carries the HILOS_BUILD_TIMESTAMP env value the frontend compares on every
     * (re)connect to force a page refresh on mismatch; `protectedMode.active` tells a
     * connection caught by a cluster freeze that it is locked out, and the copy beside it
     * gives that connection the words to say so without asking anything further. The freeze
     * flag is a light in-memory read of the daemon-owned runtime row on this same master
     * process — inert (false) when no project mounted the item — so the light master stays
     * light; the copy comes from a facade constant, not the database, which a restore is
     * rewriting underneath us. `sessionCookieName` is the name of the cookie this same 101
     * just set, told to the frontend so a token rotation can write the auxiliary cookie
     * beside it (HIL-582).
     * Written directly to the write buffer so the 101 response and the welcome leave in
     * one flush.
     *
     * @param string $acceptKey This connection's accept key, compared against the initiator's
     * @param string $sessionCookieName Name of this deployment's session cookie
     * @throws EnvException When the build timestamp env value cannot be read
     */
    private function sendHandshakeWelcome(string $acceptKey, string $sessionCookieName): void
    {
        $locksOut = $this->protectedModeLocksOut($acceptKey);
        $operation = $locksOut ? Hilos::$rt?->hilosProtectedModeRuntime?->operation : null;
        $copy = $locksOut ? ProtectedModeStubCopy::forOperation($operation) : null;
        $welcome = new HandshakeWelcomeSignalData(
            build: Hilos::$env->string(EnvConstants::HILOS_BUILD_TIMESTAMP),
            sessionCookieName: $sessionCookieName,
            protectedModeActive: $locksOut,
            protectedModeOperation: $operation,
            protectedModeTitle: $copy?->title,
            protectedModeMessage: $copy?->message,
            // The row's own bit, not this connection's - exactly what the pushed frame carries.
            // A locked-out connection reads it as "the surface may offer a code field"; an
            // admitted verifier reads it as "the window I am inside is still open", and needs
            // to: its welcome says active:false in the very words a lift does, and without this
            // bit the two are one frame. That client would then either reload itself out of the
            // window or, taking the silence for admission, keep a dead key past the lift.
            protectedModeAcceptsPass: $this->protectedModePhaseIsVerifying(),
            // The second bit of the same row: whether anything has been minted yet. Without it a
            // verifier arriving mid-window would be handed a field before any code exists, which
            // is the lie this pair was added to end.
            protectedModePassIssued: $this->protectedModePassIssued(),
        );
        $message = [
            SignalPayloadConstants::FIELD_TYPE => SignalTypeConstants::HANDSHAKE,
            SignalPayloadConstants::FIELD_DATA => $welcome->toArray(),
        ];
        $messageJson = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($messageJson === false) {
            Logger::error('Failed to encode the handshake welcome frame');
            return;
        }

        $this->writeBuffer .= $this->buildFrameHeader(strlen($messageJson), self::OPCODE_TEXT) . $messageJson;
    }

    /**
     * Reads the protected-mode runtime row and reports whether this connection is locked out.
     *
     * A light in-memory lookup of the daemon-owned singleton on this master process (the same
     * Hilos::$rt the daemon writes the freeze into): false whenever this process holds no runtime
     * state. There is no project that does not use protected mode - the framework mounts the row
     * for every project that has an RT context.
     *
     * The connection is offered under both of its names: the accept key of this socket and the hash
     * of the session behind it. The second is the one a reload and a second tab arrive with, and
     * without it the operator watching their own restore is locked out of it by pressing F5.
     *
     * @param string $acceptKey This connection's accept key, compared against the initiator's
     * @return bool Whether an active freeze locks this connection out
     */
    private function protectedModeLocksOut(string $acceptKey): bool
    {
        return Hilos::$rt?->hilosProtectedModeRuntime?->locksOut($acceptKey, $this->sessionTokenHash) ?? false;
    }

    /**
     * Whether the freeze is in the one phase that takes a code from the maintenance surface.
     *
     * @return bool Whether the mode currently accepts a pass
     */
    private function protectedModePhaseIsVerifying(): bool
    {
        return Hilos::$rt?->hilosProtectedModeRuntime?->phase === ProtectedModeRuntime::PHASE_VERIFYING;
    }

    /**
     * Whether the verification window has at least one pass standing on its row.
     *
     * Both halves are stated rather than only the array test: the bit means "a code can be
     * presented right now", and a row that left the window has already voided its hashes. Saying
     * so twice costs one comparison and keeps the sentence in the code the sentence in the
     * contract. Like every read on this path it is an in-memory lookup of the daemon-owned
     * singleton on this master process - no database, no file, no socket.
     *
     * @return bool Whether a pass has been issued for the window in flight
     */
    private function protectedModePassIssued(): bool
    {
        $freeze = Hilos::$rt?->hilosProtectedModeRuntime;

        return $freeze?->phase === ProtectedModeRuntime::PHASE_VERIFYING && $freeze->passHashes !== [];
    }

    /**
     * Lets this connection through the freeze when its upgrade request carried a valid pass.
     *
     * The whole weight of the admission, and it is deliberately small: one in-memory row read,
     * one hash and one constant-time compare. Nothing is read from the database - a restore is
     * rewriting exactly the tables a credential would come from, which is the reason the pass
     * exists at all - and nothing blocks, because this runs on the master's accept path.
     *
     * A wrong key changes nothing: the connection stays on the stub and the field can be tried
     * again. The comparison is {@see hash_equals()} rather than `===` because the two sides are
     * a secret and an attacker-supplied value, which is the one place a timing difference is
     * worth removing even though the search space is a 256-bit hash.
     *
     * What the match admits is the browser session rather than this socket: the verifier reads
     * the code once and then opens tabs, and each of those arrives with the same cookie and a
     * brand new accept key. A connection carrying no session is refused for the reason the
     * initiator's null hash is refused - letting two nulls meet would open the node to every
     * cookieless visitor - and it is barely reachable anyway, since a token is minted on every
     * handshake.
     *
     * @param RequestQueryParams $queryParams Query parameters of the upgrade request
     */
    private function admitProtectedModePass(RequestQueryParams $queryParams): void
    {
        $pass = $queryParams->getString(ProtectedModeAdmissionConstants::HILOS_PASS_QUERY_PARAM);
        if ($pass === null || $pass === '' || $this->sessionTokenHash === null) {
            return;
        }

        $freeze = Hilos::$rt?->hilosProtectedModeRuntime;
        if ($freeze === null || $freeze->phase !== ProtectedModeRuntime::PHASE_VERIFYING) {
            return;
        }

        $presented = hash(ProtectedModeAdmissionConstants::PASS_HASH_ALGO, $pass);
        foreach ($freeze->passHashes as $minted) {
            if (hash_equals($minted, $presented)) {
                $this->protectedModeAdmissionRecorder?->admitProtectedModeSession($this->sessionTokenHash);
                return;
            }
        }
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
        $firstLine = $lines[0];

        // Parse: GET /path?query=params HTTP/1.1
        $parts = explode(' ', $firstLine);

        return [
            HttpConstants::REQUEST_KEY_METHOD => $parts[0] ?? HttpConstants::METHOD_GET,
            HttpConstants::REQUEST_KEY_PATH => $parts[1] ?? HttpConstants::PATH_ROOT,
            HttpConstants::REQUEST_KEY_VERSION => $parts[2] ?? HttpConstants::HTTP_VERSION,
        ];
    }

    /**
     * Parse query parameters from path.
     *
     * @param string $path Path with optional query string (e.g. /path?key=value)
     * @return RequestQueryParams Query parameters from request URL
     */
    private function parseQueryParams(string $path): RequestQueryParams
    {
        return RequestQueryParams::fromPath($path);
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

        $fin = ($byte1 & self::FIN_MASK) >> self::HIGH_BIT_SHIFT;
        $opcode = $byte1 & self::OPCODE_MASK;
        $masked = ($byte2 & self::MASKED_MASK) >> self::HIGH_BIT_SHIFT;
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

        // external-boundary: an unmasked frame has no masking key, and only the masked branch reads it
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
     * @throws InvalidFrameException When JSON or signal fields are invalid
     * @throws AgentUnknownActionException When action name is not allowed
     * @throws UnsupportedOperationException When an internal signal branch is unreachable
     * @throws InvalidArgumentException When the signal the frame turns into cannot be named
     */
    protected function onFrame(string $payload): void
    {
        $this->trackCurrentClientIp();
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

                $requestId = isset($decoded[SignalPayloadConstants::FIELD_REQUEST_ID])
                    && is_string($decoded[SignalPayloadConstants::FIELD_REQUEST_ID])
                    && $decoded[SignalPayloadConstants::FIELD_REQUEST_ID] !== ''
                    ? $decoded[SignalPayloadConstants::FIELD_REQUEST_ID]
                    : null;

                // Who is asking rides with the action itself. There is no accept-key→IP map
                // anywhere to consult instead: the handshake is routed to the WebSocket
                // lifecycle agent and the action to the page agent, so nothing the worker can
                // reach knows both (HIL-420).
                $dto = new WebSocketActionSignalDTO(
                    acceptKey: $acceptKey,
                    action: $actionName,
                    data: $actionData,
                    requestId: $requestId,
                    clientIp: $this->handshakeClientIp,
                    sessionIdentity: $this->sessionIdentity,
                );

                $userActionId = Hilos::$ac?->logUserAction($acceptKey, $actionName, $actionData);
                Hilos::$ac?->startUserActionCapture($userActionId);
                try {
                    Hilos::$sr->queueSignal(
                        new SignalSource(SignalSource::WEBSOCKET),
                        new SignalType(SignalTypeConstants::ACTION),
                        new SignalName($actionName),
                        $dto,
                    );
                } finally {
                    Hilos::$ac?->clearUserActionCapture();
                }
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

            case SignalTypeConstants::TABLE_VIEWPORT: {
                $page = isset($decoded[SignalPayloadConstants::FIELD_PAGE])
                    && is_string($decoded[SignalPayloadConstants::FIELD_PAGE])
                    ? $decoded[SignalPayloadConstants::FIELD_PAGE]
                    : throw new InvalidFrameException("Page is required for {$type} signal");
                if ($page === '') {
                    throw new InvalidFrameException("Page is empty for {$type} signal");
                }

                $decoded[SignalPayloadConstants::FIELD_ACCEPT_KEY] = $acceptKey;

                // The viewport is the one frame whose shape is read by its DTO rather
                // than field by field here, so its refusal is translated into the type
                // the checks above throw. Either reader of the client closes the
                // connection on both classes now, so what the translation buys is one
                // vocabulary on this path: a frame that could not be read says so as a
                // frame error, and not as a payload refusal from somewhere below.
                try {
                    $dto = WebSocketTableViewportSignalDTO::fromArray($decoded);
                } catch (InvalidFormatException $exception) {
                    throw new InvalidFrameException($exception->getMessage(), $exception);
                }

                Hilos::$sr->queueSignal(
                    new SignalSource(SignalSource::WEBSOCKET),
                    new SignalType(SignalTypeConstants::TABLE_VIEWPORT),
                    new SignalName($page),
                    $dto,
                );

                $this->onTableViewportParsed($page, $dto);
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
     * Hook: called after a table viewport payload is parsed and queued.
     *
     * @param string $page Page identifier
     * @param WebSocketTableViewportSignalDTO $dto Table viewport signal
     */
    protected function onTableViewportParsed(string $page, WebSocketTableViewportSignalDTO $dto): void
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
     * @throws AgentUnknownActionException When action name is not allowed
     */
    protected function onActionValidated(string $actionName): void
    {
        throw new AgentUnknownActionException("Unknown websocket action type: {$actionName}");
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
     * @throws InvalidArgumentException When the signal the frame turns into cannot be named
     */
    protected function onFrameBinary(string $payload): void
    {
        $this->trackCurrentClientIp();
        $dto = new WebSocketFrameBinarySignalDTO(
            acceptKey: $this->acceptKey,
            payload: $payload,
        );

        Hilos::$sr->queueSignal(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::FRAME_BINARY),
            new SignalName(SignalTypeConstants::FRAME_BINARY),
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
     * @param array<string, string> $headers HTTP headers from handshake request (lowercase header names)
     * @param string $acceptKey Daemon-minted connection identifier (not the RFC Sec-WebSocket-Accept value)
     * @param array<string, string> $cookies Parsed cookies from Cookie header
     * @param ?string $clientIp Client IP (IPv4 or IPv6), or null when the peer name is unavailable
     * @param RequestQueryParams $queryParams Query parameters from request URL
     * @param string $sessionToken Session token resolved on the 101 (cookie value or freshly minted)
     * @param ?string $inheritedAck Success ack a traded rotation carried over to this connection, or null for every other handshake
     * @throws InvalidArgumentException When the handshake signal cannot be named
     */
    final protected function handleHandshakeInternal(
        array $headers,
        string $acceptKey,
        array $cookies,
        ?string $clientIp,
        RequestQueryParams $queryParams,
        string $sessionToken = '',
        ?string $inheritedAck = null,
    ): void {
        $this->acceptKey = $acceptKey;
        $this->handshakeClientIp = $clientIp;
        $this->sessionIdentity = ThrottleIdentity::forSession($sessionToken);
        $this->onHandshake($headers, $acceptKey, $cookies, $clientIp, $queryParams);

        // The connection row is all the master writes here: resolving the browser session
        // costs a SELECT and an INSERT, and this code runs on the accept loop
        // (docs/agents/antipatterns/heavy-work-in-master.md). The worker attaches the
        // session to this row when it picks the handshake signal up.
        Hilos::$ac?->openWsConnection($acceptKey, $clientIp);

        $dto = new WebSocketHandshakeSignalDTO(
            headers: $headers,
            acceptKey: $acceptKey,
            cookies: $cookies,
            clientIp: $clientIp,
            queryParams: $queryParams,
            sessionToken: $sessionToken,
            inheritedAck: $inheritedAck,
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
     * @param array<string, string> $headers HTTP headers from handshake request (lowercase header names)
     * @param string $acceptKey Daemon-minted connection identifier (not the RFC Sec-WebSocket-Accept value)
     * @param array<string, string> $cookies Parsed cookies from Cookie header
     * @param ?string $clientIp Client IP (IPv4 or IPv6), or null when the peer name is unavailable
     * @param RequestQueryParams $queryParams Query parameters from request URL
     */
    abstract protected function onHandshake(
        array $headers,
        string $acceptKey,
        array $cookies,
        ?string $clientIp,
        RequestQueryParams $queryParams,
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
     *
     * Announces the close and nothing more. The subscriptions the accept key held are
     * dropped by the master's own dispatch, after the close has been routed
     * ({@see DaemonManager::forgetSubscriptionsAfterRouting()}): the records name the
     * agent instances serving this connection, and dropping them here would erase the
     * addressees before the close reached them (HIL-627). The page the connection sat
     * on is unsubscribed on the worker side by
     * {@see WorkerManager::dispatchPageUnsubscribeIfTrackedOnConnectionClose()},
     * which is the only side that knows which page that was.
     *
     * @throws InvalidArgumentException When the close signal cannot be named
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

        Hilos::$ac?->closePageSession($this->acceptKey);
        Hilos::$ac?->closeWsConnection($this->acceptKey);

        $closeDto = new WebSocketCloseSignalDTO(acceptKey: $this->acceptKey);
        Hilos::$sr->queueSignal(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::CONNECTION_CLOSE),
            new SignalName(SignalTypeConstants::CONNECTION_CLOSE),
            $closeDto,
        );
    }

    /**
     * Decides the address this connection is answered for: the peer, or what a trusted peer forwards.
     *
     * {@see AbstractClient::getClientIp()} reports an unavailable peer as a blank string,
     * and that blank is the end of the question rather than an invitation to read a
     * header: with no peer to check against the list, nothing can be trusted, so the
     * address is absent. A peer outside the configured networks is the address itself,
     * which is what a deployment facing the network directly always gets. Only a peer
     * inside them speaks for someone else, and only its X-Real-IP is read - a value that
     * has to parse as an address, since anything else is a misconfigured proxy rather
     * than a visitor.
     *
     * @param array<string, string> $headers HTTP headers from handshake request (lowercase header names)
     * @return ?string Effective client IP (IPv4 or IPv6), or null when the peer name is unavailable
     * @throws EnvException When the trusted-proxy list cannot be read from the environment
     * @throws SocketException When the peer name cannot be read
     */
    private function resolveClientIp(array $headers): ?string
    {
        $peerIp = $this->getClientIp();
        if ($peerIp === '') {
            return null;
        }

        if (!TrustedProxies::fromEnv()->trusts($peerIp)) {
            return $peerIp;
        }

        $forwarded = HttpHeaderHelper::get($headers, HttpConstants::HEADER_X_REAL_IP);

        return $forwarded !== null && filter_var($forwarded, FILTER_VALIDATE_IP) !== false ? $forwarded : $peerIp;
    }

    /**
     * Reports the connection's effective address to analytics, which is the one settled on the 101.
     *
     * The peer is deliberately not re-read here. Behind a trusted proxy every frame of
     * every visitor arrives from the same peer, so re-reading it would record a change of
     * address on the first frame of anyone the proxy speaks for - the effective address
     * belongs to the connection and was fixed when the connection was accepted.
     */
    private function trackCurrentClientIp(): void
    {
        if ($this->acceptKey === '') {
            return;
        }

        try {
            Hilos::$ac?->trackWsConnectionIpChange($this->acceptKey, $this->handshakeClientIp);
        } catch (Throwable) {
            // Ignore analytics tracking errors; they must not cost the connection a frame.
        }
    }
}
