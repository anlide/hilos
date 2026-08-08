<?php

declare(strict_types=1);

namespace Hilos\Backup;

/**
 * RestoreEnvDecision - what the ENV guard matrix allows for one restore request.
 *
 * The decision travels from the CLI preflight into the restore child's argv, so the
 * engine can act on what was decided without re-deriving it; hence the backed values.
 */
enum RestoreEnvDecision: string
{
    /** The restore may proceed as-is. */
    case ALLOW = 'allow';

    /**
     * The restore may proceed only through an anonymization pass: the archive carries
     * production data and the target is not production.
     */
    case REQUIRE_ANONYMIZATION = 'require-anonymization';

    /** The restore must not happen; {@see RestoreEnvDecisionResult::$reason} says why. */
    case REFUSE = 'refuse';
}
