<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\AgentConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\TruthSource\RtNodeSourceMap;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * WorkerRtSourceRegisteredDTO - the RT collections an agent owns, reported to the daemon.
 *
 * The truth-source registry lives in the worker beside the agent that registered in it, and
 * the master decides what goes on the wire between nodes — so the worker tells it what its
 * agent took ownership of, once, right after the agent started. The master keeps the answers
 * in its {@see RtNodeSourceMap}; nothing reads {@see RtTruthSourceRegistry} across a process
 * boundary.
 */
class WorkerRtSourceRegisteredDTO extends WorkerDTO
{
    // Message type
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_RT_SOURCE_REGISTERED;

    /** @var string Payload key: RT collections the agent registered as a truth source for */
    public const string FIELD_COLLECTION_KEYS = 'collectionKeys';

    /**
     * Creates RT source registered DTO.
     *
     * @param string $agentId Agent that registered the collections
     * @param list<string> $collectionKeys RT collections it owns on this node
     */
    public function __construct(
        public readonly string $agentId,
        public readonly array $collectionKeys,
    ) {
    }

    /**
     * Get message type.
     *
     * @return string Message type
     */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            AgentConstants::FIELD_AGENT_ID => $this->agentId,
            self::FIELD_COLLECTION_KEYS => $this->collectionKeys,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data (agentId, collectionKeys)
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no agent id or no collection list
     */
    public static function fromArray(array $data): static
    {
        $collectionKeys = [];
        foreach (self::requireArray($data, self::FIELD_COLLECTION_KEYS) as $collectionKey) {
            if (is_string($collectionKey) && $collectionKey !== '') {
                $collectionKeys[] = $collectionKey;
            }
        }

        return new static(
            agentId: self::requireString($data, AgentConstants::FIELD_AGENT_ID),
            collectionKeys: $collectionKeys,
        );
    }
}
