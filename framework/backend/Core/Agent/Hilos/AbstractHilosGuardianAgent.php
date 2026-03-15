<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Hilos;

use Hilos\Constants\HilosAgentType;

/**
 * AbstractHilosGuardianAgent - Abstract agent for Hilos guardian page (project validation robots).
 *
 * Projects must extend this class to provide a concrete agent for the Hilos guardian page.
 * If not extended, the guardian page will not function.
 */
abstract class AbstractHilosGuardianAgent extends AbstractHilosAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_GUARDIAN;
}
