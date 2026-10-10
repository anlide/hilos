<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\Auth\SecondFactor\SecondFactorResetSweeper;
use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Users\Agent\AbstractUserAgent;

/**
 * Users library → the person's agent: a removal's moment came, carry it out (HIL-1406).
 *
 * What {@see HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_DUE} carries, sent by
 * {@see SecondFactorResetSweeper} for each removal that is due. Not an ask of the handover form
 * ({@see HandoverAskInterface}): the sweep runs on a tick, outside any connection, so there is
 * nobody to stamp the write with. {@see AbstractUserAgent} marks the removal carried out by a
 * conditional write and takes the factor out in the same transaction; a cancel that won the same
 * minute leaves the factor, and the frame sent again by a tick that came before the answer does
 * nothing. The request comes back inside {@see UserSecondFactorResetDueDoneSignalData}.
 */
final class UserSecondFactorResetDueSignalData extends BaseDTO implements SignalDataInterface
{
    public const string userId = 'userId';
    public const string resetId = 'resetId';
    public const string replySignal = 'replySignal';

    /**
     * @param int $userId Person whose removal it is, and the index of the agent the request is for
     * @param int $resetId Removal that is due
     * @param string $replySignal Agent signal the agent answers the library under
     * @throws InvalidFormatException When the person id is not positive
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $resetId,
        public readonly string $replySignal,
    ) {
        if ($userId <= 0) {
            throw new InvalidFormatException('User id must be positive');
        }
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Due removal request
     * @throws InvalidFormatException When the person, the removal or the reply name is invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            resetId: self::requireInt($data, self::resetId),
            replySignal: self::requireString($data, self::replySignal),
        );
    }

    /** @return array<string, int|string> Transport payload */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::resetId => $this->resetId,
            self::replySignal => $this->replySignal,
        ];
    }
}
