<?php

declare(strict_types=1);

namespace Hilos\Pages\Logs\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Log\LogBatchTakeoutMarker;

/**
 * Reply to logs_takeout_confirm: the instant the batch is now recorded as carried off (HIL-483).
 *
 * One field, and it is the written one rather than the requested one: a confirmation that found
 * the marker already there answers with the stamp ALREADY on disk, so a second tab and a second
 * administrator are told the same thing the first was, not a fresher time for the same event.
 *
 * Nothing else travels back. The row the browser is looking at is repainted by the node's index
 * reaching the screen, not by this ack — the ack only closes the modal that is waiting on it.
 * Refusals are not carried here at all: they are {@see ValidationException}s, and their own text
 * is what crosses the wire ({@see LogBatchTakeoutMarker} never writes on a refusal).
 */
final class LogsTakeoutConfirmReplyDTO extends ActionReplyDTO
{
    /** Reply key: Unix timestamp the batch is recorded as carried off at. */
    public const string takenAt = 'takenAt';

    /**
     * @param int $takenAt Unix timestamp the batch is recorded as carried off at
     */
    public function __construct(
        public readonly int $takenAt,
    ) {
    }

    /**
     * Reads a reply back from its wire form.
     *
     * Present for the base contract, not for a caller: the ack travels flat and the browser reads
     * it as data, so nothing on this side rebuilds one from the wire.
     *
     * @param array<string, mixed> $data Wire form of a reply
     * @return static Restored reply
     * @throws InvalidFormatException When the stamp is absent or holds a value of the wrong type
     */
    public static function fromArray(array $data): static
    {
        return new static(takenAt: self::requireInt($data, self::takenAt));
    }

    /**
     * @return array<string, mixed> Reply as it goes out on the action ack
     */
    public function toArray(): array
    {
        return [self::takenAt => $this->takenAt];
    }
}
