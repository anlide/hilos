<?php

declare(strict_types=1);

namespace Demo\FileGalleryManager\Pages\Hilos;

use Demo\FileGalleryManager\Agents\FileGalleryManagerAgent;
use Demo\FileGalleryManager\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfilePage;

/**
 * File Gallery Manager binding of the framework current-user profile page.
 *
 * Binds the app agent that serves it. Account writes remain on the users library; the profile
 * is a reading surface.
 *
 * @property FileGalleryManagerAgent $agent
 */
final class ProfilePage extends AbstractHilosProfilePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::FILE_GALLERY_MANAGER;
}
