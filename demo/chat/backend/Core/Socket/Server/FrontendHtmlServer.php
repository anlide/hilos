<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Socket\Server;

use Demo\Chat\Core\Frontend\HtmlCache;
use Demo\Chat\Core\Frontend\HtmlResolver;
use Demo\Chat\Core\Socket\Client\FrontendHtmlClient;
use Hilos\Constants\HttpConstants;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\SocketException;

/**
 * HTTP server that serves prerendered frontend HTML with Accept-Language content negotiation.
 * Separate from status server (port 8090).
 */
class FrontendHtmlServer extends AbstractServer
{
    public function __construct(
        string $host,
        int $port,
        private HtmlResolver $resolver,
        private HtmlCache $cache
    ) {
        parent::__construct($host, $port);
    }

    public function acceptConnection(): ?ClientInterface
    {
        return parent::acceptConnection();
    }

    protected function onCreateClient($socket): ClientInterface
    {
        return new FrontendHtmlClient($socket, $this->resolver, $this->cache);
    }

    public function getServerName(): string
    {
        return 'Frontend HTML Server';
    }

    protected function onStart(): void
    {
        $forbidden = 403;
        $this->resolver->setStatusOverride('admin', $forbidden);
        $this->resolver->setStatusOverride('admin/users', $forbidden);
        $this->resolver->setStatusOverride('admin/moderator', $forbidden);
        $this->resolver->setStatusOverride('admin/bots', $forbidden);
    }

    public function prepareShutdown(): void
    {
        parent::prepareShutdown();
    }

    public function isReadyToShutdown(): bool
    {
        return empty($this->clients);
    }

    /**
     * @throws SocketException
     */
    public function stop(): void
    {
        if ($this->socket !== null) {
            socket_close($this->socket);
            $this->handleSocketError(\Hilos\Socket\SocketOperation::CLOSE);
            $this->socket = null;
        }
        $this->isRunning = false;
    }
}
