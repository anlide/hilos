<?php

declare(strict_types=1);

namespace Hilos\Auth\StepUp\DTO;

use Hilos\Auth\Session\DTO\ProfileFlowsSignalData;
use Hilos\Auth\StepUp\StepUpConfirmations;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Writer of a confirmation, or the sessions library on a handshake → the tabs of one session: these
 * are the operations it has a live identity confirmation of (HIL-1330).
 *
 * The frame carries the LIST and not the operation just confirmed, the shape {@see ProfileFlowsSignalData}
 * established: a write and a reconnect are then one sentence, and the handshake has no single
 * operation to name. Unlike that list, an EMPTY one is never sent: the browser does not keep this
 * list as state - it is read only on arrival, as "the server says this is confirmed now" - so there
 * is nothing an empty frame could take away.
 *
 * What a key does NOT carry is the person who confirmed it and the moment it dies. The first is the
 * session's own, and the second is the server's to judge by: the gate checks the row on every
 * protected action, whatever a tab was told.
 *
 * Built by {@see StepUpConfirmations::frameFor()}.
 */
final class StepUpConfirmedSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: the operation keys with a live confirmation. */
    public const string operations = 'operations';

    /**
     * @param list<string> $operations Operation keys the session has a live confirmation of, without repeats, in ascending order
     */
    public function __construct(
        public readonly array $operations,
    ) {
    }

    /**
     * @return array<string, mixed> DTO payload for transport
     */
    public function toArray(): array
    {
        return [
            self::operations => $this->operations,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no list of operation keys
     */
    public static function fromArray(array $data): static
    {
        $operations = self::optionalStringList($data, self::operations);
        if ($operations === null) {
            throw new InvalidFormatException('Payload carries no list of strings under key ' . self::operations);
        }

        return new static($operations);
    }
}
