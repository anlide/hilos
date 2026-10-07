<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Hilos;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Analytics\AnalyticsSectionReader;

/**
 * AbstractHilosAnalyticsAgent - Cluster reader for the Hilos analytics section.
 *
 * A project's thin subclass hosts its admin pages in a monopolistic worker. Their
 * bounded SQL reads go through the typed section reader, not the journal writer.
 */
abstract class AbstractHilosAnalyticsAgent extends AbstractHilosAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_ANALYTICS;

    /**
     * @return AnalyticsSectionReader Read-only data source for pages served by this agent
     */
    public function sectionReader(): AnalyticsSectionReader
    {
        return new AnalyticsSectionReader();
    }
}
