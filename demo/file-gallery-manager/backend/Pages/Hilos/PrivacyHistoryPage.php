<?php

declare(strict_types=1);

namespace Demo\FileGalleryManager\Pages\Hilos;

use Demo\FileGalleryManager\Constants\AgentType;
use Hilos\Pages\AbstractHilosPrivacyHistoryPage;

/** Binds the public Privacy history page to the index agent. */
final class PrivacyHistoryPage extends AbstractHilosPrivacyHistoryPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
