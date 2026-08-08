<?php

declare(strict_types=1);

namespace Hilos\Backup;

/**
 * RestoreEnvDecisionResult - the ENV guard's answer for one archive/target pair.
 *
 * A bare {@see RestoreEnvDecision} is not enough for the operator: a refusal must say
 * why, and the one refusal that `--force` can override must be distinguishable from the
 * ones it cannot. The result carries all three so the CLI prints the reason verbatim
 * and suggests `--force` only where it would actually change the outcome.
 */
final readonly class RestoreEnvDecisionResult
{
    /**
     * @param RestoreEnvDecision $decision What the guard allows
     * @param ?string $reason Operator-facing explanation; null when the restore is plainly allowed
     * @param bool $requiresForce Whether passing `--force` would turn this refusal into an allow
     */
    public function __construct(
        public RestoreEnvDecision $decision,
        public ?string $reason = null,
        public bool $requiresForce = false,
    ) {
    }
}
