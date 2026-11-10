<?php

declare(strict_types=1);

namespace Demo\Pmd\Pages\Hilos;

use Demo\Pmd\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfileDataPage;

/** Binds the framework's personal-data page to the PMD agent. */
final class ProfileDataPage extends AbstractHilosProfileDataPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::PMD;
}
