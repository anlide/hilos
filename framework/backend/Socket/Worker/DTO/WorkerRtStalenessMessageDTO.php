<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\RtStaleness;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerRtStalenessMessageDTO - which rows of one RT collection froze or thawed, daemon to worker.
 *
 * The master is the only process that sees a peer link open or close, and every worker holds its
 * own copy of the collection — so the answer to "is this row's source still reachable" has to
 * travel the same way the rows themselves do (HIL-711). It rides the interest filter with them:
 * a worker that does not read the collection could do nothing with the frame.
 *
 * One frame per collection rather than one per node, because that is what a reader asks about:
 * a worker never learns which node owns what, and does not need to — the master has already
 * turned "this node became unreachable" into "these rows of this collection froze".
 *
 * A null moment is the mark being LIFTED. The alternative — a second message type for thawing —
 * would double a frame that already carries every field the lift needs.
 */
class WorkerRtStalenessMessageDTO extends WorkerDTO
{
    // Message type
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_RT_STALENESS;

    /** @var string Payload key: RT collection the rows belong to */
    public const string FIELD_COLLECTION_KEY = 'collectionKey';

    /** @var string Payload key: rows whose freshness changed, as a list of state ids */
    public const string FIELD_STATE_IDS = 'stateIds';

    /** @var string Payload key: microtime the rows froze at, or null when the mark is lifted */
    public const string FIELD_SINCE = 'since';

    /**
     * @param string $collectionKey RT collection the rows belong to
     * @param list<string> $stateIds Rows whose freshness changed
     * @param ?float $since Microtime they froze at, as {@see RtStaleness::mark()} takes it, or
     *     null when the mark is lifted
     */
    public function __construct(
        public readonly string $collectionKey,
        public readonly array $stateIds,
        public readonly ?float $since,
    ) {
    }

    /**
     * @return string Message type
     */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /**
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_COLLECTION_KEY => $this->collectionKey,
            self::FIELD_STATE_IDS => $this->stateIds,
            self::FIELD_SINCE => $this->since,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * A missing moment reads as the mark being lifted, which is what the field says when it is
     * there: absent and null mean the same thing, and there is no third state to tell apart.
     *
     * @param array<string, mixed> $data Source data (collectionKey, stateIds, since)
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no collection
     */
    public static function fromArray(array $data): static
    {
        $stateIds = [];
        $raw = $data[self::FIELD_STATE_IDS] ?? [];
        if (is_array($raw)) {
            foreach ($raw as $stateId) {
                if (is_scalar($stateId)) {
                    $stateIds[] = (string)$stateId;
                }
            }
        }

        $since = $data[self::FIELD_SINCE] ?? null;

        return new static(
            collectionKey: self::requireString($data, self::FIELD_COLLECTION_KEY),
            stateIds: $stateIds,
            since: is_numeric($since) ? (float)$since : null,
        );
    }
}
