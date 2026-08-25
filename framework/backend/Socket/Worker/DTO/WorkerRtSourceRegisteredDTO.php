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

    /** @var string Payload key: those of them the agent holds only part of the operations on */
    public const string FIELD_PARTIAL_COLLECTION_KEYS = 'partialCollectionKeys';

    /** @var string Payload key: the rows the agent claimed by name, collection by collection */
    public const string FIELD_KEYS_BY_COLLECTION = 'keysByCollection';

    /**
     * Creates RT source registered DTO.
     *
     * The second list is a subset of the first, not another set of collections: it says which
     * of the claims are partial by OPERATION, so the master can tell a legitimate co-owner on
     * another node from the two-owner split it refuses. The map that follows is the other axis,
     * the rows: a collection appears there when the agent claimed it by naming keys, and the
     * keys it named. A claim on the whole collection names none, so the map stays silent about
     * it — silence means "all of it", as it does in the registry.
     *
     * @param string $agentId Agent that registered the collections
     * @param list<string> $collectionKeys RT collections it owns on this node
     * @param list<string> $partialCollectionKeys Those of them it owns with only part of the operations
     * @param array<string, list<string>> $keysByCollection Those of them it claimed by key, and the keys
     */
    public function __construct(
        public readonly string $agentId,
        public readonly array $collectionKeys,
        public readonly array $partialCollectionKeys = [],
        public readonly array $keysByCollection = [],
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
            self::FIELD_PARTIAL_COLLECTION_KEYS => $this->partialCollectionKeys,
            self::FIELD_KEYS_BY_COLLECTION => $this->keysByCollection,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * The partial list and the key map are both optional on the wire: a worker of an older build
     * names neither, and reading its silence as "no partial claim, no claim by key" leaves that
     * worker judged exactly as it was before the two axes existed.
     *
     * @param array<string, mixed> $data Source data (agentId, collectionKeys, partialCollectionKeys, keysByCollection)
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

        $partialCollectionKeys = [];
        $partialRaw = $data[self::FIELD_PARTIAL_COLLECTION_KEYS] ?? [];
        if (is_array($partialRaw)) {
            foreach ($partialRaw as $collectionKey) {
                if (is_string($collectionKey) && $collectionKey !== '') {
                    $partialCollectionKeys[] = $collectionKey;
                }
            }
        }

        $keysByCollection = [];
        $keysRaw = $data[self::FIELD_KEYS_BY_COLLECTION] ?? [];
        if (is_array($keysRaw)) {
            foreach ($keysRaw as $collectionKey => $stateIds) {
                if (!is_string($collectionKey) || $collectionKey === '' || !is_array($stateIds)) {
                    continue;
                }
                $keys = [];
                foreach ($stateIds as $stateId) {
                    if (is_string($stateId) && $stateId !== '') {
                        $keys[] = $stateId;
                    }
                }
                $keysByCollection[$collectionKey] = $keys;
            }
        }

        return new static(
            agentId: self::requireString($data, AgentConstants::FIELD_AGENT_ID),
            collectionKeys: $collectionKeys,
            partialCollectionKeys: $partialCollectionKeys,
            keysByCollection: $keysByCollection,
        );
    }
}
