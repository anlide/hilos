<?php

declare(strict_types=1);

namespace Demo\Polls\Pages\Hilos\Legal;

use Demo\Polls\Constants\AgentType;
use Hilos\Pages\Legal\AbstractHilosLegalSettingsPage;

/** Binds the legal subscription to the demo's dedicated section agent. */
final class LegalSettingsPage extends AbstractHilosLegalSettingsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LEGAL;
}
