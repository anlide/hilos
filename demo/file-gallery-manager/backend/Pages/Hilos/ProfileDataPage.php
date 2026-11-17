<?php

declare(strict_types=1);

namespace Demo\FileGalleryManager\Pages\Hilos;

use Demo\FileGalleryManager\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfileDataPage;

/** Binds the framework's personal-data page to the file-gallery-manager agent. */
final class ProfileDataPage extends AbstractHilosProfileDataPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::FILE_GALLERY_MANAGER;
}
