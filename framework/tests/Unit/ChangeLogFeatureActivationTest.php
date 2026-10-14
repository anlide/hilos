<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Hilos\AbstractHilosChangeLogAgent;
use Hilos\Core\Feature\Definition\ChangeLogFeature;
use Hilos\Core\Feature\Exception\IncompleteFeatureActivationException;
use Hilos\Core\Feature\FeatureRegistry;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos as HilosFacade;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogDashboardPage;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogTablePage;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogTablesPage;
use Hilos\Tables\ChangeLog\HilosChangeLogFeedTable;
use Hilos\Tables\ChangeLog\HilosChangeLogHistoryTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTablesTable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exercises activation through the real validator, independently of Backup. */
final class ChangeLogFeatureActivationTest extends TestCase
{
    public function testDefinitionHasNoPrimaryDbOrRuntimeRequirement(): void
    {
        $definition = (new FeatureRegistry())->definition(HilosFeature::CHANGE_LOG);
        self::assertInstanceOf(ChangeLogFeature::class, $definition);
        self::assertFalse($definition->mountsRuntime());
        self::assertSame([], $definition->requirements()->requires);
        self::assertSame([], $definition->requirements()->requiredDbTables);
        self::assertSame([CliCommands::CHANGE_LOG_GENERATE], $definition->requirements()->requiredCliCommands);
    }

    public function testCompleteFeaturePassesStartupValidation(): void
    {
        ChangeLogFeatureCompleteHilos::validateFeatureActivation();
        $this->addToAssertionCount(1);
    }

    /** @return array<string, array{class-string<HilosFacade>, string}> Missing registrations and their refusals */
    public static function incompleteActivations(): array
    {
        return [
            'dashboard page' => [ChangeLogFeatureMissingDashboardHilos::class, AbstractHilosChangeLogDashboardPage::class],
            'tables page' => [ChangeLogFeatureMissingTablesPageHilos::class, AbstractHilosChangeLogTablesPage::class],
            'table page' => [ChangeLogFeatureMissingTablePageHilos::class, AbstractHilosChangeLogTablePage::class],
            'reader' => [ChangeLogFeatureMissingAgentHilos::class, 'is not registered in AGENTS'],
            'feed table' => [ChangeLogFeatureMissingFeedHilos::class, HilosChangeLogFeedTable::class],
            'tables table' => [ChangeLogFeatureMissingTablesHilos::class, HilosChangeLogTablesTable::class],
            'history table' => [ChangeLogFeatureMissingHistoryHilos::class, HilosChangeLogHistoryTable::class],
            'dashboard binding' => [ChangeLogFeatureMissingDashboardBindingHilos::class, 'PAGE_TABLES'],
            'tables binding' => [ChangeLogFeatureMissingTablesBindingHilos::class, 'PAGE_TABLES'],
            'table binding' => [ChangeLogFeatureMissingHistoryBindingHilos::class, 'PAGE_TABLES'],
            'undeclared feature' => [ChangeLogFeatureUndeclaredHilos::class, 'is not declared in FEATURES'],
        ];
    }

    /**
     * @param class-string<HilosFacade> $facade Incomplete activation
     * @param string $message Missing obligation
     */
    #[DataProvider('incompleteActivations')]
    public function testIncompleteActivationRefusesStartup(string $facade, string $message): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage($message);
        $facade::validateFeatureActivation();
    }
}

class ChangeLogFeatureCompleteHilos extends HilosFacade
{
    public const array PAGES = [
        ChangeLogFeatureDashboardPage::PAGE => ChangeLogFeatureDashboardPage::class,
        ChangeLogFeatureTablesPage::PAGE => ChangeLogFeatureTablesPage::class,
        ChangeLogFeatureTablePage::PAGE => ChangeLogFeatureTablePage::class,
    ];

    public const array AGENTS = [
        HilosAgentType::HILOS_CHANGE_LOG => [
            AgentRegistryKey::WORKER => ChangeLogFeatureAgent::class,
            AgentRegistryKey::DAEMON => ChangeLogFeatureAgentDaemon::class,
        ],
    ];

    public const array TABLES = [
        HilosChangeLogFeedTable::TABLE => HilosChangeLogFeedTable::class,
        HilosChangeLogTablesTable::TABLE => HilosChangeLogTablesTable::class,
        HilosChangeLogHistoryTable::TABLE => HilosChangeLogHistoryTable::class,
    ];

    public const array PAGE_TABLES = [
        ChangeLogFeatureDashboardPage::PAGE => [HilosChangeLogFeedTable::TABLE => []],
        ChangeLogFeatureTablesPage::PAGE => [HilosChangeLogTablesTable::TABLE => []],
        ChangeLogFeatureTablePage::PAGE => [HilosChangeLogHistoryTable::TABLE => []],
    ];

