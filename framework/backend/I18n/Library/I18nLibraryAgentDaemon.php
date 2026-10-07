<?php

declare(strict_types=1);

namespace Hilos\I18n\Library;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/** Proxy for the one cluster-wide writer of the i18n reference. */
final class I18nLibraryAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_I18N_LIBRARY;

    /** @return bool True because one process must hold every reference-table claim */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
