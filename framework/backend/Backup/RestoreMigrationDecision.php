<?php

declare(strict_types=1);

namespace Hilos\Backup;

/**
 * RestoreMigrationDecision - what the migration-index gate allows for one archive.
 *
 * Two values, not three: "migrate forward" is not a verdict of its own. The engine
 * always runs the migrations after the import, and a run whose levels already match
 * simply applies none - so an archive older than the code is an ordinary allow, and
 * the operator learns what will be applied from
 * {@see RestoreMigrationDecisionResult::$gaps}, not from the decision.
 */
enum RestoreMigrationDecision: string
{
    /** The restore may proceed; any missing migrations are applied after the import. */
    case ALLOW = 'allow';

    /**
     * The restore must not happen: the archive is ahead of the code and there is no
     * downgrade path. {@see RestoreMigrationDecisionResult::$reason} says which
     * connections disagree and by how much.
     */
    case REFUSE = 'refuse';
}
