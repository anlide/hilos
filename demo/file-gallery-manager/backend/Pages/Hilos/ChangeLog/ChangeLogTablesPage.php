<?php

declare(strict_types=1);

namespace Demo\FileGalleryManager\Pages\Hilos\ChangeLog;

use Demo\FileGalleryManager\Constants\AgentType;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogTablesPage;

/**
 * ChangeLogTablesPage - Hilos change log tracked tables list for the file-gallery-manager demo.
 */
final class ChangeLogTablesPage extends AbstractHilosChangeLogTablesPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_CHANGE_LOG;
}
