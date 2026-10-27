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
use Hilos\Database\Actions\Collection\ChangeLogReceiptsActions;
use Hilos\Database\Actions\Item\ChangeLogReceiptActions;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Context\FrameworkExtension;
use Hilos\Database\Exception\IncompleteFrameworkExtensionException;
use Hilos\Database\Entity\Collection\ChangeLogReceipts as EntityChangeLogReceipts;
use Hilos\Database\Object\Item\ChangeLogReceipt as ObjectChangeLogReceipt;
use Hilos\Database\Object\Collection\ChangeLogReceipts as ObjectChangeLogReceipts;
use Hilos\Database\View\Item\ChangeLogReceipt as DbItemChangeLogReceipt;
use Hilos\Database\View\Collection\ChangeLogReceipts as DbCollectionChangeLogReceipts;
use Hilos\Database\Database;
use Hilos\Database\DatabaseNames;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\Object\Objects;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Database\Schema\FrameworkExtensionGuard;
use ReflectionProperty;
use Hilos\Database\Entity\Item\ChangeLogTable;
use Hilos\Database\Entity\Item\ChangeLogField;
use Hilos\Database\Entity\Item\ChangeLogReceipt;
use Hilos\Database\Entity\Item\ChangeLogEntry;
use Hilos\Database\Entity\Item\ChangeLogChange;
use Hilos\Database\Entity\Item\ChangeLogValue;
use Hilos\Hilos as HilosFacade;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogDashboardPage;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogFeedPage;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogNoisyPage;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogTablePage;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogTablesPage;
use Hilos\Tables\ChangeLog\HilosChangeLogFeedTable;
use Hilos\Tables\ChangeLog\HilosChangeLogHistoryTable;
use Hilos\Tables\ChangeLog\HilosChangeLogNoisyColumnsTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTablesTable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exercises activation through the real validator, independently of Backup. */
final class ChangeLogFeatureActivationTest extends TestCase
{
    public function testRealContextMountsJournalOnlyWhenFeatureIsEnabledWithoutSqlOrImplicitReaders(): void
    {
        $appClass = new ReflectionProperty(HilosFacade::class, 'appClass');
        $previous = $appClass->getValue();
        $previousDb = HilosFacade::$db;
        $properties = [];
        foreach (['configurations', 'databaseBindings'] as $property) {
            $properties[$property] = new ReflectionProperty(Database::class, $property)->getValue();
        }
        try {
            Database::configure(database: 'journal_mount_unit');
            Database::configure(index: 1, database: ChangeLogDatabase::configuredName());
            $expected = [
                HilosDbContext::changeLogTables => ChangeLogTable::class,
                HilosDbContext::changeLogFields => ChangeLogField::class,
                HilosDbContext::changeLogReceipts => ChangeLogReceipt::class,
                HilosDbContext::changeLogEntries => ChangeLogEntry::class,
                HilosDbContext::changeLogChanges => ChangeLogChange::class,
                HilosDbContext::changeLogValues => ChangeLogValue::class,
            ];
            foreach ([false, true] as $enabled) {
                $appClass->setValue(null, $enabled ? ChangeLogFeatureCompleteHilos::class : ChangeLogFeatureUndeclaredHilos::class);
                $context = new ChangeLogRealMountContext();
                $context->configure();
                $mounted = $context->getMountedEntities();
                self::assertSame($enabled ? $expected : [], array_intersect_key($mounted, $expected));
                foreach ($expected as $key => $entityClass) {
                    self::assertNotContains($key, $context->processReads());
                    self::assertFalse(SourceInterestRegistry::hasConsumers(SourceChange::KIND_DB, $key));
                    if (!$enabled) {
                        self::assertNull($context->mountedObjectCollection($key));
                        continue;
                    }
                    $store = $context->mountedObjectCollection($key);
                    self::assertFalse($store->cachesRows());
                    self::assertFalse($store->isAllLoaded());
                    self::assertSame(Objects::LAZY_STRATEGY_KEY, $store->getLazyStrategy());
                    self::assertSame(DatabaseNames::CHANGE_LOG, $entityClass::getDatabaseName());
                    self::assertSame(1, $entityClass::getDatabase()->schemaIndex);
                    self::assertSame(0, $entityClass::getDatabase()->connectionIndex);
                    if ($key === HilosDbContext::changeLogReceipts) {
                        self::assertSame(ChangeLogReceiptsActions::class, $context->frameworkRegistrations()[$key]->actions);
                        self::assertSame(ChangeLogReceiptActions::class, $context->frameworkRegistrations()[$key]->itemActions);
                        continue;
                    }
                    self::assertNull($context->frameworkRegistrations()[$key]->actions);
                    self::assertNull($context->frameworkRegistrations()[$key]->itemActions);
                }
                HilosFacade::$db = $context;
                FrameworkExtensionGuard::assertMountedExtensionsWhole();
            }
            $context = new ChangeLogExtendedMountContext();
            $context->configure();
            HilosFacade::$db = $context;
            self::assertSame(ExtendedJournalReceipt::class, $context->getMountedEntities()[HilosDbContext::changeLogReceipts]);
            self::assertInstanceOf(ExtendedJournalReceipts::class, $context->getDbItemCollection(HilosDbContext::changeLogReceipts));
            self::assertInstanceOf(ExtendedJournalObjectReceipts::class, $context->mountedObjectCollection(HilosDbContext::changeLogReceipts));
            self::assertSame(ExtendedJournalViewReceipt::class, ExtendedJournalReceipts::DB_ITEM_CLASS);
            self::assertSame(
                ChangeLogReceiptsActions::class,
                $context->frameworkRegistrations()[HilosDbContext::changeLogReceipts]->actions,
            );
            self::assertSame(
                ChangeLogReceiptActions::class,
                $context->frameworkRegistrations()[HilosDbContext::changeLogReceipts]->itemActions,
            );
            self::assertSame(
                ChangeLogReceiptsActions::class,
                $context->getDbItemCollection(HilosDbContext::changeLogReceipts)->getActionsClass(),
            );
            self::assertSame(
                ChangeLogReceiptActions::class,
                $context->getDbItemCollection(HilosDbContext::changeLogReceipts)->getItemActionsClass(),
            );
            self::assertFalse($context->mountedObjectCollection(HilosDbContext::changeLogReceipts)->cachesRows());
            self::assertFalse(Database::isConnected(0));
            self::assertFalse(Database::isConnected(1));
            // The extension names a view only. Both action layers stay the framework class, and the
            // guard refuses that half chain. The guard itself is unchanged.
            $refused = false;
            try {
                FrameworkExtensionGuard::assertMountedExtensionsWhole();
            } catch (IncompleteFrameworkExtensionException $refusal) {
                $refused = true;
                self::assertStringContainsString('changeLogReceipts', $refusal->getMessage());
                self::assertStringContainsString('collection actions', $refusal->getMessage());
                self::assertStringContainsString('item actions', $refusal->getMessage());
            }
            self::assertTrue($refused);
        } finally {
            $appClass->setValue(null, $previous);
            HilosFacade::$db = $previousDb;
            foreach ($properties as $property => $value) {
                new ReflectionProperty(Database::class, $property)->setValue(null, $value);
            }
        }
    }

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
        self::assertArrayNotHasKey(ChangeLogFeatureDashboardPage::PAGE, ChangeLogFeatureCompleteHilos::PAGE_TABLES);
    }

    /** @return array<string, array{class-string<HilosFacade>, string}> Missing registrations and their refusals */
    public static function incompleteActivations(): array
    {
        return [
            'dashboard page' => [ChangeLogFeatureMissingDashboardHilos::class, AbstractHilosChangeLogDashboardPage::class],
            'feed page' => [ChangeLogFeatureMissingFeedPageHilos::class, AbstractHilosChangeLogFeedPage::class],
            'noisy page' => [ChangeLogFeatureMissingNoisyPageHilos::class, AbstractHilosChangeLogNoisyPage::class],
            'noisy table' => [ChangeLogFeatureMissingNoisyHilos::class, HilosChangeLogNoisyColumnsTable::class],
            'noisy binding' => [ChangeLogFeatureMissingNoisyBindingHilos::class, 'PAGE_TABLES'],
            'tables page' => [ChangeLogFeatureMissingTablesPageHilos::class, AbstractHilosChangeLogTablesPage::class],
            'table page' => [ChangeLogFeatureMissingTablePageHilos::class, AbstractHilosChangeLogTablePage::class],
            'reader' => [ChangeLogFeatureMissingAgentHilos::class, 'is not registered in AGENTS'],
            'feed table' => [ChangeLogFeatureMissingFeedHilos::class, HilosChangeLogFeedTable::class],
            'tables table' => [ChangeLogFeatureMissingTablesHilos::class, HilosChangeLogTablesTable::class],
            'history table' => [ChangeLogFeatureMissingHistoryHilos::class, HilosChangeLogHistoryTable::class],
            'feed binding' => [ChangeLogFeatureMissingFeedBindingHilos::class, 'PAGE_TABLES'],
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
        ChangeLogFeatureNoisyPage::PAGE => ChangeLogFeatureNoisyPage::class,
        ChangeLogFeatureFeedPage::PAGE => ChangeLogFeatureFeedPage::class,
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
        HilosChangeLogNoisyColumnsTable::TABLE => HilosChangeLogNoisyColumnsTable::class,
    ];

    public const array PAGE_TABLES = [
        ChangeLogFeatureFeedPage::PAGE => [HilosChangeLogFeedTable::TABLE => []],
        ChangeLogFeatureNoisyPage::PAGE => [HilosChangeLogNoisyColumnsTable::TABLE => []],
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

final class ChangeLogFeatureFeedPage extends AbstractHilosChangeLogFeedPage
{
}

final class ChangeLogFeatureNoisyPage extends AbstractHilosChangeLogNoisyPage
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
        ChangeLogFeatureNoisyPage::PAGE => ChangeLogFeatureNoisyPage::class,
        ChangeLogFeatureFeedPage::PAGE => ChangeLogFeatureFeedPage::class,
        ChangeLogFeatureTablesPage::PAGE => ChangeLogFeatureTablesPage::class,
        ChangeLogFeatureTablePage::PAGE => ChangeLogFeatureTablePage::class,
    ];
}

