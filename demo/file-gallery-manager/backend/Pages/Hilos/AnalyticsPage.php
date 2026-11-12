<?php

declare(strict_types=1);

namespace Demo\FileGalleryManager\Pages\Hilos;

use Demo\FileGalleryManager\Constants\AgentType;
use Hilos\Pages\AbstractHilosAnalyticsPage;

/** Analytics section page for the file-gallery-manager demo. */
final class AnalyticsPage extends AbstractHilosAnalyticsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_ANALYTICS;
}
