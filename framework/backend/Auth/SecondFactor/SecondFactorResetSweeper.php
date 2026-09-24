<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\Command\SecondFactorCommands;
use Hilos\Constants\TimeConstants;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * SecondFactorResetSweeper - carries out the removals whose time came, and reminds of the rest (HIL-494).
 *
 * Run from the users library's tick ({@see AbstractUsersLibraryAgent}) once a minute. A removal
 * whose moment has passed is marked carried out by a conditional write first - a cancel that
 * won the same minute leaves the factor where it was - and only then is the factor taken out
 * and the removal announced. A removal still waiting is announced again once a day.
 */
final class SecondFactorResetSweeper
{
    /**
     * @param SecondFactorCommands $commands The second factor's commands, which take a factor out and fan the section
     */
    public function __construct(private readonly SecondFactorCommands $commands)
    {
    }

    /**
     * Carries out the removals due and reminds of the ones still waiting.
     *
     * @throws HilosException When a lookup, a write, a frame or an announcement fails
     */
    public function sweep(): void
    {
        $now = TimeHelper::getSqlDateTime();

        foreach (Hilos::$db->secondFactorResets->dueBy($now) as $reset) {
            if (!$reset->actions->complete()) {
                continue;
            }

            $this->commands->switchOff($reset->userId);
            SecondFactorResetNotifier::completed($reset->userId);
            $this->commands->publishState($reset->userId);
        }

        $staleBefore = date('Y-m-d H:i:s', time() - TimeConstants::SECONDS_PER_DAY);
        foreach (Hilos::$db->secondFactorResets->reminderDueBy($now, $staleBefore) as $reset) {
            SecondFactorResetNotifier::reminder($reset->userId, (int)strtotime($reset->effectiveAt));
            $reset->actions->markNotified();
        }
    }
}
