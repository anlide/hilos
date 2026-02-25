<?php

namespace Hilos\Table\Exception;

use Hilos\Core\Table\Exception\TableNotFoundException as BaseTableNotFoundException;

/**
 * Exception: table not found in Hilos::$table.
 */
class TableNotFoundException extends BaseTableNotFoundException
{
}
