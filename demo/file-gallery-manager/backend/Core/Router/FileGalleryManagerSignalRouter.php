<?php

declare(strict_types=1);

namespace Demo\FileGalleryManager\Core\Router;

use Demo\FileGalleryManager\Constants\AgentType;
use Demo\FileGalleryManager\Hilos;
use Hilos\Core\Router\SignalRouter;

/**
 * FileGalleryManagerSignalRouter - Signal router for the file-gallery-manager demo.
 *
 * Declares demo service-signal defaults. Page subscription, page actions, and
 * page-owned signals are resolved by framework SignalRouter from project
 * topology.
 */
final class FileGalleryManagerSignalRouter extends SignalRouter
{
    /**
     * Returns the file-gallery-manager project facade for topology registry reads.
     *
     * @return class-string<Hilos> FileGalleryManager project facade class
     */
    protected function hilosClass(): string
    {
        return Hilos::class;
    }

    /**
     * Returns file-gallery-manager agents started on DAEMON/SYSTEM bootstrap signals.
     *
     * @return list<string> Agent type identifiers
     */
    protected function getDefaultSystemBootstrapAgentTypes(): array
    {
        return [
            AgentType::FILE_GALLERY_MANAGER,
            AgentType::HILOS_INDEX,
            AgentType::HILOS_LOGS,
        ];
    }

    /**
     * Returns the file-gallery-manager owner for WebSocket lifecycle service signals.
     *
     * @return ?string Fallback agent type
     */
    protected function getDefaultWebSocketLifecycleAgentType(): ?string
    {
        return AgentType::FILE_GALLERY_MANAGER;
    }
}