    protected const array FEATURES = [HilosFeature::CHANGE_LOG];

    /** @return HilosDbContext Unused context required by the facade contract */
    protected static function createDb(): HilosDbContext
    {
        return new ChangeLogFeatureTestDbContext();
    }
}

final class ChangeLogFeatureTestDbContext extends HilosDbContext
{
    /** No data collections are used by activation validation. */
    public function configure(): void
    {
    }
}

final class ChangeLogFeatureDashboardPage extends AbstractHilosChangeLogDashboardPage
{
}

final class ChangeLogFeatureTablesPage extends AbstractHilosChangeLogTablesPage
{
}

final class ChangeLogFeatureTablePage extends AbstractHilosChangeLogTablePage
{
}

final class ChangeLogFeatureAgent extends AbstractHilosChangeLogAgent
{
}

final class ChangeLogFeatureAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_CHANGE_LOG;

    /** @return bool The reader runs in a dedicated worker */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}

final class ChangeLogFeatureMissingAgentHilos extends ChangeLogFeatureCompleteHilos
{
    public const array AGENTS = [];
}

final class ChangeLogFeatureUndeclaredHilos extends ChangeLogFeatureCompleteHilos
{
    protected const array FEATURES = [];
}

final class ChangeLogFeatureMissingDashboardHilos extends ChangeLogFeatureCompleteHilos
{
    public const array PAGES = [
        ChangeLogFeatureTablesPage::PAGE => ChangeLogFeatureTablesPage::class,
        ChangeLogFeatureTablePage::PAGE => ChangeLogFeatureTablePage::class,
    ];
}

final class ChangeLogFeatureMissingTablesPageHilos extends ChangeLogFeatureCompleteHilos
{
    public const array PAGES = [
        ChangeLogFeatureDashboardPage::PAGE => ChangeLogFeatureDashboardPage::class,
        ChangeLogFeatureTablePage::PAGE => ChangeLogFeatureTablePage::class,
    ];
}

final class ChangeLogFeatureMissingTablePageHilos extends ChangeLogFeatureCompleteHilos
{
    public const array PAGES = [
        ChangeLogFeatureDashboardPage::PAGE => ChangeLogFeatureDashboardPage::class,
        ChangeLogFeatureTablesPage::PAGE => ChangeLogFeatureTablesPage::class,
    ];
}

final class ChangeLogFeatureMissingFeedHilos extends ChangeLogFeatureCompleteHilos
{
    public const array TABLES = [
        HilosChangeLogTablesTable::TABLE => HilosChangeLogTablesTable::class,
        HilosChangeLogHistoryTable::TABLE => HilosChangeLogHistoryTable::class,
    ];
}

final class ChangeLogFeatureMissingTablesHilos extends ChangeLogFeatureCompleteHilos
{
    public const array TABLES = [
        HilosChangeLogFeedTable::TABLE => HilosChangeLogFeedTable::class,
        HilosChangeLogHistoryTable::TABLE => HilosChangeLogHistoryTable::class,
    ];
}

final class ChangeLogFeatureMissingHistoryHilos extends ChangeLogFeatureCompleteHilos
{
    public const array TABLES = [
        HilosChangeLogFeedTable::TABLE => HilosChangeLogFeedTable::class,
        HilosChangeLogTablesTable::TABLE => HilosChangeLogTablesTable::class,
    ];
}

final class ChangeLogFeatureMissingDashboardBindingHilos extends ChangeLogFeatureCompleteHilos
{
    public const array PAGE_TABLES = [
        ChangeLogFeatureTablesPage::PAGE => [HilosChangeLogTablesTable::TABLE => []],
        ChangeLogFeatureTablePage::PAGE => [HilosChangeLogHistoryTable::TABLE => []],
    ];
}

final class ChangeLogFeatureMissingTablesBindingHilos extends ChangeLogFeatureCompleteHilos
{
    public const array PAGE_TABLES = [
        ChangeLogFeatureDashboardPage::PAGE => [HilosChangeLogFeedTable::TABLE => []],
        ChangeLogFeatureTablePage::PAGE => [HilosChangeLogHistoryTable::TABLE => []],
    ];
}

final class ChangeLogFeatureMissingHistoryBindingHilos extends ChangeLogFeatureCompleteHilos
{
    public const array PAGE_TABLES = [
        ChangeLogFeatureDashboardPage::PAGE => [HilosChangeLogFeedTable::TABLE => []],
        ChangeLogFeatureTablesPage::PAGE => [HilosChangeLogTablesTable::TABLE => []],
    ];
}
