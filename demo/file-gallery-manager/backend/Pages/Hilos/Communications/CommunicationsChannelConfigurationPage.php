<?php

declare(strict_types=1);

namespace Demo\FileGalleryManager\Pages\Hilos\Communications;

use Demo\FileGalleryManager\Constants\AgentType;
use Hilos\Pages\Communications\AbstractHilosCommunicationsChannelConfigurationPage;

/** Channel configuration subscription served by the file-gallery-manager demo's index agent. */
final class CommunicationsChannelConfigurationPage extends AbstractHilosCommunicationsChannelConfigurationPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
