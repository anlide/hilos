<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

/**
 * Whole lines of a ready journal file read from one offset ({@see AnalyticsJournalDirectory::readPortion()}).
 */
final readonly class AnalyticsJournalPortion
{
    /**
     * @param list<string> $lines Whole lines, without their line breaks
     * @param int $nextOffset Byte offset right after the last line read
     * @param bool $complete Whether the file ends with these lines
     * @param int $passedOver Lines too long to send to the writer
     */
    public function __construct(
        public array $lines,
        public int $nextOffset,
        public bool $complete,
        public int $passedOver = 0,
    ) {
    }
}
