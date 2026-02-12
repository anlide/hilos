<?php

declare(strict_types=1);

namespace Hilos\Core\Table;

/**
 * Table data source type.
 *
 * - Entity: backed by IdeaCollection (e.g. users, events).
 * - Sql: backed by raw SQL (structure only; no implementation yet).
 * - Other: other sources (e.g. filesystem, backups list).
 */
enum TableType: string
{
    case Entity = 'entity';
    case Sql = 'sql';
    case Other = 'other';
}
