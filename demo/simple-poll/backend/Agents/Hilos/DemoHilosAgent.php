<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Agents\Hilos;

use Demo\SimplePoll\Database\PollDbContext;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;

/**
 * DemoHilosAgent - Concrete Hilos index agent for the simple-poll demo.
 *
 * Owns the framework Hilos admin pages (dashboard, settings, users). Registers
 * the standalone user-rename audit collection as a truth source so the users
 * page's rename action — handled in this agent — may append audit rows.
 *
 * The admin grant seam is NOT here any more (HIL-729): the command moved to the
 * sessions library, because what it writes ends in a person's open tabs being told.
 */
final class DemoHilosAgent extends AbstractHilosIndexAgent
{
    /**
     * Registers the rename-audit collection as this agent's truth source.
     */
    public function onStart(): void
    {
        parent::onStart();

        $this->registerDbTruthSource(PollDbContext::userRenames);
    }
}
