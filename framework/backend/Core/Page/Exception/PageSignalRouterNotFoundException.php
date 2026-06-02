<?php

declare(strict_types=1);

namespace Hilos\Core\Page\Exception;

use Hilos\Core\Page\PageException;
use Throwable;

/**
 * Exception thrown when a page signal router cannot be created for an agent.
 */
class PageSignalRouterNotFoundException extends PageException
{
    /**
     * Creates exception when page signal router cannot be created for an agent.
     *
     * @param class-string $agentClass Agent class name
     * @param int $code Error code
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(string $agentClass, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct("Page signal router not found for agent: {$agentClass}", $code, $previous);
    }
}
