<?php

declare(strict_types=1);

namespace Hilos\Exception\Page;

use Hilos\Exception\PageException;

/**
 * Exception thrown when a page signal router cannot be created for an agent.
 */
class PageSignalRouterNotFoundException extends PageException
{
    public function __construct(string $agentClass, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct("Page signal router not found for agent: {$agentClass}", $code, $previous);
    }
}
