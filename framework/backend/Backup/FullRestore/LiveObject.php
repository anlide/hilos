<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

/** Physical metadata, private to one read-only restore preparation. */
final readonly class LiveObject
{
    /**
     * @param string $type Server object kind, including kinds the planner cannot replace
     * @param string $createSql Server's complete declaration
     * @param ?string $rowFormat Effective physical row format, when the object is a table
     */
    public function __construct(public string $type, public string $createSql, public ?string $rowFormat = null)
    {
    }
}
