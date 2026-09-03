<?php

declare(strict_types=1);

namespace Hilos\Pages\Logs\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Log\LogBatchTakeoutMarker;

/**
 * DTO for the logs_takeout_undo action payload: which batch, on which node (HIL-759).
 *
 * The twin of {@see LogsTakeoutConfirmActionDTO} and deliberately a separate request rather than
 * a flag on it: the two actions are checked differently and refuse for different reasons, and a
 * boolean "confirm or withdraw" would branch on the node in its first line.
 *
 * The batch is named by the pair that identifies it — the node holding the archive and the
 * rotation stamp of the directory — and never by a path, the same rule
 * {@see LogsReadLinesActionDTO} names a file by.
 *
 * Nothing about the withdrawal itself travels: taking the fact back is removing the file that
 * carries it ({@see LogBatchTakeoutMarker}), and there is no second stamp to mint anywhere.
 */
final class LogsTakeoutUndoActionDTO extends ActionPayloadDTO
{
    /** Payload key: id of the node holding the batch, empty for this node. */
    public const string nodeId = 'nodeId';

    /** Payload key: Unix timestamp of the rotation batch whose confirmation is withdrawn. */
    public const string batchTimestamp = 'batchTimestamp';

    /**
     * @param string $nodeId Id of the node holding the batch, empty for this node
     * @param int $batchTimestamp Unix timestamp of the rotation batch whose confirmation is withdrawn
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly int $batchTimestamp,
    ) {
    }

    /**
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return HilosSignalConstants::LOGS_TAKEOUT_UNDO;
    }

    /**
     * Reads and validates one withdrawal request.
     *
     * The stamp is checked here and not on the node, for the reason the confirmation's is: the
     * owner answers on a socket it does not hold, so a request that names nothing must fail here,
     * correlated, rather than travel and come back as a refusal about a batch nobody meant.
     *
     * @param array<string, mixed> $data Raw payload (may contain a FIELD_DATA wrapper)
     * @return static Instance
     * @throws InvalidFormatException When the node or the batch stamp is absent, or the stamp
     *     names an instant no rotation could have happened at
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        $batchTimestamp = self::requireInt($inner, self::batchTimestamp);
        if ($batchTimestamp <= 0) {
            throw new InvalidFormatException('Batch timestamp precedes the epoch');
        }

        return new static(
            nodeId: self::requireString($inner, self::nodeId),
            batchTimestamp: $batchTimestamp,
        );
    }

    /**
     * @return array<string, mixed> Data naming the batch to withdraw the confirmation of
     */
    public function toArray(): array
    {
        return [
            self::nodeId => $this->nodeId,
            self::batchTimestamp => $this->batchTimestamp,
        ];
    }
}
