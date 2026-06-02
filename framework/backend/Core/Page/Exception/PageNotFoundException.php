<?php

declare(strict_types=1);

namespace Hilos\Core\Page\Exception;

use Hilos\Core\Page\PageException;
use Throwable;

/**
 * Exception thrown when a page cannot be found or created.
 */
class PageNotFoundException extends PageException
{
    /**
     * Creates exception with page name, code and optional previous exception.
     *
     * @param string $pageName Page name that was not found
     * @param int $code Error code
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(string $pageName, int $code = 0, ?Throwable $previous = null)
    {
        $message = "Page not found: {$pageName}";
        parent::__construct($message, $code, $previous);
    }
}
