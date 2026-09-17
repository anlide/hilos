<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

use Hilos\Socket\Client\AbstractClient;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\Transport\SocketTransportInterface;
use Hilos\Socket\Transport\TlsSocketTransport;
use Socket;

/**
 * AbstractTlsServer - a server whose connections speak TLS.
 *
 * Accepting does not change: the connection arrives through the same socket_accept() as on any
 * server, and the event loop watches the same socket. What differs is the transport the client is
 * created with, and this class owns the one thing that transport needs - the certificate. A
 * concrete server hands {@see createTransport()} to its client's constructor in onCreateClient();
 * the client does the rest through the transport seam of {@see AbstractClient}.
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

    /**
     * Create server with host, port and the certificate it presents.
     *
     * @param string $host Host to bind
     * @param int $port Port to bind
     * @param string $certificateFile PEM file holding the certificate chain and its private key
     */
    public function __construct(string $host, int $port, string $certificateFile)
    {
        parent::__construct($host, $port);

        $this->certificateFile = $certificateFile;
    }

    /**
     * Wraps an accepted connection in a TLS session presenting this server's certificate.
     *
     * @param Socket $socket Accepted client socket, as onCreateClient() receives it
     * @return SocketTransportInterface Transport the client reads and writes through
     */
    protected function createTransport(Socket $socket): SocketTransportInterface
    {
        return new TlsSocketTransport($socket, $this->certificateFile);
    }
}
