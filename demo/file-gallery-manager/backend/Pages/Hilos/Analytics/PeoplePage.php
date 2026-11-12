<?php

declare(strict_types=1);

namespace Demo\FileGalleryManager\Pages\Hilos\Analytics;

use Demo\FileGalleryManager\Constants\AgentType;
use Hilos\Pages\Analytics\AbstractHilosAnalyticsPeoplePage;

/** People analytics subscription, served by the demo's Analytics agent. */
final class PeoplePage extends AbstractHilosAnalyticsPeoplePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_ANALYTICS;
}
