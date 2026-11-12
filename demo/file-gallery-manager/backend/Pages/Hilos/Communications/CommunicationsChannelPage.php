<?php

declare(strict_types=1);

namespace Demo\FileGalleryManager\Pages\Hilos\Communications;

use Demo\FileGalleryManager\Constants\AgentType;
use Hilos\Pages\Communications\AbstractHilosCommunicationsChannelPage;

/**
 * CommunicationsChannelPage - Single channel config for the file-gallery-manager demo.
 */
final class CommunicationsChannelPage extends AbstractHilosCommunicationsChannelPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
