<?php

declare(strict_types=1);

namespace Hilos\Pages\Logs\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Log\ClusterLogIndexMirror;
use Hilos\Tables\Logs\HilosLogWorkersTable;

/**
 * Header of the log-workers screen (server → client, HIL-386).
 *
 * Everything on that screen except the streams themselves: whether there is a picture to draw at
 * all, and which nodes it holds. The streams ride the ordinary windowed table
 * ({@see HilosLogWorkersTable}) and are deliberately not here — a header that carried rows would
 * have to be re-sent whenever a window was scrolled.
 *
 * {@see $available} has the three answers the neighbouring screens' have, and they are three
 * different screens. Null: no merged picture has arrived, because the aggregator is unplaced,
 * moving, or simply has not answered yet ({@see ClusterLogIndexMirror}). False: the picture arrived
 * and not one node could read its log store. True: there are streams to list, even if there are
 * none yet.
 *
 * {@see $nodes} being empty is the single-node installation and not a cluster nobody reported for:
 * a node with no id of its own is not a name to filter by, and the screen drops its node column,
 * its node filter and the mention of a node in its footnote entirely rather than offering a choice
 * of one.
 */
final class HilosLogsWorkersSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: whether the cluster's log stores could be read, null while no picture has arrived. */
    public const string available = 'available';

    /** Payload key: the nodes the picture holds, empty in a single-node installation. */
    public const string nodes = 'nodes';

    /**
     * @param ?bool $available Whether the log stores could be read, null while no picture has arrived
     * @param list<string> $nodes Names of the nodes in the picture; empty in a single-node installation
     */
    public function __construct(
        public readonly ?bool $available,
        public readonly array $nodes,
    ) {
    }

    /**
     * @return array<string, mixed> Header as it goes out to the browser
     */
    public function toArray(): array
    {
        return [
            self::available => $this->available,
            self::nodes => $this->nodes,
        ];
    }

    /**
     * Reads the header back from its wire form.
     *
     * @param array<string, mixed> $data Wire form of the header
     * @return static Restored header
     * @throws InvalidFormatException When a field the header has no meaning without is absent or of the wrong type
     */
    public static function fromArray(array $data): static
    {
        $available = $data[self::available] ?? null;

        return new static(
            // Anything that is not a bool reads as null — "we do not know" — rather than as false:
            // false is the claim that the stores were read and none of them answered.
            available: is_bool($available) ? $available : null,
            nodes: array_values(array_filter(self::requireArray($data, self::nodes), 'is_string')),
        );
    }
}
