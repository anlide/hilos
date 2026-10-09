<?php

declare(strict_types=1);

namespace Demo\Polls\Pages\Hilos;

use Demo\Polls\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfileAgreementsHistoryPage;

/** Binds the personal legal section to the polls subscription owner. */
final class ProfileAgreementsHistoryPage extends AbstractHilosProfileAgreementsHistoryPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::POLLS;
}
