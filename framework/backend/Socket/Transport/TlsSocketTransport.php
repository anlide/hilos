<?php

declare(strict_types=1);

namespace Hilos\Socket\Transport;

use Hilos\Socket\Exception\SocketTlsHandshakeException;
use Socket;

/**
 * TLS over a connection the sockets extension carries - either side of it: the one that
 * accepted the connection, or the one that dialed it.
 *
 * The socket is exported into a stream once, and the stream is where OpenSSL lives: the
 * handshake, then every read and write. The descriptor stays the same, so the event loop keeps
 * watching the socket it was given and needs to know nothing of this.
 *
 * A transport given a trust file verifies its peer: the peer must present a certificate signed
 * by an authority in that file, and the name in that certificate is what
 * {@see verifiedPeerName()} vouches for. Whether a refused handshake is named follows from the
 * same choice. On a mutually verified channel everyone refused was meant to be one of us - a
 * misconfigured node or a stranger - so the refusal is thrown with its address and reason. On a
 * public port, which verifies nobody, a refused handshake is ordinary noise and closes without
 * a word.
 *
 * Three answers differ from a bare socket, and the client relies on each of them:
 * - an empty read means only "nothing decrypted yet" - the socket turns readable for protocol
 *   bytes that carry no application data - and the end of the connection is told by the
 *   stream's own end-of-stream flag;
 * - a write may take zero bytes, which asks for the same bytes again on a later turn;
 * - bytes OpenSSL already decrypted wait inside the stream where the event loop cannot see
 *   them, and are collected by the read every server tick makes of every client.
 */
final class TlsSocketTransport implements SocketTransportInterface
{
    /** Prefix PHP puts in front of every warning the handshake call raises */
    private const string HANDSHAKE_WARNING_PREFIX = 'stream_socket_enable_crypto(): ';

    /** @var Socket Connected socket the stream was exported from */
    private Socket $socket;

    /** @var resource Stream over the same descriptor, carrying the TLS session */
    private $stream;

    /** @var int Which side of the handshake this end takes, a STREAM_CRYPTO_METHOD_TLS_* constant */
    private int $cryptoMethod;

    /** @var bool Whether the peer is verified against a trust file, which also names a refusal */
    private bool $verifiesPeer;

    /** @var ?string Peer address as "ip:port", null when the socket would not tell it */
    private ?string $peerAddress;

    /** @var bool Whether the handshake has completed */
    private bool $handshakeFinished = false;

    /** @var ?string Name in the verified peer certificate, read once the handshake has completed */
    private ?string $verifiedPeerName = null;

    /**
     * Exports the socket into a non-blocking stream presenting the given certificate.
     *
     * @param Socket $socket Connected, non-blocking socket
     * @param string $certificateFile PEM file holding the certificate chain and its private key
     * @param ?string $trustFile PEM file of the authorities a peer certificate must be signed by, null to verify nobody
     * @param int $cryptoMethod Side of the handshake this end takes, a STREAM_CRYPTO_METHOD_TLS_* constant
     */
    private function __construct(Socket $socket, string $certificateFile, ?string $trustFile, int $cryptoMethod)
    {
        $this->socket = $socket;
        $this->cryptoMethod = $cryptoMethod;
        $this->verifiesPeer = $trustFile !== null;
        // A peer that reset before this line leaves the socket without a name; the refusal is
        // still told, only without the address.
        // warning-suppressed: a false return leaves the address null, and the refusal text omits it
        $this->peerAddress = @socket_getpeername($socket, $ip, $port) ? "{$ip}:{$port}" : null;

        $this->stream = socket_export_stream($socket);
        stream_set_blocking($this->stream, false);
        stream_context_set_option($this->stream, 'ssl', 'local_cert', $certificateFile);

        if ($trustFile === null) {
            // PHP asks a client for its certificate unless told otherwise, and then checks it
            // against the system store - a client presenting one would be refused for it.
            stream_context_set_option($this->stream, 'ssl', 'verify_peer', false);
            return;
        }

        stream_context_set_option($this->stream, 'ssl', 'verify_peer', true);
        // The name is checked by whoever knows which name to expect, through verifiedPeerName():
        // a seed is dialed by address, before anyone knows the name of the node behind it.
        stream_context_set_option($this->stream, 'ssl', 'verify_peer_name', false);
        stream_context_set_option($this->stream, 'ssl', 'cafile', $trustFile);
        stream_context_set_option($this->stream, 'ssl', 'capture_peer_cert', true);
    }

    /**
     * The side that accepted the connection: the TLS server.
     *
     * @param Socket $socket Accepted, non-blocking client socket
     * @param string $certificateFile PEM file holding the certificate chain and its private key
     * @param ?string $trustFile PEM file of the authorities a client certificate must be signed by;
     *                           null on a public port, where no client certificate is asked for
     * @return self Transport of the accepted connection
     */
    public static function accepting(Socket $socket, string $certificateFile, ?string $trustFile = null): self
    {
        return new self($socket, $certificateFile, $trustFile, STREAM_CRYPTO_METHOD_TLS_SERVER);
    }

