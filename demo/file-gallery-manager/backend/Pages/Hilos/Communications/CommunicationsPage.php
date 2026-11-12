<?php

declare(strict_types=1);

namespace Demo\FileGalleryManager\Pages\Hilos\Communications;

use Demo\FileGalleryManager\Constants\AgentType;
use Hilos\Pages\Communications\AbstractHilosCommunicationsPage;

/**
 * CommunicationsPage - Hilos communications hub for the file-gallery-manager demo.
 */
final class CommunicationsPage extends AbstractHilosCommunicationsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
