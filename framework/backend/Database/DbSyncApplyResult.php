<?php

declare(strict_types=1);

namespace Hilos\Database;

use Hilos\Core\Source\SourceChange;
use Hilos\Database\Object\Item\AccountMergeFence as ObjectAccountMergeFence;

/** A valid uncached fact is accepted even though it changed no held business Object. */
final readonly class DbSyncApplyResult
{
    /**
     * @param bool $accepted Whether consumers may receive the fact
     * @param bool $memoryChanged Whether a business Object or membership changed
     * @param ?ObjectAccountMergeFence $fence Local progress to advance after successful application
     * @param ?SourceChange $sourceChange Applied snapshot and previous held values for browser consumers
     */
    public function __construct(
        public bool $accepted,
        public bool $memoryChanged = false,
        public ?ObjectAccountMergeFence $fence = null,
        public ?SourceChange $sourceChange = null,
    ) {
    }
}
