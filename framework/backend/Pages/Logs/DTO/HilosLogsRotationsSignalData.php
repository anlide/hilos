<?php

declare(strict_types=1);

namespace Hilos\Pages\Logs\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Log\ClusterLogIndexMirror;
use Hilos\Log\LogArchiveRetentionPolicy;
use Hilos\Log\LogRotationTriggerPolicy;
use Hilos\Log\LogSettingsResolver;
use Hilos\Tables\Logs\HilosLogRotationsTable;

/**
 * Header of the log-rotations screen (server → client, HIL-387).
 *
 * Everything on that screen except the batches themselves: whether there is a picture to draw at
 * all, which nodes it holds, and the rotation and retention rules in force. The batches ride the
 * ordinary windowed table ({@see HilosLogRotationsTable}) and are deliberately not here — a header
 * that carried rows would have to be re-sent whenever a window was scrolled.
 *
 * {@see $available} has the three answers the overview's has, and they are three different screens.
 * Null: no merged picture has arrived, because the aggregator is unplaced, moving, or simply has
 * not answered yet ({@see ClusterLogIndexMirror}). False: the picture arrived and not one node
 * could read its log store. True: there are batches to list, even if there are none yet.
 *
 * {@see $nodes} being empty is the single-node installation and not a cluster nobody reported for:
 * a node with no id of its own is not a name to filter by, and the screen drops its node column and
 * node filter entirely rather than offering a choice of one.
 *
 * The rules are the ones that REALLY act: {@see LogSettingsResolver} has already fallen back to the
 * environment where a setting could not be used, so the screen shows what rotation will do and not
 * what the settings table says it should ({@see LogRotationTriggerPolicy},
 * {@see LogArchiveRetentionPolicy}).
 */
final class HilosLogsRotationsSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: whether the cluster's log stores could be read, null while no picture has arrived. */
    public const string available = 'available';

    /** Payload key: the nodes the picture holds, empty in a single-node installation. */
    public const string nodes = 'nodes';

    /** Payload key: the cron expression of the rotation schedule axis, null when it is not configured. */
    public const string rotationCron = 'rotationCron';

    /** Payload key: seconds since the last rotation after which the next one fires; 0 is off. */
    public const string rotationMaxAgeSeconds = 'rotationMaxAgeSeconds';

    /** Payload key: summed live-log size at which rotation fires, in bytes; 0 is off. */
    public const string rotationMaxLiveSizeBytes = 'rotationMaxLiveSizeBytes';

    /** Payload key: newest batches of each archive that are always kept; 0 is off. */
    public const string retentionKeepBatches = 'retentionKeepBatches';

    /** Payload key: age in seconds beyond which a batch may be carried off; 0 is off. */
    public const string retentionMaxAgeSeconds = 'retentionMaxAgeSeconds';

    /**
     * @param ?bool $available Whether the log stores could be read, null while no picture has arrived
     * @param list<string> $nodes Names of the nodes in the picture; empty in a single-node installation
     * @param ?string $rotationCron Cron expression of the schedule axis, null when none is configured
     * @param int $rotationMaxAgeSeconds Age axis of rotation in seconds; 0 when the axis is off
     * @param int $rotationMaxLiveSizeBytes Size axis of rotation in bytes; 0 when the axis is off
     * @param int $retentionKeepBatches Newest batches of each archive always kept; 0 when the criterion is off
     * @param int $retentionMaxAgeSeconds Age at which a batch becomes eligible; 0 when the criterion is off
     */
    public function __construct(
        public readonly ?bool $available,
        public readonly array $nodes,
        public readonly ?string $rotationCron,
        public readonly int $rotationMaxAgeSeconds,
        public readonly int $rotationMaxLiveSizeBytes,
        public readonly int $retentionKeepBatches,
        public readonly int $retentionMaxAgeSeconds,
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
            self::rotationCron => $this->rotationCron,
            self::rotationMaxAgeSeconds => $this->rotationMaxAgeSeconds,
            self::rotationMaxLiveSizeBytes => $this->rotationMaxLiveSizeBytes,
            self::retentionKeepBatches => $this->retentionKeepBatches,
            self::retentionMaxAgeSeconds => $this->retentionMaxAgeSeconds,
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
            rotationCron: self::optionalString($data, self::rotationCron),
            rotationMaxAgeSeconds: self::requireInt($data, self::rotationMaxAgeSeconds),
            rotationMaxLiveSizeBytes: self::requireInt($data, self::rotationMaxLiveSizeBytes),
            retentionKeepBatches: self::requireInt($data, self::retentionKeepBatches),
            retentionMaxAgeSeconds: self::requireInt($data, self::retentionMaxAgeSeconds),
        );
    }
}
