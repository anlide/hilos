<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

use Hilos\Cluster\NodeIdentity;

/**
 * Structured reading of a node's advertised capability tags for resource-aware placement
 * (HIL-182, HIL-448).
 *
 * A node still advertises a flat {@see NodeIdentity} tag list on the wire; this value object
 * layers meaning on top of it without changing that contract. A tag is one of two shapes:
 *
 * - a boolean capability tag ("worker", "gpu") — a presence flag the hard gate matches;
 * - a numeric capacity ("cpu=8", "ram=32") — how much of a named resource the node has. It is
 *   a CONSUMABLE stock (HIL-448): the leader subtracts the cost of every agent it placed on the
 *   node, so what a newcomer is checked against is the free remainder, not the declared figure.
 *
 * A node that declares no numeric capacity at all accepts no placed work (HIL-445): it is not a
 * candidate for the policy, and a placement naming it is refused. A capacity of zero is still a
 * declaration — the node takes agents that cost nothing.
 *
 * The `key=value` grammar reuses the existing tag channel so no peer frame changes: a token
 * without `=`, or one whose value is not numeric, is kept as a plain tag, so operator typos
 * degrade to a harmless unmatched tag rather than a parse error. `=` is used rather than the
 * agent id separator `:` so a capacity key never collides with an agent id.
 */
final class NodeCapacities
{
    /** @var string Separator between a numeric capacity key and its value in a capability tag */
    public const string CAPACITY_SEPARATOR = '=';

    /**
     * @param list<string> $tags Boolean capability tags the node advertises
     * @param array<string, float> $capacities Numeric capacities keyed by resource name
     */
    private function __construct(
        private readonly array $tags,
        private readonly array $capacities,
    ) {
    }

    /**
     * Parses a node's advertised tag list into boolean tags and numeric capacities.
     *
     * @param list<string> $advertised Advertised capability tags
     * @return self Parsed capacities
     */
    public static function fromTags(array $advertised): self
    {
        $tags = [];
        $capacities = [];
        foreach ($advertised as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            $separator = strpos($token, self::CAPACITY_SEPARATOR);
            if ($separator === false) {
                $tags[] = $token;
                continue;
            }

            $key = trim(substr($token, 0, $separator));
            $value = trim(substr($token, $separator + 1));
            if ($key === '' || !is_numeric($value)) {
                $tags[] = $token;
                continue;
            }

            $capacities[$key] = (float)$value;
        }

        return new self($tags, $capacities);
    }

    /**
     * Reports whether the node advertises a boolean capability tag.
     *
     * @param string $tag Capability tag to test
     * @return bool True when the tag is advertised
     */
    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags, true);
    }

    /**
     * Returns the node's declared amount of a named resource, or 0.0 when it declares none.
     *
     * @param string $key Resource name
     * @return float Declared capacity
     */
    public function capacity(string $key): float
    {
        return $this->capacities[$key] ?? 0.0;
    }

    /**
     * Reports whether the node declares any numeric capacity, and therefore accepts placed work.
     *
     * @return bool True when at least one `key=value` capacity was parsed, zero included
     */
    public function declaresCapacity(): bool
    {
        return $this->capacities !== [];
    }

    /**
     * Returns every declared numeric capacity.
     *
     * @return array<string, float> Declared capacity keyed by resource name
     */
    public function capacities(): array
    {
        return $this->capacities;
    }

    /**
     * Returns the sum of every declared numeric capacity, a coarse "node strength" used as
     * the best-fit tiebreaker among nodes with an equal load and head count.
     *
     * @return float Total declared capacity
     */
    public function totalCapacity(): float
    {
        return array_sum($this->capacities);
    }
}
