<?php

declare(strict_types=1);

namespace Demo\FileGalleryManager\Pages\Hilos\Logs;

use Demo\FileGalleryManager\Constants\AgentType;
use Hilos\Pages\Logs\AbstractHilosLogsRotationsPage;

/**
 * LogsRotationsPage - Hilos logs rotation history page implementation for the file-gallery-manager demo.
 */
final class LogsRotationsPage extends AbstractHilosLogsRotationsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LOGS;
}
