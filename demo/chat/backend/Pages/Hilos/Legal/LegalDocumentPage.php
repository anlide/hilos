<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Legal;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Legal\AbstractHilosLegalDocumentPage;

/** Binds the legal subscription to the demo's dedicated section agent. */
final class LegalDocumentPage extends AbstractHilosLegalDocumentPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LEGAL;
}
