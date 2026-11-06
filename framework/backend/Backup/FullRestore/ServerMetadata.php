<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

/** All visible schemas of one physical server, never merged by schema name across servers. */
final readonly class ServerMetadata
{
    public const string CREATE_SQL = 'CREATE_SQL';

    /**
     * Catalog rows stay array-shaped at this low-level database boundary.
     * Direct global grants are a sufficient visibility proof; role-only grants are not inferred.
     *
     * @param bool $completeVisibility Whether all schemas and dependent definitions are visible
     * @param list<string> $grants Actual grants used for the visibility verdict
     * @param list<array<string, mixed>> $foreignKeys All incoming/outgoing FK columns and both actions
     * @param list<array<string, mixed>> $views Every visible view and its definition
     * @param list<array<string, mixed>> $triggers Every visible trigger and its body
     * @param list<array<string, mixed>> $routines Every visible routine and its body
     * @param list<array<string, mixed>> $events Every visible event and its body
     * @param list<array<string, mixed>> $functions Native UDF catalog, whose implementations are opaque
     */
    public function __construct(
        public bool $completeVisibility,
        public array $grants,
        public array $foreignKeys,
        public array $views,
        public array $triggers,
        public array $routines,
        public array $events,
        public array $functions = [],
    ) {
    }
}
