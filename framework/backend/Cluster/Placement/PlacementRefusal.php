<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

use Hilos\Cluster\Exception\PeerTransportException;

/**
 * One gate refusal of a placement, in the form a machine reads (HIL-447): which node refused,
 * the first gate it failed, and the details that gate is about.
 *
 * Built where the gate judges ({@see PlacementCandidate::refusal()}) and carried from there
 * unchanged — on the exception the gate throws, in a failed status and a not-placed verdict
 * between nodes, on the leader's placement record and in its last-refusal snapshot. Nothing ever
 * reads it back out of a message text.
 *
 * A refusal speaks about one node, except {@see PlacementRefusalCode::NoOnlineNodes}, which has
 * none to name. Only the details of its own code are filled: the tags a node lacks for a missing
 * capability, the resources it is short of for an insufficient capacity, nothing for the other
 * two. On the wire the shortfalls are an object keyed by resource name, even when empty, and the
 * missing capabilities a list.
 */
final class PlacementRefusal
{
    /** @var string Wire key: id of the node that refused, null when no node was online */
    public const string FIELD_NODE_ID = 'nodeId';

    /** @var string Wire key: the {@see PlacementRefusalCode} value */
    public const string FIELD_REASON_CODE = 'reasonCode';

    /** @var string Wire key: required capability tags the node does not advertise */
    public const string FIELD_MISSING_CAPABILITIES = 'missingCapabilities';

    /** @var string Wire key: resources the node is short of, keyed by resource name */
    public const string FIELD_SHORTFALLS = 'shortfalls';

    /** @var string Shortfall key: what the agent costs of the resource, its slot included */
    public const string FIELD_COST = 'cost';

    /** @var string Shortfall key: what the node has free of the resource */
    public const string FIELD_FREE = 'free';

    /**
     * @param ?string $nodeId Node that refused, or null for {@see PlacementRefusalCode::NoOnlineNodes}
     * @param PlacementRefusalCode $reasonCode First gate the node failed
     * @param list<string> $missingCapabilities Tags the node lacks; empty unless capabilities are missing
     * @param array<string, array{cost: float, free: float}> $shortfalls Short resources; empty unless capacity is insufficient
     */
    private function __construct(
        public readonly ?string $nodeId,
        public readonly PlacementRefusalCode $reasonCode,
        public readonly array $missingCapabilities,
        public readonly array $shortfalls,
    ) {
    }

    /**
     * Builds the refusal of a node that does not advertise every capability tag the agent needs.
     *
     * @param string $nodeId Node that refused
     * @param list<string> $missing Required tags the node does not advertise
     * @return self Missing-capabilities refusal
     */
    public static function unmetCapabilities(string $nodeId, array $missing): self
    {
        return new self($nodeId, PlacementRefusalCode::MissingCapabilities, array_values($missing), []);
    }

    /**
     * Builds the refusal of a node that declares no capacity at all and so takes no placed work.
     *
     * @param string $nodeId Node that refused
     * @return self No-declared-capacity refusal
     */
    public static function noDeclaredCapacity(string $nodeId): self
    {
        return new self($nodeId, PlacementRefusalCode::NoDeclaredCapacity, [], []);
    }

    /**
     * Builds the refusal of a node whose free room is below the agent's cost in some resource.
     *
     * @param string $nodeId Node that refused
     * @param array<string, array{cost: float, free: float}> $shortfalls Cost and free capacity keyed by resource name
     * @return self Insufficient-capacity refusal
     */
    public static function insufficientCapacity(string $nodeId, array $shortfalls): self
    {
        return new self($nodeId, PlacementRefusalCode::InsufficientCapacity, [], $shortfalls);
    }

    /**
     * Builds the refusal that names no node: nothing online was a candidate at all.
     *
     * @return self No-online-nodes refusal
     */
    public static function noOnlineNodes(): self
    {
        return new self(null, PlacementRefusalCode::NoOnlineNodes, [], []);
    }

    /**
     * Whether another refusal says exactly the same thing: same node, code and details.
     *
     * Compared by value, so two refusals built apart — one on the node, one parsed off the wire on
     * the leader — are the same refusal when they carry the same structure.
     *
     * @param self $other Refusal to compare with
     * @return bool True when every field is equal
     */
    public function equals(self $other): bool
    {
        return $this->nodeId === $other->nodeId
            && $this->reasonCode === $other->reasonCode
            && $this->missingCapabilities === $other->missingCapabilities
            && $this->shortfalls === $other->shortfalls;
    }

    /**
     * Serializes the refusal to its wire array.
     *
     * The shortfalls go out as an object even when empty: they are a map by resource name, and an
     * empty PHP array would reach the other end as a list.
     *
     * @return array<string, mixed> Refusal payload
     */
    public function toArray(): array
    {
        return [
            self::FIELD_NODE_ID => $this->nodeId,
            self::FIELD_REASON_CODE => $this->reasonCode->value,
            self::FIELD_MISSING_CAPABILITIES => $this->missingCapabilities,
            self::FIELD_SHORTFALLS => (object)$this->shortfalls,
        ];
    }

