<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\Auth\SecondFactor\SecondFactorResetSweeper;
use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Users\Agent\AbstractUserAgent;

/**
 * Users library → the person's agent: mark a waiting removal reminded (HIL-1406).
 *
 * What {@see HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_REMIND} carries, sent by
 * {@see SecondFactorResetSweeper} once a day for each removal still waiting. Plain, as the due
 * frame is ({@see UserSecondFactorResetDueSignalData}). {@see AbstractUserAgent} marks the removal
 * only while it stands and was last announced no later than the bound, so the frame sent again
 * before the answer marks nothing and the reminder is mailed once. The request comes back inside
 * {@see UserSecondFactorResetRemindDoneSignalData}.
 */
final class UserSecondFactorResetRemindSignalData extends BaseDTO implements SignalDataInterface
{
    public const string userId = 'userId';
    public const string resetId = 'resetId';
    public const string notifiedBefore = 'notifiedBefore';
    public const string replySignal = 'replySignal';

    /**
     * @param int $userId Person whose removal it is, and the index of the agent the request is for
     * @param int $resetId Removal owing a reminder
     * @param string $notifiedBefore An announcement at or before this moment is stale (SQL datetime)
     * @param string $replySignal Agent signal the agent answers the library under
     * @throws InvalidFormatException When the person id is not positive
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $resetId,
        public readonly string $notifiedBefore,
        public readonly string $replySignal,
    ) {
        if ($userId <= 0) {
            throw new InvalidFormatException('User id must be positive');
        }
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Reminder mark request
     * @throws InvalidFormatException When the person, the removal, the bound or the reply name is invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            resetId: self::requireInt($data, self::resetId),
            notifiedBefore: self::requireString($data, self::notifiedBefore),
            replySignal: self::requireString($data, self::replySignal),
        );
    }

    /** @return array<string, int|string> Transport payload */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::resetId => $this->resetId,
            self::notifiedBefore => $this->notifiedBefore,
            self::replySignal => $this->replySignal,
        ];
    }
}
