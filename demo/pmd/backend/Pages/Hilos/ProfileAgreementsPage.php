<?php

declare(strict_types=1);

namespace Demo\Pmd\Pages\Hilos;

use Demo\Pmd\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfileAgreementsPage;

/** PMD binding of the framework current-user legal agreements page. */
final class ProfileAgreementsPage extends AbstractHilosProfileAgreementsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::PMD;
}
