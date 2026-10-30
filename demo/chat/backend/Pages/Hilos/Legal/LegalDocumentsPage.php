<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Legal;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Legal\AbstractHilosLegalDocumentsPage;

/** Binds the Documents subscription to the demo's dedicated legal agent. */
final class LegalDocumentsPage extends AbstractHilosLegalDocumentsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LEGAL;
}