final class ChangeLogFeatureMissingFeedPageHilos extends ChangeLogFeatureCompleteHilos
{
    public const array PAGES = [
        ChangeLogFeatureDashboardPage::PAGE => ChangeLogFeatureDashboardPage::class,
        ChangeLogFeatureNoisyPage::PAGE => ChangeLogFeatureNoisyPage::class,
        ChangeLogFeatureTablesPage::PAGE => ChangeLogFeatureTablesPage::class,
        ChangeLogFeatureTablePage::PAGE => ChangeLogFeatureTablePage::class,
    ];
}

final class ChangeLogFeatureMissingNoisyPageHilos extends ChangeLogFeatureCompleteHilos
{
    public const array PAGES = [
        ChangeLogFeatureDashboardPage::PAGE => ChangeLogFeatureDashboardPage::class,
        ChangeLogFeatureFeedPage::PAGE => ChangeLogFeatureFeedPage::class,
        ChangeLogFeatureTablesPage::PAGE => ChangeLogFeatureTablesPage::class,
        ChangeLogFeatureTablePage::PAGE => ChangeLogFeatureTablePage::class,
    ];
}

final class ChangeLogFeatureMissingTablesPageHilos extends ChangeLogFeatureCompleteHilos
{
    public const array PAGES = [
        ChangeLogFeatureDashboardPage::PAGE => ChangeLogFeatureDashboardPage::class,
        ChangeLogFeatureNoisyPage::PAGE => ChangeLogFeatureNoisyPage::class,
        ChangeLogFeatureFeedPage::PAGE => ChangeLogFeatureFeedPage::class,
        ChangeLogFeatureTablePage::PAGE => ChangeLogFeatureTablePage::class,
    ];
}

