<?php

declare(strict_types=1);

namespace Hilos\Socket\Transport;

use Socket;

/**
 * The server side of TLS over a connection the sockets extension accepted.
 *
 * The socket is exported into a stream once, and the stream is where OpenSSL lives: the
 * handshake, then every read and write. The descriptor stays the same, so the event loop keeps
 * watching the socket it was given and needs to know nothing of this.
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
    /** @var Socket Accepted socket the stream was exported from */
    private Socket $socket;

    /** @var resource Stream over the same descriptor, carrying the TLS session */
    private $stream;

    /** @var bool Whether the handshake has completed */
    private bool $handshakeFinished = false;

    /**
     * Exports the accepted socket into a non-blocking stream presenting the given certificate.
     *
     * @param Socket $socket Accepted, non-blocking client socket
     * @param string $certificateFile PEM file holding the certificate chain and its private key
     */
    public function __construct(Socket $socket, string $certificateFile)
    {
        $this->socket = $socket;
        $this->stream = socket_export_stream($socket);
        stream_set_blocking($this->stream, false);
        stream_context_set_option($this->stream, 'ssl', 'local_cert', $certificateFile);
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
     * Takes one step of the server handshake.
     *
     * @return int|bool True once finished, 0 while it needs another turn, false when the peer was refused
     */
    public function advanceHandshake(): int|bool
    {
        // OpenSSL warns on every refused handshake - a peer speaking plain HTTP, a peer that does
        // not trust the certificate - which is the ordinary traffic of an open port.
        // warning-suppressed: the int|bool result is examined below and by the client, false closes the connection
        $step = @stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_SERVER);

        if ($step === true) {
            $this->handshakeFinished = true;
        }

        return $step;
    }
}
