<?php

declare(strict_types=1);

namespace Hilos\Database\Migration;

/** One planned pair, including the exact paths retained when resuming a half-published pair. */
final readonly class FrameworkMigrationPublication
{
    /**
     * @param FrameworkMigrationPair $pair Verified source pair
     * @param int $index Project track number
     * @param string $upPath Final up path
     * @param string $downPath Final down path
     * @param bool $upPresent Whether the exact up half already exists
     * @param bool $downPresent Whether the exact down half already exists
     */
    public function __construct(
        public FrameworkMigrationPair $pair,
        public int $index,
        public string $upPath,
        public string $downPath,
        public bool $upPresent = false,
        public bool $downPresent = false,
    ) {
    }
}
