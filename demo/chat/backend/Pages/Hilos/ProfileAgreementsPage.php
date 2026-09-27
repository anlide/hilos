<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfileAgreementsPage;

/** Binds the personal legal section to the chat subscription owner. */
final class ProfileAgreementsPage extends AbstractHilosProfileAgreementsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CHAT;
}