    /**
     * The side that dialed the connection: the TLS client, which always verifies whom it reached.
     *
     * @param Socket $socket Connected, non-blocking socket this end dialed
     * @param string $certificateFile PEM file holding the certificate chain and its private key
     * @param string $trustFile PEM file of the authorities the accepting side's certificate must be signed by
     * @return self Transport of the dialed connection
     */
    public static function dialing(Socket $socket, string $certificateFile, string $trustFile): self
    {
        return new self($socket, $certificateFile, $trustFile, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    }

    /**
     * Reads decrypted bytes; an empty answer is not the end of the connection on its own.
     *
     * @param int $length Most bytes to read in one call
     * @return string|false Decrypted bytes, '' when none are ready or the stream ended, false on a failed read
     */
    public function read(int $length): string|false
    {
        // A reset peer warns here and the read comes back empty; the client asks isEndOfStream()
        // about every empty read, and the stream's end flag is what closes the connection.
        // warning-suppressed: an empty result is followed by the end-of-stream check in the client
        return @fread($this->stream, $length);
    }

    /**
     * Writes through the TLS session; zero bytes taken is a request to retry, not a failure.
     *
     * @param string $data Bytes to send
     * @return int|false Bytes written, 0 when the same bytes must be offered again later, false on a failed write
     */
    public function write(string $data): int|false
    {
        // A reset peer warns here and the write takes 0 bytes; the next read finds the stream's
        // end and closes the connection.
        // warning-suppressed: the byte count is examined by the client, the next read tells a reset peer
        return @fwrite($this->stream, $data);
    }

    /**
     * Closes the socket, which also frees the stream exported from it.
     */
    public function close(): void
    {
        socket_close($this->socket);
    }

    /**
     * Reads the stream's end-of-stream flag, set by the read that met the peer's close.
     *
     * @return bool True when the peer has closed its side and nothing is left to read
     */
    public function isEndOfStream(): bool
    {
        return feof($this->stream);
    }

    /**
     * @return bool True until the TLS handshake has completed
     */
    public function needsHandshake(): bool
    {
        return !$this->handshakeFinished;
    }

    /**
     * Takes one step of the handshake, on the side this transport was created for.
     *
     * @return int|bool True once finished, 0 while it needs another turn, false when a peer nobody verifies was refused
     * @throws SocketTlsHandshakeException When the handshake with a verified peer is refused, by either end
     */
    public function advanceHandshake(): int|bool
    {
        // OpenSSL warns on every refused handshake - a peer speaking plain HTTP, a peer that does
        // not trust the certificate - and the warning is the only place its reason is written.
        // It is caught rather than muted: a verified channel names the refusal with it.
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning ??= $message;
            return true;
        });

        try {
            $step = stream_socket_enable_crypto($this->stream, true, $this->cryptoMethod);
        } finally {
            restore_error_handler();
        }

        if ($step === true) {
            $this->handshakeFinished = true;
            $this->verifiedPeerName = $this->verifiesPeer ? $this->readPeerCertificateName() : null;
        }

        if ($step === false && $this->verifiesPeer) {
            $reason = $this->describeRefusal($warning);
            throw new SocketTlsHandshakeException($this->peerAddress === null ? $reason : "{$this->peerAddress}: {$reason}");
        }

        return $step;
    }

    /**
     * Name in the certificate the peer presented, once the handshake has checked its chain.
     *
     * @return ?string Common name of the verified peer certificate; null until the handshake has
     *                 completed, and always null on a transport that verifies nobody
     */
    public function verifiedPeerName(): ?string
    {
        return $this->verifiedPeerName;
    }

    /**
     * Reads the common name out of the certificate the handshake captured.
     *
     * @return ?string Common name, null when the certificate carries no single one
     */
    private function readPeerCertificateName(): ?string
    {
        $certificate = stream_context_get_options($this->stream)['ssl']['peer_certificate'] ?? null;
        if ($certificate === null) {
            return null;
        }

        $parsed = openssl_x509_parse($certificate);
        if ($parsed === false) {
            return null;
        }

        $name = $parsed['subject']['CN'] ?? null;

        return is_string($name) ? $name : null;
    }

    /**
     * Turns the warning of a refused handshake into the reason a log line carries.
     *
     * @param ?string $warning First warning the handshake call raised, null when it raised none
     * @return string OpenSSL's reason, without the name of the PHP function in front of it
     */
    private function describeRefusal(?string $warning): string
    {
        if ($warning === null) {
            return 'the handshake was refused without a reason';
        }

        $reason = str_starts_with($warning, self::HANDSHAKE_WARNING_PREFIX)
            ? substr($warning, strlen(self::HANDSHAKE_WARNING_PREFIX))
            : $warning;

        // OpenSSL lists its errors one per line; a log line keeps them on one.
        return str_replace("\n", ' ', $reason);
    }
}
