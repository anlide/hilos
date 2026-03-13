<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Hilos;

use Hilos\Constants\HilosAgentType;

/**
 * AbstractHilosIndexAgent - Abstract agent for Hilos dashboard, settings and i18n pages
 *
 * Projects must extend this class to provide a concrete agent for Hilos index pages
 * (dashboard, settings, i18n). If not extended, the corresponding pages will not function.
 */
abstract class AbstractHilosIndexAgent extends AbstractHilosAgent
{
    /**
     * Return agent type identifier.
     *
     * @return string Agent type
     */
    public function getType(): string
    {
        return HilosAgentType::HILOS_INDEX;
    }
}
