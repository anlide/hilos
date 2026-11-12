<?php

declare(strict_types=1);

namespace Demo\FileGalleryManager\Pages\Hilos\Logs;

use Demo\FileGalleryManager\Constants\AgentType;
use Hilos\Pages\Logs\AbstractHilosLogsKeysPage;

/**
 * LogsKeysPage - Hilos logs by key list page implementation for the file-gallery-manager demo.
 */
final class LogsKeysPage extends AbstractHilosLogsKeysPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LOGS;
}
