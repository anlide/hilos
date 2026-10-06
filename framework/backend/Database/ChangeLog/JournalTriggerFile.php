<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog;

/** One canonical service trigger file, including its migration header. */
final readonly class JournalTriggerFile
{
    /**
     * @param string $name Trigger and file stem
     * @param string $body One CREATE TRIGGER or DROP TRIGGER statement
     * @param int $migrationIndex First migration for which this body is valid
     * @param bool $tombstone Whether the body removes an obsolete trigger
     */
    public function __construct(
        public string $name,
        public string $body,
        public int $migrationIndex,
        public bool $tombstone,
    ) {
    }

    /**
     * @return string Canonical file content
     */
    public function content(): string
    {
        return "-- valid from migration #{$this->migrationIndex}\n{$this->body}\n";
    }
}
