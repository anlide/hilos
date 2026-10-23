<?php

declare(strict_types=1);

namespace Hilos\Database\Exception;

use Hilos\Database\DatabaseException;

/** Refuses a whole-set read of a database collection that does not cache rows. */
class UnboundedCollectionReadException extends DatabaseException
{
    /** @param string $collectionKey Collection whose reads need an explicit bound */
    public function __construct(string $collectionKey)
    {
        parent::__construct(
            "Database collection '{$collectionKey}' does not cache rows; use a bounded query with a positive limit"
        );
    }
}