final class ChangeLogFeatureMissingTablePageHilos extends ChangeLogFeatureCompleteHilos
{
    public const array PAGES = [
        ChangeLogFeatureDashboardPage::PAGE => ChangeLogFeatureDashboardPage::class,
        ChangeLogFeatureNoisyPage::PAGE => ChangeLogFeatureNoisyPage::class,
        ChangeLogFeatureFeedPage::PAGE => ChangeLogFeatureFeedPage::class,
        ChangeLogFeatureTablesPage::PAGE => ChangeLogFeatureTablesPage::class,
    ];
}

final class ChangeLogFeatureMissingFeedHilos extends ChangeLogFeatureCompleteHilos
{
    public const array TABLES = [
        HilosChangeLogTablesTable::TABLE => HilosChangeLogTablesTable::class,
        HilosChangeLogHistoryTable::TABLE => HilosChangeLogHistoryTable::class,
        HilosChangeLogNoisyColumnsTable::TABLE => HilosChangeLogNoisyColumnsTable::class,
    ];
}

final class ChangeLogFeatureMissingTablesHilos extends ChangeLogFeatureCompleteHilos
{
    public const array TABLES = [
        HilosChangeLogFeedTable::TABLE => HilosChangeLogFeedTable::class,
        HilosChangeLogHistoryTable::TABLE => HilosChangeLogHistoryTable::class,
        HilosChangeLogNoisyColumnsTable::TABLE => HilosChangeLogNoisyColumnsTable::class,
    ];
}

final class ChangeLogFeatureMissingHistoryHilos extends ChangeLogFeatureCompleteHilos
{
    public const array TABLES = [
        HilosChangeLogFeedTable::TABLE => HilosChangeLogFeedTable::class,
        HilosChangeLogTablesTable::TABLE => HilosChangeLogTablesTable::class,
        HilosChangeLogNoisyColumnsTable::TABLE => HilosChangeLogNoisyColumnsTable::class,
    ];
}

