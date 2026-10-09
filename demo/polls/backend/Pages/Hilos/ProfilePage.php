<?php

declare(strict_types=1);

namespace Demo\Polls\Pages\Hilos;

use Demo\Polls\Constants\AgentType;
use Demo\Polls\Database\PollsDbContext;
use Hilos\Pages\AbstractHilosProfilePage;

/**
 * Polls demo binding of the framework's profile root (HIL-1169).
 *
 * The framework owns the page and reads the person's sections; this binds the agent that serves it
 * and carries the session count for the active sign-ins row.
 */
final class ProfilePage extends AbstractHilosProfilePage
{
    /**
     * @var list<string> The framework page's list and the sessions the active sign-ins summary
     *     counts; it replaces the parent's, so it carries the parent's whole.
     */
    public const array READS_DB = [...parent::READS_DB, PollsDbContext::sessions];

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::POLLS;
}
