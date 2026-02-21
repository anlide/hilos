<?php

namespace Hilos\Database\Exception\View;

use Hilos\Database\Exception\CollectionNotManualException as BaseCollectionNotManualException;

/**
 * Exception: operation requires manual collection (or item has no ID).
 */
class CollectionNotManualException extends BaseCollectionNotManualException
{
}
