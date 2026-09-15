<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * What a protected-mode snapshot means to the caller whose drive reply was lost.
 */
enum ProtectedModeReAskVerdict: string
{
    case TAKEN = 'taken';

    case UNDER_WAY = 'underWay';

    case NOT_TAKEN = 'notTaken';

    /** The caller's verdict when the re-ask itself got no answer; {@see read()} never returns it. */
    case UNKNOWN = 'unknown';

    /**
     * @param array<string, mixed> $snapshot Reply payload of protected-mode:inspect
     * @param ?string $operation Operation the caller drove, or null when it drove none
     * @param list<string> $takenPhases Phases that mean the drive happened
     * @return self Verdict read from the snapshot
     */
    public static function read(array $snapshot, ?string $operation, array $takenPhases): self
    {
        if (($snapshot[ProtectedModeCommandConstants::FIELD_RT_MOUNTED] ?? false) !== true) {
            return self::NOT_TAKEN;
        }

        if ($operation !== null
            && ($snapshot[ProtectedModeCommandConstants::FIELD_OPERATION] ?? null) !== $operation
        ) {
            return self::NOT_TAKEN;
        }

        $phase = $snapshot[ProtectedModeCommandConstants::FIELD_PHASE] ?? null;
        if (in_array($phase, $takenPhases, true)) {
            return self::TAKEN;
        }

        if ($phase === ProtectedModeRuntime::PHASE_ACTIVATING) {
            return self::UNDER_WAY;
        }

        return self::NOT_TAKEN;
    }
}
