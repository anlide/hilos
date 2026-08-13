<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\ProtectedMode\ClusterProtectedMode;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * ProtectedModeVerifySignalData - initiator -> daemon payload for PROTECTED_MODE_VERIFY.
 *
 * The initiator sends it the moment its destructive operation ends, asking for the verification
 * window rather than for the lift: the system stays closed to everyone, and a hand-picked circle
 * is let in by pass to confirm it really came back
 * ({@see ProtectedModeRuntime::PHASE_VERIFYING}).
 *
 * It authorizes exactly like {@see ProtectedModeDisableSignalData} does, and carries the same
 * identity for the same reason: on a single node the recorded initiator agent is the whole
 * authorization, while a cluster compares initiator node ids instead
 * ({@see ClusterProtectedMode::onVerify()}) and ignores these fields.
 */
final class ProtectedModeVerifySignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: the agent type asking for the verification window. */
    public const string initiatorAgentType = 'initiatorAgentType';

    /** Payload key: the agent index asking for the verification window. */
    public const string initiatorAgentIndex = 'initiatorAgentIndex';

    /**
     * @param string $initiatorAgentType Agent type asking for the verification window
     * @param ?int $initiatorAgentIndex Agent index, or null for a singleton agent
     */
    public function __construct(
        public readonly string $initiatorAgentType,
        public readonly ?int $initiatorAgentIndex,
    ) {
    }

    /**
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::initiatorAgentType => $this->initiatorAgentType,
            self::initiatorAgentIndex => $this->initiatorAgentIndex,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        $agentIndex = $data[self::initiatorAgentIndex] ?? null;

        return new static(
            initiatorAgentType: (string)$data[self::initiatorAgentType],
            initiatorAgentIndex: $agentIndex === null ? null : (int)$agentIndex,
        );
    }
}
