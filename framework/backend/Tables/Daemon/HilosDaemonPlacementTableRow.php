<?php

declare(strict_types=1);

namespace Hilos\Tables\Daemon;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;

/** One declared agent type and the instances each known node holds. */
final class HilosDaemonPlacementTableRow extends AbstractTableRow
{
    public const string rowKey = 'rowKey';
    public const string agentType = 'agentType';
    public const string placement = 'placement';
    public const string idle = 'idle';
    public const string nodes = 'nodes';
    public const string unplacedAgentIds = 'unplacedAgentIds';
    public const string NODE_ID = 'nodeId';
    public const string NODE_AGENT_IDS = 'agentIds';

    /**
     * @param string $rowKey Registry type key
     * @param string $agentType Declared agent type
     * @param string $placement Node, leader, or policy placement
     * @param bool $idle Whether the declaration has an idle timeout
     * @param list<array{nodeId: string, agentIds: ?list<string>}> $nodes Known nodes and their instances
     * @param list<string> $unplacedAgentIds Leader's unplaced instances of this type
     */
    public function __construct(
        public string $rowKey,
        public string $agentType,
        public string $placement,
        public bool $idle,
        public array $nodes,
        public array $unplacedAgentIds,
    ) {
    }

    /** @return string Stable registry type key */
    public function getRowKey(): string
    {
        return $this->rowKey;
    }

    /** @return string Payload key carrying the row key */
    public static function keyField(): string
    {
        return self::rowKey;
    }

    /** @return array<string, mixed> Placement row payload */
    public function toArray(): array
    {
        return [
            self::rowKey => $this->rowKey,
            self::agentType => $this->agentType,
            self::placement => $this->placement,
            self::idle => $this->idle,
            self::nodes => $this->nodes,
            self::unplacedAgentIds => $this->unplacedAgentIds,
        ];
    }

    /**
     * @param array<string, mixed> $data Raw placement row
     * @return static Reconstructed row
     * @throws InvalidFormatException When a required field or node list is invalid
     */
    public static function fromArray(array $data): static
    {
        $unplaced = self::optionalStringList($data, self::unplacedAgentIds);
        if ($unplaced === null) {
            throw new InvalidFormatException('Placement row carries no unplaced id list');
        }
        $nodes = self::requireArray($data, self::nodes);
        if (!array_is_list($nodes)) {
            throw new InvalidFormatException('Placement row nodes must be a list');
        }
        foreach ($nodes as $index => $node) {
            if (!is_array($node)) {
                throw new InvalidFormatException('Placement row node must be an object');
            }
            $nodeId = self::requireString($node, self::NODE_ID);
            $agentIds = self::optionalStringList($node, self::NODE_AGENT_IDS);
            if (!array_key_exists(self::NODE_AGENT_IDS, $node)) {
                throw new InvalidFormatException('Placement row node carries no agent id list');
            }
            $nodes[$index] = [self::NODE_ID => $nodeId, self::NODE_AGENT_IDS => $agentIds];
        }

        return new static(
            rowKey: self::requireString($data, self::rowKey),
            agentType: self::requireString($data, self::agentType),
            placement: self::requireString($data, self::placement),
            idle: self::requireBool($data, self::idle),
            nodes: $nodes,
            unplacedAgentIds: $unplaced,
        );
    }
}
