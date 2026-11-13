<?php

declare(strict_types=1);

namespace Hilos\Database\Settings\Library;

/**
 * A settings write went through: its moment and the trust restriction it recorded, if any (HIL-1909).
 *
 * Returned by the doors of {@see SettingsLibraryAgent} in place of a bare moment, so the answer
 * that follows the write can name the restriction it is waiting on: the second factor turned off
 * while forgetting the trusted browsers is on waits for the owners to forget the trusts under it.
 */
final readonly class SettingSaved
{
    /**
     * Creates the record of one saved write.
     *
     * @param int $savedAt Moment the write was saved, the one its restriction was recorded with (epoch milliseconds)
     * @param ?int $restrictionId Trust restriction recorded in the same commit, or null when the write recorded none
     */
    public function __construct(
        public int $savedAt,
        public ?int $restrictionId,
    ) {
    }
}
