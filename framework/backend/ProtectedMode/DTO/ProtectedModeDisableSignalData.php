<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\ProtectedMode\ClusterProtectedMode;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * ProtectedModeDisableSignalData - initiator -> daemon payload for PROTECTED_MODE_DISABLE.
 *
 * The initiator sends it once its operation has finished, asking for the freeze to be lifted.
 * It names the agent that asks, and on a single node that name is the whole authorization: only
 * the agent recorded as the initiator on {@see ProtectedModeRuntime} may thaw the system, or any
 * other agent could resume the node in the middle of a restore. A cluster authorizes by initiator
 * node id instead ({@see ClusterProtectedMode::onDisable()}) and ignores these fields - that
 * comparison is what degenerates to nothing when there is only one node, which is why the identity
 * had to enter the payload at all.
 */
final class ProtectedModeDisableSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: the agent type asking for the release. */
    public const string initiatorAgentType = 'initiatorAgentType';

    /** Payload key: the agent index asking for the release. */
    public const string initiatorAgentIndex = 'initiatorAgentIndex';

    /**
     * @param string $initiatorAgentType Agent type asking for the release
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
            initiatorAgentType: (string)($data[self::initiatorAgentType] ?? ''),
            initiatorAgentIndex: $agentIndex === null ? null : (int)$agentIndex,
        );
    }
}
