<?php

declare(strict_types=1);

namespace Hilos\Database\Settings\Library;

/**
 * A settings write went through: the trust restriction it recorded, if any (HIL-1909, HIL-1908).
 *
 * Returned by the doors of {@see SettingsLibraryAgent}, so the answer that follows the write can
 * name the restriction it is waiting on: a shorter trust term waits for the owners to cut their
 * trusts to it, and the second factor turned off while forgetting the trusted browsers is on waits
 * for the owners to forget the trusts under it.
 */
final readonly class SettingSaved
{
    /**
     * Creates the record of one saved write.
     *
     * @param ?int $restrictionId Trust restriction recorded in the same commit, or null when the write recorded none
     */
    public function __construct(
        public ?int $restrictionId,
    ) {
    }
}
