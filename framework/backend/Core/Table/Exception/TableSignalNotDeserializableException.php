<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Exception;

use Hilos\HilosException;

/**
 * Exception: server-to-client signal data does not support deserialization via fromArray().
 */
class TableSignalNotDeserializableException extends HilosException
{
    public function __construct(string $className)
    {
        parent::__construct("{$className}::fromArray() is not supported (server-to-client only)");
    }
}
