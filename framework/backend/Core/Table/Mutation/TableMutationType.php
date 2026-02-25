<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Mutation;

/**
 * Mutation type for table change log entries.
 */
enum TableMutationType: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
}
