<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Agents\Hilos;

use Demo\SimpleTodo\Database\TodoDbContext;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;

/**
 * DemoHilosAgent - Concrete Hilos index agent for the simple-todo demo.
 *
 * Owns the framework Hilos admin pages (dashboard, settings, users). Registers
 * the standalone user-rename audit collection as a truth source so the users
 * page's rename action — handled in this agent — may append audit rows.
 */
final class DemoHilosAgent extends AbstractHilosIndexAgent
{
    /**
     * Registers the rename-audit collection as this agent's truth source.
     */
    public function onStart(): void
    {
        parent::onStart();

        $this->registerDbTruthSource(TodoDbContext::eventUserRenames);
    }
}
