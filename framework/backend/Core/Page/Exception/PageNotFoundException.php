<?php

declare(strict_types=1);

namespace Hilos\Core\Page\Exception;

use Hilos\Core\Page\PageException;

/**
 * Exception thrown when a page cannot be found or created
 */
class PageNotFoundException extends PageException
{
    public function __construct(string $pageName, int $code = 0, ?\Throwable $previous = null)
    {
        $message = "Page not found: {$pageName}";
        parent::__construct($message, $code, $previous);
    }
}
