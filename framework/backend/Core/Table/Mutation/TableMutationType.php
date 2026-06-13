<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Mutation;

/**
 * Mutation type for table row changes.
 */
enum TableMutationType: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
    case Clear = 'clear';
}
