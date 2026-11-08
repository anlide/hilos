<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

use Hilos\Core\EventLoop\Exception\NativeEventUnavailableException;
use Hilos\Socket\Client\AbstractClient;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\Exception\SocketTlsHandshakeException;
use Hilos\Socket\Transport\SocketTransportInterface;
use Hilos\Socket\Transport\TlsSocketTransport;
use Hilos\Socket\Transport\TlsContext;
use Hilos\Environment\Exception\EnvException;
use Hilos\HilosException;
use Hilos\Socket\SocketException;
use Socket;

/**
 * TLS listener with certificate and trust contexts prepared before the event loop runs.
 * Accepted clients bind native SSL to the same loop through the server's attacher.
 * Mutual TLS requires a client certificate; public ports ask for none. Refusals of
 * verified peers reach the client's contained read guard, while public noise closes quietly.
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

    private ?TlsContext $acceptingContext = null;

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
     * Starts only from startup-prepared material; this entry may run on the master loop.
     *
     * @return bool Whether the listener started
     * @throws SocketException When the listener cannot start or TLS was not prepared
     * @throws HilosException When the concrete start hook refuses startup
     */
    public function start(): bool
    {
        if ($this->acceptingContext === null) {
            throw new SocketTlsHandshakeException('prepare TLS contexts before starting the event loop');
        }
        return parent::start();
    }

    /**
     * Prepares this listener's context during boot, before run() and any native callbacks.
     * Repeated preparation reuses the immutable certificate and trust configuration.
     *
     * @throws EnvException When the TLS input budget is invalid
     * @throws NativeEventUnavailableException When the required native TLS API is unavailable
     */
    public function prepareTlsContexts(): void
    {
        $this->acceptingContext ??= TlsContext::accepting($this->certificateFile, $this->trustFile);
    }

    /**
     * Wraps an accepted connection in a TLS session presenting this server's certificate.
     *
     * @param Socket $socket Accepted client socket, as onCreateClient() receives it
     * @return SocketTransportInterface Transport the client reads and writes through
     * @throws SocketTlsHandshakeException When called before TLS startup preparation
     */
    protected function createTransport(Socket $socket): SocketTransportInterface
    {
        if ($this->acceptingContext === null) {
            throw new SocketTlsHandshakeException('the TLS server has not prepared its certificates');
        }
        return TlsSocketTransport::accepting($socket, $this->acceptingContext);
    }
}
