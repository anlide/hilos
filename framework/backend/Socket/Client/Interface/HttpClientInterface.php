<?php

declare(strict_types=1);

namespace Hilos\Socket\Client\Interface;

use Hilos\API\Router\HttpRouter;
use Hilos\Socket\Client\ClientInterface;

/**
 * HttpClientInterface - Interface for HTTP client implementations.
 *
 * Extends ClientInterface with HTTP-specific methods.
 */
interface HttpClientInterface extends ClientInterface
{
    /**
     * Set router for handling HTTP requests.
     *
     * @param HttpRouter $router Router instance for handling HTTP requests
     */
    public function setRouter(HttpRouter $router): void;
}
