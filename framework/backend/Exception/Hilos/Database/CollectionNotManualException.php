<?php

namespace Hilos\Exception\Hilos\Database;

use Hilos\Exception\Database\CollectionNotManualException as BaseCollectionNotManualException;

/**
 * Exception: operation requires manual collection (or item has no ID).
 */
class CollectionNotManualException extends BaseCollectionNotManualException
{
}
