<?php

declare(strict_types=1);

namespace Hilos\I18n\Library;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Hilos\AbstractHilosAgent;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;

/** Cluster library that serves i18n section pages and owns its five reference tables. */
final class I18nLibraryAgent extends AbstractHilosAgent
{
    /** @var array<string, list<TruthSourceOperation>> Whole-table write claims */
    public const array OWNS_DB = [
        HilosDbContext::languages => TruthSourceOperation::ALL,
        HilosDbContext::countries => TruthSourceOperation::ALL,
        HilosDbContext::locales => TruthSourceOperation::ALL,
        HilosDbContext::languageNames => TruthSourceOperation::ALL,
        HilosDbContext::countryNames => TruthSourceOperation::ALL,
    ];

    public const string AGENT_TYPE = HilosAgentType::HILOS_I18N_LIBRARY;
}
