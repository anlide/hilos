<?php

declare(strict_types=1);

namespace Hilos\Core\Feature\Definition;

use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Core\Feature\FeatureRequirements;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Migration\FrameworkSchemaCatalog;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogDashboardPage;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogFeedPage;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogNoisyPage;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogTablePage;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogTablesPage;
use Hilos\Tables\ChangeLog\HilosChangeLogFeedTable;
use Hilos\Tables\ChangeLog\HilosChangeLogHistoryTable;
use Hilos\Tables\ChangeLog\HilosChangeLogNoisyColumnsTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTablesTable;

/** The derived database journal and its framework admin section. */
final class ChangeLogFeature extends FeatureDefinition
{
    /** @return HilosFeature Change log feature case */
    public function feature(): HilosFeature
    {
        return HilosFeature::CHANGE_LOG;
    }

    /** @return FeatureRequirements Five pages and their tables, the reader agent and trigger generator command */
    public function requirements(): FeatureRequirements
    {
        return new FeatureRequirements(
            requiredPages: [
                AbstractHilosChangeLogDashboardPage::class,
                AbstractHilosChangeLogFeedPage::class,
                AbstractHilosChangeLogNoisyPage::class,
                AbstractHilosChangeLogTablesPage::class,
                AbstractHilosChangeLogTablePage::class,
            ],
            requiredAgents: [HilosAgentType::HILOS_CHANGE_LOG],
            requiredTables: [
                HilosChangeLogFeedTable::class,
                HilosChangeLogTablesTable::class,
                HilosChangeLogHistoryTable::class,
                HilosChangeLogNoisyColumnsTable::class,
            ],
            requiredPageTables: [
                AbstractHilosChangeLogFeedPage::class => HilosChangeLogFeedTable::class,
                AbstractHilosChangeLogNoisyPage::class => HilosChangeLogNoisyColumnsTable::class,
                AbstractHilosChangeLogTablesPage::class => HilosChangeLogTablesTable::class,
                AbstractHilosChangeLogTablePage::class => HilosChangeLogHistoryTable::class,
            ],
            requiredCliCommands: [CliCommands::CHANGE_LOG_GENERATE],
            requiredSchemaUnits: [FrameworkSchemaCatalog::CHANGE_LOG],
        );
    }
}
