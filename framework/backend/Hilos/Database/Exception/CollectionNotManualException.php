<?php

namespace Hilos\Hilos\Database\Exception;

use Hilos\Database\Exception\CollectionNotManualException as BaseCollectionNotManualException;

/**
 * Exception: operation requires manual collection (or item has no ID).
 */
class CollectionNotManualException extends BaseCollectionNotManualException
{
}
