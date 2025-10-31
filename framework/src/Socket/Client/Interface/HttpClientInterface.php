<?php

declare(strict_types=1);

namespace Hilos\Socket\Client\Interface;

use Hilos\Socket\Client\ClientInterface;

/**
 * HttpClientInterface - Interface for HTTP client implementations
 *
 * Extends ClientInterface with HTTP-specific methods.
 */
interface HttpClientInterface extends ClientInterface
{
    /**
     * Set router for handling HTTP requests
     *
     * @param \Hilos\API\Router\HttpRouter $router Router instance
     */
    public function setRouter(\Hilos\API\Router\HttpRouter $router): void;
}