final class ChangeLogFeatureMissingNoisyHilos extends ChangeLogFeatureCompleteHilos
{
    public const array TABLES = [
        HilosChangeLogFeedTable::TABLE => HilosChangeLogFeedTable::class,
        HilosChangeLogTablesTable::TABLE => HilosChangeLogTablesTable::class,
        HilosChangeLogHistoryTable::TABLE => HilosChangeLogHistoryTable::class,
    ];
}

final class ChangeLogFeatureMissingFeedBindingHilos extends ChangeLogFeatureCompleteHilos
{
    public const array PAGE_TABLES = [
        ChangeLogFeatureNoisyPage::PAGE => [HilosChangeLogNoisyColumnsTable::TABLE => []],
        ChangeLogFeatureTablesPage::PAGE => [HilosChangeLogTablesTable::TABLE => []],
        ChangeLogFeatureTablePage::PAGE => [HilosChangeLogHistoryTable::TABLE => []],
    ];
}

final class ChangeLogFeatureMissingNoisyBindingHilos extends ChangeLogFeatureCompleteHilos
{
    public const array PAGE_TABLES = [
        ChangeLogFeatureFeedPage::PAGE => [HilosChangeLogFeedTable::TABLE => []],
        ChangeLogFeatureTablesPage::PAGE => [HilosChangeLogTablesTable::TABLE => []],
        ChangeLogFeatureTablePage::PAGE => [HilosChangeLogHistoryTable::TABLE => []],
    ];
}

final class ChangeLogFeatureMissingTablesBindingHilos extends ChangeLogFeatureCompleteHilos
{
    public const array PAGE_TABLES = [
        ChangeLogFeatureFeedPage::PAGE => [HilosChangeLogFeedTable::TABLE => []],
        ChangeLogFeatureNoisyPage::PAGE => [HilosChangeLogNoisyColumnsTable::TABLE => []],
        ChangeLogFeatureTablePage::PAGE => [HilosChangeLogHistoryTable::TABLE => []],
    ];
}

final class ChangeLogFeatureMissingHistoryBindingHilos extends ChangeLogFeatureCompleteHilos
{
    public const array PAGE_TABLES = [
        ChangeLogFeatureFeedPage::PAGE => [HilosChangeLogFeedTable::TABLE => []],
        ChangeLogFeatureNoisyPage::PAGE => [HilosChangeLogNoisyColumnsTable::TABLE => []],
        ChangeLogFeatureTablesPage::PAGE => [HilosChangeLogTablesTable::TABLE => []],
    ];
}

/** Exercises parent configure(), unlike the pure activation fixture. */
final class ChangeLogRealMountContext extends HilosDbContext
{
    /** @return list<string> Process-wide reads without adding journal interest */
    public function processReads(): array
    {
        return $this->processWideReadCollections();
    }
}

/** A whole journal chain substituted under the framework key, with no extra actions. */
final class ChangeLogExtendedMountContext extends HilosDbContext
{
    /** @return array<string, FrameworkExtension> Receipt chain under its original framework key */
    protected function frameworkExtensions(): array
    {
        return [self::changeLogReceipts => new FrameworkExtension(ExtendedJournalReceipts::class)];
    }
}

final class ExtendedJournalReceipt extends ChangeLogReceipt
{
}

final class ExtendedJournalEntityReceipts extends EntityChangeLogReceipts
{
    public const string ENTITY_CLASS = ExtendedJournalReceipt::class;
}

final class ExtendedJournalObjectReceipt extends ObjectChangeLogReceipt
{
    public const string ENTITY_CLASS = ExtendedJournalReceipt::class;
    public const string OBJECT_COLLECTION_CLASS = ExtendedJournalObjectReceipts::class;
}

/**
 * @method ExtendedJournalObjectReceipt|null first()
 * @method ExtendedJournalObjectReceipt|null last()
 * @method ExtendedJournalObjectReceipt|null get(int|string $key)
 * @method ExtendedJournalObjectReceipt|null offsetGet(mixed $offset)
 */
final class ExtendedJournalObjectReceipts extends ObjectChangeLogReceipts
{
    public const string OBJECT_CLASS = ExtendedJournalObjectReceipt::class;
    public const string ENTITY_COLLECTION_CLASS = ExtendedJournalEntityReceipts::class;
}

final class ExtendedJournalViewReceipt extends DbItemChangeLogReceipt
{
}

/**
 * @method ExtendedJournalObjectReceipts|null getObjectCollection()
 * @method ExtendedJournalViewReceipt|null first()
 * @method ExtendedJournalViewReceipt|null last()
 * @method ExtendedJournalViewReceipt offsetGet(mixed $offset)
 */
final class ExtendedJournalReceipts extends DbCollectionChangeLogReceipts
{
    public const string DB_ITEM_CLASS = ExtendedJournalViewReceipt::class;
    public const string OBJECT_COLLECTION_CLASS = ExtendedJournalObjectReceipts::class;
}
