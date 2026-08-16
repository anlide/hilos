<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\ProtectedMode\ClusterProtectedMode;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * ProtectedModeProgressSignalData - initiator -> daemon payload for PROTECTED_MODE_PROGRESS.
 *
 * The initiator sends it whenever the work behind the freeze moved, so that a watchdog reading
 * {@see ProtectedModeRuntime::$progressAt} can tell an operation that is legitimately long from one
 * that hung. It is the one request of the set that asks for nothing: no phase moves, no client is
 * let in or out, and a mark that never arrives costs an alert rather than a stuck system.
 *
 * It carries the same two identity fields {@see ProtectedModeVerifySignalData} carries, for the
 * same reason: on a single node the recorded initiator agent is the whole authorization, while a
 * cluster compares initiator node ids instead ({@see ClusterProtectedMode::onProgress()}) and
 * ignores these fields. What it deliberately does NOT carry is the moment of the progress - the
 * master that owns the row stamps that itself, so a node with a skewed clock cannot push another
 * node's silence threshold around.
 */
final class ProtectedModeProgressSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: the agent type reporting that its operation moved. */
    public const string initiatorAgentType = 'initiatorAgentType';

    /** Payload key: the agent index reporting that its operation moved. */
    public const string initiatorAgentIndex = 'initiatorAgentIndex';

    /**
     * @param string $initiatorAgentType Agent type reporting the progress
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
