<?php

declare(strict_types=1);

namespace Demo\Pmd\Pages\Hilos;

use Demo\Pmd\Agents\PmdAgent;
use Demo\Pmd\Constants\AgentType;
use Demo\Pmd\Database\PmdDbContext;
use Hilos\Pages\AbstractHilosProfileSessionsPage;

/**
 * Pmd binding of the framework current-user sessions page.
 *
 * @property PmdAgent $agent
 */
final class ProfileSessionsPage extends AbstractHilosProfileSessionsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::PMD;
    public const array READS_DB = [...parent::READS_DB, PmdDbContext::sessions];
}
