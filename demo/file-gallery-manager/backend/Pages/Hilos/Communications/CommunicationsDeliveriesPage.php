<?php

declare(strict_types=1);

namespace Demo\FileGalleryManager\Pages\Hilos\Communications;

use Demo\FileGalleryManager\Constants\AgentType;
use Hilos\Pages\Communications\AbstractHilosCommunicationsDeliveriesPage;

/**
 * CommunicationsDeliveriesPage - Channel deliveries page of the file-gallery-manager demo, drawn over the
 * notification deliveries table.
 */
final class CommunicationsDeliveriesPage extends AbstractHilosCommunicationsDeliveriesPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
