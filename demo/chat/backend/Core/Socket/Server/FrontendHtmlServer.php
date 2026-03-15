<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Socket\Server;

use Demo\Chat\Core\Frontend\HtmlCache;
use Demo\Chat\Core\Frontend\HtmlResolver;
use Demo\Chat\Core\Socket\Client\FrontendHtmlClient;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\SocketException;
use Hilos\Socket\SocketOperation;

/**
 * HTTP server that serves prerendered frontend HTML with Accept-Language content negotiation.
 * Separate from status server (port 8090).
 */
final class FrontendHtmlServer extends AbstractServer
{
    /**
     * Initialize server with host, port, HTML resolver and cache.
     *
     * @param string $host Listen host
     * @param int $port Listen port
     * @param HtmlResolver $resolver Path resolver for HTML
     * @param HtmlCache $cache Cached prerendered HTML
     */
    public function __construct(
        string $host,
        int $port,
        private HtmlResolver $resolver,
        private HtmlCache $cache
    ) {
        parent::__construct($host, $port);
    }

    /**
     * Accept new client connection.
     *
     * @return ?ClientInterface New client or null
     * @throws SocketException If connection accept fails
     */
    public function acceptConnection(): ?ClientInterface
    {
        return parent::acceptConnection();
    }

    /**
     * Create client instance for accepted socket.
     *
     * @param resource $socket Client socket
     * @return ClientInterface Client instance
     */
    protected function onCreateClient($socket): ClientInterface
    {
        return new FrontendHtmlClient($socket, $this->resolver, $this->cache);
    }

    /**
     * Return server display name.
     *
     * @return string Server name
     */
    public function getServerName(): string
    {
        return 'Frontend HTML Server';
    }

    /**
     * Set forbidden status for admin routes.
     */
    protected function onStart(): void
    {
        $forbidden = 403;
        $this->resolver->setStatusOverride('admin', $forbidden);
        $this->resolver->setStatusOverride('admin/users', $forbidden);
        $this->resolver->setStatusOverride('admin/moderator', $forbidden);
        $this->resolver->setStatusOverride('admin/bots', $forbidden);
    }

    /**
     * Prepare server for graceful shutdown.
     */
    public function prepareShutdown(): void
    {
        parent::prepareShutdown();
    }

    /**
     * Check if server has no active clients and can shut down.
     *
     * @return bool True when no clients connected
     */
    public function isReadyToShutdown(): bool
    {
        return empty($this->clients);
    }

    /**
     * Stop server and close socket.
     *
     * @throws SocketException If socket close fails
     */
    public function stop(): void
    {
        if ($this->socket !== null) {
            socket_close($this->socket);
            $this->handleSocketError(SocketOperation::CLOSE);
            $this->socket = null;
        }
        $this->isRunning = false;
    }
}
