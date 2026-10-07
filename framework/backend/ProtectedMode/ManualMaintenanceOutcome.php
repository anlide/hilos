<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

/**
 * Result of one index-agent manual maintenance request after its protected-mode wait.
 *
 * A timed-out request is unconfirmed: the daemon may still apply it after this agent
 * has forgotten the waiter. The clear pass exists only on a confirmed mint result.
 */
final readonly class ManualMaintenanceOutcome
{
    public const string SUCCEEDED = 'succeeded';
    public const string REFUSED = 'refused';
    public const string UNCONFIRMED = 'unconfirmed';

    private function __construct(
        public string $status,
        public ?string $phase,
        public ?string $pass,
        public ?string $reason,
    ) {
    }

    /**
     * @param string $phase Observed completion phase
     * @param ?string $pass Clear pass returned only after its hash appears on the row
     * @return self Confirmed result
     */
    public static function succeeded(string $phase, ?string $pass = null): self
    {
        return new self(self::SUCCEEDED, $phase, $pass, null);
    }

    /**
     * @param string $reason Reason the agent or protected-mode core refused the request
     * @return self Refusal result
     */
    public static function refused(string $reason): self
    {
        return new self(self::REFUSED, null, null, $reason);
    }

    /**
     * @param string $reason What the agent could not confirm before its wait expired
     * @param ?string $phase Last phase observed on the local runtime row
     * @return self Unconfirmed result
     */
    public static function unconfirmed(string $reason, ?string $phase): self
    {
        return new self(self::UNCONFIRMED, $phase, null, $reason);
    }
}
