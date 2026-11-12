<?php

declare(strict_types=1);

namespace Demo\FileGalleryManager\Pages;

use Demo\FileGalleryManager\Agents\FileGalleryManagerAgent;
use Demo\FileGalleryManager\Constants\AgentType;
use Demo\FileGalleryManager\Constants\PageConstants;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageReach;

/**
 * MainPage - The home page of the file-gallery-manager demo.
 *
 * Declares no actions or signals: the home says who is looking and nothing more, and the name
 * it shows comes with the session identity every page already has. The demo has no product of
 * its own for this page to show.
 *
 * @property FileGalleryManagerAgent $agent
 */
final class MainPage extends AbstractPage
{
    public const string PAGE = PageConstants::MAIN;

    public const PageReach REACH = PageReach::ROUTE;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::FILE_GALLERY_MANAGER;
}
