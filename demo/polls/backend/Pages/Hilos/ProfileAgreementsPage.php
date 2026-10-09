<?php

declare(strict_types=1);

namespace Demo\Polls\Pages\Hilos;

use Demo\Polls\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfileAgreementsPage;

/** Binds the personal legal section to the polls subscription owner. */
final class ProfileAgreementsPage extends AbstractHilosProfileAgreementsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::POLLS;
}
