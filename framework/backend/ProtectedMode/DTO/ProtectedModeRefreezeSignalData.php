<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\ProtectedMode\ClusterProtectedMode;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * ProtectedModeRefreezeSignalData - initiator -> daemon payload for PROTECTED_MODE_REFREEZE.
 *
 * The other way out of the verification window: the operator did not like what the verifiers
 * found, so the system closes again ({@see ProtectedModeRuntime::PHASE_ACTIVE}) instead of
 * opening to everyone. Agents are stopped once more, every pass is void, and another destructive
 * operation may run - without this exit an operator who has just seen broken data would have to
 * open the system to real users in order to do anything about it.
 *
 * It carries and authorizes by the same identity as {@see ProtectedModeVerifySignalData}
 * ({@see ClusterProtectedMode::onRefreeze()} for the clustered half).
 */
final class ProtectedModeRefreezeSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: the agent type asking to close the system again. */
    public const string initiatorAgentType = 'initiatorAgentType';

    /** Payload key: the agent index asking to close the system again. */
    public const string initiatorAgentIndex = 'initiatorAgentIndex';

    /**
     * @param string $initiatorAgentType Agent type asking to close the system again
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