    /**
     * Restores a refusal from its wire array, refusing any form the writer never produces.
     *
     * Every key is required. The code must be known; a node refusal must name its node and a
     * no-online-nodes one must not; the details of the code must be there and the details of any
     * other code must be empty.
     *
     * @param array<string, mixed> $data Refusal payload
     * @return self Restored refusal
     * @throws PeerTransportException When the code is unknown, a field is missing or mistyped, or the details do not fit the code
     */
    public static function fromArray(array $data): self
    {
        $codeValue = $data[self::FIELD_REASON_CODE] ?? null;
        $reasonCode = is_string($codeValue) ? PlacementRefusalCode::tryFrom($codeValue) : null;
        if ($reasonCode === null) {
            $shownCode = is_string($codeValue) ? $codeValue : get_debug_type($codeValue);
            throw new PeerTransportException("Placement refusal has an unknown reason code '{$shownCode}'");
        }

        if (!array_key_exists(self::FIELD_NODE_ID, $data)) {
            throw new PeerTransportException('Placement refusal is missing the node id');
        }
        $nodeId = self::readNodeId($data[self::FIELD_NODE_ID], $reasonCode);
        $missing = self::readMissingCapabilities($data);
        $shortfalls = self::readShortfalls($data);

        if (($reasonCode === PlacementRefusalCode::MissingCapabilities) !== ($missing !== [])) {
            throw new PeerTransportException(
                "Placement refusal '{$reasonCode->value}' does not fit its missing capabilities",
            );
        }
        if (($reasonCode === PlacementRefusalCode::InsufficientCapacity) !== ($shortfalls !== [])) {
            throw new PeerTransportException("Placement refusal '{$reasonCode->value}' does not fit its shortfalls");
        }

        return new self($nodeId, $reasonCode, $missing, $shortfalls);
    }

    /**
     * Reads the node id a refusal of this code must, or must not, carry.
     *
     * @param mixed $raw Raw node id from the wire
     * @param PlacementRefusalCode $reasonCode Code already read off the same refusal
     * @return ?string Node id, or null for a no-online-nodes refusal
     * @throws PeerTransportException When a node refusal names no node, or a no-online-nodes one names one
     */
    private static function readNodeId(mixed $raw, PlacementRefusalCode $reasonCode): ?string
    {
        if ($reasonCode === PlacementRefusalCode::NoOnlineNodes) {
            if ($raw !== null) {
                throw new PeerTransportException('Placement refusal with no online nodes names a node');
            }

            return null;
        }

        $nodeId = is_string($raw) ? trim($raw) : null;
        if ($nodeId === null || $nodeId === '') {
            throw new PeerTransportException("Placement refusal '{$reasonCode->value}' names no node");
        }

        return $nodeId;
    }

    /**
     * Reads the list of missing capability tags.
     *
     * @param array<string, mixed> $data Refusal payload
     * @return list<string> Missing tags
     * @throws PeerTransportException When the key is absent, or holds anything but a list of non-blank strings
     */
    private static function readMissingCapabilities(array $data): array
    {
        $raw = $data[self::FIELD_MISSING_CAPABILITIES] ?? null;
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new PeerTransportException('Placement refusal missing capabilities are not a list');
        }

        foreach ($raw as $tag) {
            if (!is_string($tag) || trim($tag) === '') {
                throw new PeerTransportException('Placement refusal names a missing capability that is not a tag');
            }
        }

        return $raw;
    }

    /**
     * Reads the shortfalls map: per resource, what the agent costs and what the node has free.
     *
     * A resource name arrives as an array key, and PHP turns a decimal one into an integer, so it
     * is read back as a string. A non-empty list is refused: the writer sends an object.
     *
     * @param array<string, mixed> $data Refusal payload
     * @return array<string, array{cost: float, free: float}> Shortfalls keyed by resource name
     * @throws PeerTransportException When the key is absent, or holds anything but a map of cost and free numbers
     */
    private static function readShortfalls(array $data): array
    {
        $raw = $data[self::FIELD_SHORTFALLS] ?? null;
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw new PeerTransportException('Placement refusal shortfalls are not a map by resource');
        }

        $shortfalls = [];
        foreach ($raw as $resource => $shortfall) {
            $resource = (string)$resource;
            $cost = is_array($shortfall) ? ($shortfall[self::FIELD_COST] ?? null) : null;
            $free = is_array($shortfall) ? ($shortfall[self::FIELD_FREE] ?? null) : null;
            if (trim($resource) === '' || !(is_int($cost) || is_float($cost)) || !(is_int($free) || is_float($free))) {
                throw new PeerTransportException("Placement refusal shortfall '{$resource}' is not a cost and a free amount");
            }

            $shortfalls[$resource] = [self::FIELD_COST => (float)$cost, self::FIELD_FREE => (float)$free];
        }

        return $shortfalls;
    }
}
