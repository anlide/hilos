<?php

declare(strict_types=1);

namespace Hilos\Database\Migration;

use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;

/** One indivisible framework DDL pair, or a table owned by migration bootstrap. */
final readonly class FrameworkSchemaUnit
{
    /**
     * Connection describes where the tables live; all stub SQL runs in the primary migration track.
     * Bootstrap-owned units have no stub paths: Migration::initialize() creates them.
     *
     * @param string $key Stable schema unit key
     * @param list<string> $tables Exact SQL table names created together
     * @param ?string $upPath Up stub path, absent for bootstrap-owned units
     * @param ?string $downPath Down stub path, absent for bootstrap-owned units
     * @param list<string> $dependencies Unit keys that must run first
     * @param int $connection Connection holding the tables, not a migration execution target
     * @param bool $bootstrapOwned Whether migration bootstrap owns the DDL
     * @throws DatabaseException When a unit has no identity or tables, or its paths contradict its ownership
     */
    public function __construct(
        public string $key,
        public array $tables,
        public ?string $upPath,
        public ?string $downPath,
        public array $dependencies = [],
        public int $connection = DatabaseConnectionDefaults::PRIMARY_INDEX,
        public bool $bootstrapOwned = false,
    ) {
        if ($key === '' || $tables === [] || in_array('', $tables, true)) {
            throw new DatabaseException('A framework schema unit needs a key and exact table names');
        }
        if (
            ($bootstrapOwned && ($upPath !== null || $downPath !== null))
            || (!$bootstrapOwned && ($upPath === null || $downPath === null || $upPath === '' || $downPath === ''))
        ) {
            throw new DatabaseException("Framework schema unit {$key} has paths inconsistent with bootstrap ownership");
        }
    }
}
