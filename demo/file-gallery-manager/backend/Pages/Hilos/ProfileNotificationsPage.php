<?php

declare(strict_types=1);

namespace Demo\FileGalleryManager\Pages\Hilos;

use Demo\FileGalleryManager\Agents\FileGalleryManagerAgent;
use Demo\FileGalleryManager\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfileNotificationsPage;

/**
 * File Gallery Manager binding of the framework current-user notification channels page.
 *
 * @property FileGalleryManagerAgent $agent
 */
final class ProfileNotificationsPage extends AbstractHilosProfileNotificationsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::FILE_GALLERY_MANAGER;
}
