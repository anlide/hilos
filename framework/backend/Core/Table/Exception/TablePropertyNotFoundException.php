<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Exception;

use Hilos\HilosException;

/**
 * Exception: accessing a non-existent property on TableDefinition.
 */
class TablePropertyNotFoundException extends HilosException
{
    /**
     * Creates exception for non-existent property access.
     *
     * @param string $property Property name that was accessed
     */
    public function __construct(string $property)
    {
        parent::__construct("Property [{$property}] does not exist on TableDefinition");
    }
}
