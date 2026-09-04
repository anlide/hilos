<?php

declare(strict_types=1);

namespace Demo\Polls\Pages\Hilos;

use Demo\Polls\Constants\AgentType;
use Hilos\Pages\AbstractHilosLicensePage;

/**
 * LicensePage - License page implementation for the polls demo.
 *
 * Static, content-only: the framework page sends no payload; the visible
 * content is the frontend view. Only the owning agent type is bound here.
 */
final class LicensePage extends AbstractHilosLicensePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
