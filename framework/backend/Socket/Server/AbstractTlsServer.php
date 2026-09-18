<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

use Hilos\Socket\Client\AbstractClient;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\Exception\SocketTlsHandshakeException;
use Hilos\Socket\Transport\SocketTransportInterface;
use Hilos\Socket\Transport\TlsSocketTransport;
use Socket;

/**
 * AbstractTlsServer - a server whose connections speak TLS.
 *
 * Accepting does not change: the connection arrives through the same socket_accept() as on any
 * server, and the event loop watches the same socket. What differs is the transport the client is
 * created with, and this class owns what that transport needs - the certificate, and the trust
 * file when there is one. A concrete server hands {@see createTransport()} to its client's
 * constructor in onCreateClient(); the client does the rest through the transport seam of
 * {@see AbstractClient}.
 *
 * With a trust file every connection must present a certificate signed by one of its
 * authorities, and a refused handshake is thrown as {@see SocketTlsHandshakeException} out of
 * the client's read - the tick's containment of a failing client names it and drops that one
 * connection. Without one the port is public: no client certificate is asked for, and a refused
 * handshake closes without a word.
 *
 * There is no plain mode: a server of this kind that took a bare connection would be checking
 * something other than the transport it was built for.
 *
 * @template TClient of ClientInterface
 * @extends AbstractServer<TClient>
 */
abstract class AbstractTlsServer extends AbstractServer
{
    /** @var string PEM file holding the certificate chain and private key this server presents */
    private string $certificateFile;

    /** @var ?string PEM file of the authorities a client certificate must be signed by, null on a public port */
    private ?string $trustFile;

    /**
     * Create server with host, port, the certificate it presents and the authorities it trusts.
     *
     * @param string $host Host to bind
     * @param int $port Port to bind
     * @param string $certificateFile PEM file holding the certificate chain and its private key
     * @param ?string $trustFile PEM file of the authorities every client certificate must be signed by;
     *                           null for a public port, which asks no client for a certificate
     */
    public function __construct(string $host, int $port, string $certificateFile, ?string $trustFile = null)
    {
        parent::__construct($host, $port);

        $this->certificateFile = $certificateFile;
        $this->trustFile = $trustFile;
    }

    /**
     * Wraps an accepted connection in a TLS session presenting this server's certificate.
     *
     * @param Socket $socket Accepted client socket, as onCreateClient() receives it
     * @return SocketTransportInterface Transport the client reads and writes through
     */
    protected function createTransport(Socket $socket): SocketTransportInterface
    {
        return TlsSocketTransport::accepting($socket, $this->certificateFile, $this->trustFile);
    }
}
