<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\ProtectedMode\ClusterProtectedMode;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * ProtectedModeDisableSignalData - initiator -> daemon payload for PROTECTED_MODE_DISABLE.
 *
 * The initiator sends it once its operation has finished, asking for the freeze to be lifted.
 * It names the agent that asks. Both a single node and a cluster authorize the release by the
 * type and index recorded on {@see ProtectedModeRuntime}; another agent cannot thaw the system
 * mid-restore. The cluster forwards that identity to {@see ClusterProtectedMode::onDisable()},
 * so a moved initiator retains its right to release the freeze.
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
     * @throws InvalidFormatException When the payload names no initiator agent type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            initiatorAgentType: self::requireString($data, self::initiatorAgentType),
            initiatorAgentIndex: self::optionalInt($data, self::initiatorAgentIndex),
        );
    }
}
