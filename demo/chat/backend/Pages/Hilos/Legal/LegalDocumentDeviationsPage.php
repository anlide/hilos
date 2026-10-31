<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Legal;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Legal\AbstractHilosLegalDocumentDeviationsPage;

/** Binds the document subscription to the demo's dedicated legal agent. */
final class LegalDocumentDeviationsPage extends AbstractHilosLegalDocumentDeviationsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LEGAL;
}
