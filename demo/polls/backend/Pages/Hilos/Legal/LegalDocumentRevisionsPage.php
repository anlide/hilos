<?php

declare(strict_types=1);

namespace Demo\Polls\Pages\Hilos\Legal;

use Demo\Polls\Constants\AgentType;
use Hilos\Pages\Legal\AbstractHilosLegalDocumentRevisionsPage;

/** Binds the document subscription to the demo's dedicated legal agent. */
final class LegalDocumentRevisionsPage extends AbstractHilosLegalDocumentRevisionsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LEGAL;
}
