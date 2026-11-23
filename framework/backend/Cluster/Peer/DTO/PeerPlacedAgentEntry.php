<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\BaseDTO;
use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Placement\PlacementId;
use Hilos\Cluster\Placement\PlacementRecord;
use Hilos\Cluster\Placement\PlacementState;

/** An address entry in a view, or a hosted order and its start status in a report. */
final class PeerPlacedAgentEntry extends BaseDTO
{
    /** @var string Payload key: agent type */
    public const string FIELD_AGENT_TYPE = 'agentType';

    /** @var string Payload key: agent index */
    public const string FIELD_AGENT_INDEX = 'agentIndex';

    public const string FIELD_PLACEMENT_ID = PlacementRecord::FIELD_PLACEMENT_ID;

    public const string FIELD_STATE = PlacementRecord::FIELD_STATE;

    public const string FIELD_WORKER_STARTED = PlacementRecord::FIELD_WORKER_STARTED;

    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param ?PlacementId $placementId Hosted order, when reporting a managed placement
     * @param ?PlacementState $state Placing or Started, when reporting a managed placement
     * @param bool $workerStarted Whether the worker reported a completed start
     */
    public function __construct(
        public readonly string $agentType,
        public readonly ?string $agentIndex,
        public readonly ?PlacementId $placementId = null,
        public readonly ?PlacementState $state = null,
        public readonly bool $workerStarted = false,
    ) {
    }

    /**
     * Serializes the entry to its wire array.
     *
     * @return array<string, mixed> Entry payload
     */
    public function toArray(): array
    {
        return [
            self::FIELD_AGENT_TYPE => $this->agentType,
            self::FIELD_AGENT_INDEX => $this->agentIndex,
        ] + ($this->placementId === null ? [] : [self::FIELD_PLACEMENT_ID => $this->placementId->toArray()])
            + ($this->state === null ? [] : [self::FIELD_STATE => $this->state->value])
            + ($this->workerStarted ? [self::FIELD_WORKER_STARTED => true] : []);
    }

    /**
     * Restores an entry from its wire array.
     *
     * @param array<string, mixed> $data Entry payload
     * @return static Restored entry
     * @throws PeerTransportException When the agent type, hosted order or start status is invalid
     */
    public static function fromArray(array $data): static
    {
        $agentTypeValue = $data[self::FIELD_AGENT_TYPE] ?? null;
        $agentType = is_string($agentTypeValue) ? trim($agentTypeValue) : null;
        if ($agentType === null || $agentType === '') {
            throw new PeerTransportException('Peer placed-agent entry is missing the agent type');
        }

        $placementId = array_key_exists(self::FIELD_PLACEMENT_ID, $data)
            ? PlacementId::fromWire($data[self::FIELD_PLACEMENT_ID]) : null;
        $state = null;
        if (array_key_exists(self::FIELD_STATE, $data)) {
            $rawState = $data[self::FIELD_STATE];
            $state = is_string($rawState) ? PlacementState::tryFrom($rawState) : null;
            if ($state === null || !$state->hostsAgent()) {
                throw new PeerTransportException('Peer placed-agent entry has an invalid hosted state');
            }
        }

        return new static(
            $agentType,
            self::readAgentIndex($data[self::FIELD_AGENT_INDEX] ?? null),
            $placementId,
            $state,
            PlacementRecord::readWorkerStarted($data, $state, $placementId),
        );
    }

    /**
     * Normalizes a wire agent-index value: a blank or absent value reads as null.
     *
     * @param mixed $raw Raw agent-index value from the wire
     * @return ?string Agent index, or null for a singleton agent
     */
    public static function readAgentIndex(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $index = trim((string)$raw);

        return $index === '' ? null : $index;
    }
}
