<?php

declare(strict_types=1);

namespace Hilos\Legal\Export;

use Hilos\Tables\Legal\HilosLegalAcceptanceTableRow;

/**
 * The one file of acceptance records being built, between the ticks that write its parts (HIL-1234).
 *
 * It lives in the memory of the legal agent's process only: a restarted agent finds the order still
 * preparing and builds the file again from its first line, under a new name.
 */
final class LegalAcceptancesExportBuild
{
    /** Acceptance records written so far. */
    public int $records = 0;

    /** Last row of the part written last; the next part continues after it. */
    public ?HilosLegalAcceptanceTableRow $after = null;

    /**
     * @param int $exportId Order being built
     * @param int $userId Administrator who placed it
     * @param string $storedName Name the finished file gets in the export directory
     * @param string $buildingName Name the file is written under until it is whole
     * @param string $where WHERE clause the order's filters and search resolved to at the start
     * @param list<mixed> $params Values bound by that clause
     */
    public function __construct(
        public readonly int $exportId,
        public readonly int $userId,
        public readonly string $storedName,
        public readonly string $buildingName,
        public readonly string $where,
        public readonly array $params,
    ) {
    }
}
