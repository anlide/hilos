<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog\Section;

use Hilos\Database\Schema\JournalColumnMode;

/** One live column and, when journaled, its placement. */
final readonly class ChangeLogColumn
{
    /**
     * @param string $name Live column name
     * @param string $sqlType information_schema DATA_TYPE
     * @param ?JournalColumnMode $mode Null for a table outside the journal
     * @param ?string $storage inline, long, fact, ignored, or null for a record key or untracked table
     * @param ?string $noiseReason Why a noise column is omitted
     */
    public function __construct(
        public string $name,
        public string $sqlType,
        public ?JournalColumnMode $mode,
        public ?string $storage,
        public ?string $noiseReason,
    ) {
    }
}
