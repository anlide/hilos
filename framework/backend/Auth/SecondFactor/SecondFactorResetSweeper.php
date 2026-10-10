<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\Command\SecondFactorCommands;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Users\AddressablePerson;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\DTO\UserSecondFactorResetDueSignalData;
use Hilos\Users\DTO\UserSecondFactorResetRemindSignalData;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\Utils\Logger;

/**
 * SecondFactorResetSweeper - finds the removals whose time came and the ones owing a reminder (HIL-494, HIL-1406).
 *
 * Run from the users library's tick ({@see AbstractUsersLibraryAgent}) once a minute. It writes
 * nothing: each removal it finds goes to the person's agent ({@see AbstractUserAgent}) as a frame,
 * and the agent writes by conditional writes - the removal marked carried out and the factor taken
 * out in one transaction, a cancel that won the same minute leaving the factor where it was; a
 * waiting removal marked reminded only once a day. So a frame sent again by a tick that came
 * before the answer does nothing. The letters go on the agent's answer
 * ({@see SecondFactorCommands::finishResetDue()}, {@see SecondFactorCommands::finishResetRemind()}).
 */
final class SecondFactorResetSweeper
{
    /**
     * @param AbstractUsersLibraryAgent $library The users library whose tick runs the sweep, and which sends the frames
     */
    public function __construct(private readonly AbstractUsersLibraryAgent $library)
    {
    }

    /**
     * Hands the removals due and the ones owing a reminder to the people's agents.
     *
     * A person who can no longer be addressed - erased, or folded into someone else - is skipped
     * with a warning; their removals leave with the erasure or the merge. A standing request with
     * no cancel token fails the tick before its frame, so the next tick tries it again instead of
     * announcing a reminder that cannot be canceled.
     *
     * @throws LogicException When a standing request has no cancel token
     * @throws HilosException When a lookup or a frame fails
     */
    public function sweep(): void
    {
        $now = TimeHelper::getSqlDateTime();

        foreach (Hilos::$db->secondFactorResets->dueBy($now) as $reset) {
            if (!$this->addressable($reset->userId)) {
                continue;
            }

            $this->library->sendToAgent(
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_DUE,
                new UserSecondFactorResetDueSignalData(
                    $reset->userId,
                    (int)$reset->id,
                    HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_DUE_DONE,
                ),
            );
        }

        $staleBefore = date('Y-m-d H:i:s', time() - TimeConstants::SECONDS_PER_DAY);
        foreach (Hilos::$db->secondFactorResets->reminderDueBy($now, $staleBefore) as $reset) {
            if ($reset->readCancelToken() === null) {
                throw new LogicException('A standing second-factor removal ' . $reset->id . ' has no cancel token');
            }
            if (!$this->addressable($reset->userId)) {
                continue;
            }

            $this->library->sendToAgent(
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_REMIND,
                new UserSecondFactorResetRemindSignalData(
                    $reset->userId,
                    (int)$reset->id,
                    $staleBefore,
                    HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_REMIND_DONE,
                ),
            );
        }
    }

    /**
     * @param int $userId Person whose removal was found
     * @return bool Whether the person's agent may be woken for it
     * @throws HilosException When the person or the merges cannot be read
     */
    private function addressable(int $userId): bool
    {
        try {
            AddressablePerson::require($userId);
        } catch (ValidationException $refusal) {
            Logger::warning("Second-factor removal of #{$userId} skipped: {$refusal->getMessage()}");

            return false;
        }

        return true;
    }
}
