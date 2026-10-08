<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Hilos;

use Hilos\Constants\HilosAgentType;
use Hilos\Database\ChangeLog\ChangeLogSectionReader;

/** Cluster reader for the Change Log section, hosted in a dedicated worker. */
abstract class AbstractHilosChangeLogAgent extends AbstractHilosAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_CHANGE_LOG;

    /**
     * @return ChangeLogSectionReader Read-only data source for pages served by this agent
     */
    public function sectionReader(): ChangeLogSectionReader
    {
        return new ChangeLogSectionReader();
    }
}
