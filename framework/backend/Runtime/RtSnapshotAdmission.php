<?php

declare(strict_types=1);

namespace Hilos\Runtime;

/** Process-private row admissions across sources; independent of ownership-membership invalidation. */
final class RtSnapshotAdmission
{
    private static int $sequence = 0;

    /** @return int Finite row cutoff captured before a portioned source plan starts */
    public static function current(): int
    {
        return self::$sequence;
    }

    /** @return int New exact row incarnation; undo advances rather than restoring an older value */
    public static function advance(): int
    {
        return ++self::$sequence;
    }
}
