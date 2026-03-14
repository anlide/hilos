<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Exception;

use Hilos\HilosException;

/**
 * Exception: table definition constructed without data source and createDataSource() not overridden.
 */
class TableDataSourceNotProvidedException extends HilosException
{
    /**
     * Creates exception for missing data source.
     */
    public function __construct()
    {
        parent::__construct('Data source must be provided via constructor or override createDataSource()');
    }
}
