<?php

declare(strict_types=1);

namespace Hilos\Backup;

/**
 * RestoreUiDecisionResult - the UI gate's answer for one restore request.
 *
 * Shaped like {@see RestoreEnvDecisionResult}, and for the same reason: a refusal that
 * cannot say why is useless to the operator, who is looking at one row and needs to know
 * what about it stops the restore. There is no third outcome to name here - the ENV
 * matrix's "allowed after anonymization" is a decision the engine acts on, not something
 * the button can ask the admin for - so the verdict is a flag and the sentence that goes
 * with it.
 *
 * The two travel together: {@see refuse()} is the only way to build a refusal and it
 * demands the reason, so `allowed === false` and `reason !== null` always agree.
 */
final readonly class RestoreUiDecisionResult
{
    /**
     * @param bool $allowed Whether the restore may be requested from the page
     * @param ?string $reason Operator-facing explanation of the refusal; null when allowed
     */
    private function __construct(
        public bool $allowed,
        public ?string $reason = null,
    ) {
    }

    /**
     * @return self Verdict allowing the restore
     */
    public static function allow(): self
    {
        return new self(true);
    }

    /**
     * @param string $reason Operator-facing explanation, shown as the action's error
     * @return self Verdict refusing the restore with that reason
     */
    public static function refuse(string $reason): self
    {
        return new self(false, $reason);
    }
}
