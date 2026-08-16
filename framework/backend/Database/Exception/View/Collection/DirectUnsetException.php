<?php

declare(strict_types=1);

namespace Hilos\Database\Exception\View\Collection;

/**
 * Exception: attempt to directly unset an item in collection.
 */
class DirectUnsetException extends CollectionException
{
    /**
     * Creates exception for an attempt to unset an item outside actions.
     */
    public function __construct()
    {
        parent::__construct('Cannot directly unset items in collection. Use actions for modifications.');
    }
}
