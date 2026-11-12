<?php

declare(strict_types=1);

namespace Demo\FileGalleryManager\Core\Agent\Daemon;

use Demo\FileGalleryManager\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for the file-gallery-manager agent.
 */
final class FileGalleryManagerAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = AgentType::FILE_GALLERY_MANAGER;

    /**
     * The app agent owns the connections registry and answers the WebSocket lifecycle, so it
     * must be single-owner.
     *
     * @return bool True because who is on the wire is shared state with one writer
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
