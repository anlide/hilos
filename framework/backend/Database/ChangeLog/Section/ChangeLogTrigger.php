<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog\Section;

/** The source-file identity of a service trigger; SQL bodies are never exposed. */
final readonly class ChangeLogTrigger
{
    public function __construct(
        public string $name,
        public string $event,
        public string $table,
        public int $validFromMigration,
    ) {
    }
}
