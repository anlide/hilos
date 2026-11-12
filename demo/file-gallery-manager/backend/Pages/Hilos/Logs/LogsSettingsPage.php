<?php

declare(strict_types=1);

namespace Demo\FileGalleryManager\Pages\Hilos\Logs;

use Demo\FileGalleryManager\Constants\AgentType;
use Hilos\Pages\Logs\AbstractHilosLogsSettingsPage;

/**
 * LogsSettingsPage - Hilos logging modes page implementation for the file-gallery-manager demo.
 */
final class LogsSettingsPage extends AbstractHilosLogsSettingsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LOGS;
}
