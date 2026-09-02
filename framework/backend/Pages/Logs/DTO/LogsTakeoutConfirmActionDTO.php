<?php

declare(strict_types=1);

namespace Hilos\Pages\Logs\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Log\LogBatchTakeoutMarker;

/**
 * DTO for the logs_takeout_confirm action payload: which batch, on which node (HIL-483).
 *
 * The batch is named by the pair that identifies it — the node holding the archive and the
 * rotation stamp of the directory — and never by a path, the same rule
 * {@see LogsReadLinesActionDTO} names a file by. One rotation moment on two nodes is two batches
 * and two confirmations, so the node is part of the name rather than a hint about where to look.
 *
 * Nothing about the confirmation itself travels: the instant it happened at and who did it are
 * written by the node that owns the directory ({@see LogBatchTakeoutMarker}), because a stamp
 * minted in a browser would be one machine's clock recorded as another's.
 */
final class LogsTakeoutConfirmActionDTO extends ActionPayloadDTO
{
    /** Payload key: id of the node holding the batch, empty for this node. */
    public const string nodeId = 'nodeId';

    /** Payload key: Unix timestamp of the rotation batch being confirmed. */
    public const string batchTimestamp = 'batchTimestamp';

    /**
     * @param string $nodeId Id of the node holding the batch, empty for this node
     * @param int $batchTimestamp Unix timestamp of the rotation batch being confirmed
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
        return HilosSignalConstants::LOGS_TAKEOUT_CONFIRM;
    }

    /**
     * Reads and validates one confirmation request.
     *
     * The stamp is checked here and not on the node, for the reason the read's fields are: the
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
     * @return array<string, mixed> Data naming the batch to confirm
     */
    public function toArray(): array
    {
        return [
            self::nodeId => $this->nodeId,
            self::batchTimestamp => $this->batchTimestamp,
        ];
    }
}
