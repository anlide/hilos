<?php

declare(strict_types=1);

namespace Hilos\Runtime\Exception\Collection;

/**
 * Exception: Attempt to directly unset an item in RtCollection.
 */
class RtCollectionDirectUnsetException extends RtCollectionException
{
    /**
     * Creates exception for an attempt to unset an item outside actions.
     */
    public function __construct()
    {
        parent::__construct('Cannot directly unset items in collection. Use actions for modifications.');
    }
}
