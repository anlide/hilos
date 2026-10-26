<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Config\BrowserSourceConfig;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\Exception\TableActionsNotConfiguredException;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\DbSyncApplicator;
use Hilos\Database\Object\Item\ModerationRule as ObjectModerationRule;
use Hilos\Database\Object\Item\Setting as ObjectSetting;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Hilos;
use Hilos\Moderation\ModerationInput;
use Hilos\Moderation\ModerationKind;
use Hilos\Moderation\ModerationKindDirectory;
use Hilos\Moderation\ModerationKindKey;
use Hilos\Moderation\ModerationSettingsCatalog;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\Moderation\HilosModerationKindTableRow;
use Hilos\Tables\Moderation\HilosModerationKindsTable;
use Hilos\Tables\Moderation\HilosModerationRuleTableRow;
use Hilos\Tables\Moderation\HilosModerationRulesTable;
use ReflectionProperty;

/** Real ORM reads and action source facts for the framework moderation tables. */
final class HilosModerationTablesIntegrationTest extends FrameworkIntegrationTestCase
{
    private const array TABLES = ['hilos_moderation_rule', 'hilos_setting'];
    private const string OWNER_AGENT_ID = 'test-agent:moderation-tables';

    private ?DbContext $previousDb = null;
    private ?SignalRouter $previousSignalRouter = null;
    private ?SettingsAccessor $previousSettings = null;
    private ?BrowserContext $previousBrowser = null;
    private ?TableContext $previousTables = null;
    private string $boundAppClass = Hilos::class;
    private ModerationTablesChangeLog $changes;

    /** Prepares real tables and gives the test the ordinary writers' claims. */
    protected function setUp(): void
    {
        parent::setUp();
        self::runStubs(down: true);
        self::runStubs(down: false);
        Schema::reset();
        Schema::initialize();

        $this->previousDb = Hilos::$db;
        $this->previousSignalRouter = Hilos::$sr;
        $this->previousSettings = Hilos::$setting;
        $this->previousBrowser = Hilos::$browser;
        $this->previousTables = Hilos::$table;
        $this->boundAppClass = Hilos::appClass();
        self::bindAppClass(ModerationTablesTestHilos::class);
        $db = new ModerationTablesTestDbContext();
        $db->configure();
        Hilos::$db = $db;
        Hilos::$sr = new SignalRouter();
        Hilos::$setting = new SettingsAccessor(ModerationTablesSettingsCatalog::class);

        foreach ([HilosDbContext::moderationRules, HilosDbContext::settings] as $collection) {
            TruthSourceRegistry::register($collection, TruthSourceKeys::all(), self::OWNER_AGENT_ID);
        }
        ExecutionContext::setCurrentAgentId(self::OWNER_AGENT_ID);
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        $this->changes = new ModerationTablesChangeLog();
        SourceChangeBus::subscribe($this->changes);
    }

    /** Releases the test's claims, globals and stub tables. */
    protected function tearDown(): void
    {
        ExecutionContext::setCurrentAgentId(null);
        TruthSourceRegistry::unregisterAgent(self::OWNER_AGENT_ID);
        SourceChangeBus::reset();
        Hilos::$db = $this->previousDb;
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$setting = $this->previousSettings;
        Hilos::$table = $this->previousTables;
        $appClass = $this->boundAppClass;
        if ($this->previousBrowser === null) {
            Hilos::resetBrowser();
        } else {
            $appClass::initBrowser($this->previousBrowser);
        }
        self::bindAppClass($this->boundAppClass);
        self::runStubs(down: true);
        parent::tearDown();
    }

    public function testDirectoryRowsExistWithoutOwnRulesAndKeepTheirOrderAndExactShape(): void
    {
        $table = new HilosModerationKindsTable();
        $snapshot = $table->getPage(new TableQueryDTO());

        $this->assertSame(self::kindKeys(), self::keys($snapshot));
        $this->assertSame(4, $snapshot->totalCount);
        $this->assertTrue($snapshot->totalExact);
        $this->assertSame(25, $table->windowSize());
        $this->assertNull($table->defaultSort());
        $this->assertSame([], $table->bulkActions());
        $this->assertSame([
            ['hilos_profile_name', 'Profile name', 'framework', 'text', 'text-default', 0,
                'hilos.moderation.text', ['insult', 'threat', 'hate_speech', 'sexual', 'spam', 'impersonation']],
            ['hilos_profile_photo', 'Profile photo', 'framework', 'image', 'vision-default', 0,
                'hilos.moderation.image', ['nudity', 'violence', 'hate']],
            ['test_note', 'Project note', 'project', 'text_with_files', 'text-default', 0, 'hilos.moderation.text', ['rude']],
            ['test_picture', 'Project picture', 'project', 'image', 'vision-default', 0, 'hilos.moderation.image', ['nudity']],
        ], array_map(static fn (AbstractTableRow $row): array => array_values($row->toArray()), $snapshot->rows));

        foreach ($snapshot->rows as $row) {
            $this->assertInstanceOf(HilosModerationKindTableRow::class, $row);
            $this->assertSame(['kind', 'title', 'owner', 'input', 'model', 'ownRuleCount', 'profile', 'reasons'], array_keys($row->toArray()));
            $this->assertSame($row->getRowKey(), $row->toArray()[HilosModerationKindTableRow::kind]);
            $this->assertSame($row->toArray(), HilosModerationKindTableRow::fromArray($row->toArray())->toArray());
            $this->assertSame([
                BrowserPageSignalData::rowKey => $row->getRowKey(),
                BrowserPageSignalData::sources => ['kind' => $row->toArray()],
            ], $table->browserRow($row));
            $this->assertNotEmpty(Hilos::$db->moderationRules->effectiveForKind($row->getRowKey()));
        }
        $this->assertSame(HilosModerationKindTableRow::kind, HilosModerationKindTableRow::keyField());
        $this->assertCount(0, Hilos::$db->moderationRules);
        $this->assertNull($table->findRow('no_such_kind'));
        $this->assertCount(0, Hilos::$db->moderationRules);
    }

    public function testPhotoDisappearsWithoutTheFeatureIncludingItsSourceMutations(): void
    {
        $photoRule = Hilos::$db->moderationRules->actions->create(ModerationKindKey::PROFILE_PHOTO, 'A photo rule.');
        $created = $this->onlyChange();
        self::bindAppClass(ModerationTablesNoPhotoTestHilos::class);
        $table = new HilosModerationKindsTable();

        $this->assertSame([
            ModerationKindKey::PROFILE_NAME, ModerationTablesTestDirectory::NOTE, ModerationTablesTestDirectory::PICTURE,
        ], self::keys($table->getPage(new TableQueryDTO())));
        $this->assertNull($table->findRow(ModerationKindKey::PROFILE_PHOTO));
        $this->assertFalse($table->containsRow(ModerationKindKey::PROFILE_PHOTO, new TableQueryDTO()));
        $this->assertSame([], $table->buildMutationsForSourceEvent($created));
        $photoRule->actions->delete();
        $this->assertSame([], $table->buildMutationsForSourceEvent($this->onlyChange()));
    }

    public function testOwnRuleCountsUseOnlyTheCompleteSetOfTheirKindAndRefreshOnEveryRead(): void
    {
        $table = new HilosModerationKindsTable();
        $this->assertSame(0, $table->findRow(ModerationTablesTestDirectory::NOTE)->ownRuleCount);
        Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'First note rule.');
        $this->assertSame(1, $table->findRow(ModerationTablesTestDirectory::NOTE)->ownRuleCount);
        Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'Second note rule.');
        Hilos::$db->moderationRules->actions->create(ModerationKindKey::PROFILE_NAME, 'Name rule.');

        $this->assertSame([1, 0, 2, 0], array_map(
            static fn (HilosModerationKindTableRow $row): int => $row->ownRuleCount,
            $table->getPage(new TableQueryDTO())->rows,
        ));
        $this->assertSame(['First note rule.', 'Second note rule.'], Hilos::$db->moderationRules->effectiveForKind(
            ModerationTablesTestDirectory::NOTE,
        ));
        $this->assertSame(3, count(Hilos::$db->moderationRules));
    }

    public function testSearchAndMembershipUseTitleKindAndCurrentModelOnly(): void
    {
        $table = new HilosModerationKindsTable();
        Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'An own rule.');
        foreach ([
            ['Project note', [ModerationTablesTestDirectory::NOTE]],
            ['test_picture', [ModerationTablesTestDirectory::PICTURE]],
            ['vision-default', [ModerationKindKey::PROFILE_PHOTO, ModerationTablesTestDirectory::PICTURE]],
            ['text-default', [ModerationKindKey::PROFILE_NAME, ModerationTablesTestDirectory::NOTE]],
            ['framework', []], ['text_with_files', []], ['Built-in', []], ['An own rule.', []], ['999999', []],
        ] as [$search, $expected]) {
            $query = new TableQueryDTO(search: $search, limit: 1);
            $snapshot = $table->getPage($query);
            $this->assertSame(count($expected), $snapshot->totalCount, $search);
            $this->assertTrue($snapshot->totalExact);
            $this->assertSame(array_slice($expected, 0, 1), self::keys($snapshot), $search);
            foreach (self::kindKeys() as $key) {
                $this->assertSame(in_array($key, $expected, true), $table->containsRow($key, $query), $search . ': ' . $key);
            }
            $this->assertFalse($table->containsRow('no_such_kind', $query));
        }
        $this->assertSame(ModerationTablesTestDirectory::NOTE, $table->findRow(ModerationTablesTestDirectory::NOTE)->getRowKey());
    }

    /** Exact kind selection and membership share the same published set, including malformed filters. */
    public function testKindsFilterSelectsOnlyTheExactPublishedStringKey(): void
    {
        $table = new HilosModerationKindsTable();
        $this->assertSame('kind', $table::FILTER_KIND);
        $this->assertSame(self::kindKeys(), self::keys($table->getPage(new TableQueryDTO(filter: ['unrelated' => 'test_note']))));
        foreach ([
            ...self::kindKeys(), 'unknown_kind', 'test', 'TEST_NOTE', 'test_note ', '', null, 1, true, [], ['test_note'],
        ] as $kind) {
            $expected = is_string($kind) && in_array($kind, self::kindKeys(), true) ? [$kind] : [];
            $query = new TableQueryDTO(limit: 1, filter: ['kind' => $kind]);
            $snapshot = $table->getPage($query);
            $this->assertSame($expected, self::keys($snapshot));
            $this->assertSame(count($expected), $snapshot->totalCount);
            $this->assertTrue($snapshot->totalExact);
            $this->assertSame(1, $snapshot->limit);
            foreach ([...self::kindKeys(), 'unknown_kind'] as $key) {
                $this->assertSame(in_array($key, $expected, true), $table->containsRow($key, $query));
            }
        }
        foreach ([['Project note', ['test_note']], ['picture', []], ['hilos.moderation.text', []], ['rude', []]] as [$search, $expected]) {
            $query = new TableQueryDTO(search: $search, limit: 1, filter: ['kind' => 'test_note']);
            $this->assertSame($expected, self::keys($table->getPage($query)));
            $this->assertSame($expected !== [], $table->containsRow('test_note', $query));
        }
        self::bindAppClass(ModerationTablesNoPhotoTestHilos::class);
        $photoQuery = new TableQueryDTO(limit: 1, filter: ['kind' => 'hilos_profile_photo']);
        $this->assertSame([], $table->getPage($photoQuery)->rows);
        $this->assertFalse($table->containsRow('hilos_profile_photo', $photoQuery));
    }

    public function testSelectedOrdersPageByAnchorAndIndexWithoutRepeatingTies(): void
    {
        $table = new HilosModerationKindsTable();
        Hilos::$db->moderationRules->actions->create(ModerationKindKey::PROFILE_NAME, 'Name rule.');
        Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'First note rule.');
        Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'Second note rule.');
        foreach ([
            'title' => ['hilos_profile_name', 'hilos_profile_photo', 'test_note', 'test_picture'],
            'kind' => ['hilos_profile_name', 'hilos_profile_photo', 'test_note', 'test_picture'],
            'owner' => ['hilos_profile_name', 'hilos_profile_photo', 'test_note', 'test_picture'],
            'model' => ['hilos_profile_name', 'test_note', 'hilos_profile_photo', 'test_picture'],
            'ownRuleCount' => ['hilos_profile_photo', 'test_picture', 'hilos_profile_name', 'test_note'],
        ] as $field => $ascending) {
            foreach ([TableConstants::ORDER_ASC, TableConstants::ORDER_DESC] as $direction) {
                $sort = TableSortOrderDTO::of(new TableSortDTO($field, $direction));
                $whole = $table->getPage(new TableQueryDTO(sort: $sort));
                $this->assertSame(
                    $direction === TableConstants::ORDER_ASC ? $ascending : array_reverse($ascending),
                    self::keys($whole),
                    $field . ': ' . $direction,
                );
                $first = $table->getPage(new TableQueryDTO(sort: $sort, limit: 2));
                $next = $table->getPage(new TableQueryDTO(sort: $sort, limit: 2, anchor: $first->lastAnchor));
                $jump = $table->getPage(new TableQueryDTO(sort: $sort, limit: 2, pageIndex: 1));
                $this->assertSame(4, $first->totalCount);
                $this->assertTrue($first->totalExact);
                $this->assertSame(2, $first->limit);
                $this->assertSame(0, $first->rowsBefore);
                $this->assertSame(2, $next->rowsBefore);
                $this->assertSame(self::keys($next), self::keys($jump));
                $this->assertSame(self::keys($whole), [...self::keys($first), ...self::keys($next)]);
                $this->assertCount(4, array_unique([...self::keys($first), ...self::keys($next)]));
            }
        }
        $first = $table->getPage(new TableQueryDTO(limit: 2));
        $next = $table->getPage(new TableQueryDTO(limit: 2, anchor: $first->lastAnchor));
        $this->assertSame(self::kindKeys(), [...self::keys($first), ...self::keys($next)]);
    }

    public function testRealRuleActionsAddressTheirKindEvenWithADiffOrADeletedRow(): void
    {
        $table = new HilosModerationKindsTable();
        $rule = Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'Initial rule.');
        $created = $this->onlyChange();
        $this->assertSame(TableMutationType::Create, $created->mutationType);
        $this->assertCountMutation($table, $created, 1);

        $rule->actions->update('Edited rule.');
        $updated = $this->onlyChange();
        $this->assertSame(TableMutationType::Update, $updated->mutationType);
        $this->assertArrayNotHasKey(ObjectModerationRule::kind, $updated->row);
        $this->assertCountMutation($table, $updated, 1);

        $second = Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'Second rule.');
        $this->assertCountMutation($table, $this->onlyChange(), 2);
        $rule->actions->delete();
        $this->assertCountMutation($table, $this->onlyChange(), 1);
        $secondId = $second->id;
        $second->actions->delete();
        $deleted = $this->onlyChange();
        $this->assertSame(TableMutationType::Delete, $deleted->mutationType);
        $this->assertSame(ModerationTablesTestDirectory::NOTE, $deleted->row[ObjectModerationRule::kind]);
        $this->assertArrayNotHasKey($secondId, Hilos::$db->moderationRules);
        $this->assertCountMutation($table, $deleted, 0);
        $this->assertCountMutation($table, new SourceChange(
            $deleted->kind, $deleted->sourceKey, $deleted->sourceId, $deleted->mutationType, [], $deleted->row,
        ), 0);
    }

    public function testEachModelSettingUpdatesAllAndOnlyItsInputProfileAndDeletionRestoresTheDefault(): void
    {
        $table = new HilosModerationKindsTable();
        foreach ([
            [ModerationSettingsCatalog::TEXT_MODEL, 'text-default', [ModerationKindKey::PROFILE_NAME, ModerationTablesTestDirectory::NOTE]],
            [ModerationSettingsCatalog::IMAGE_MODEL, 'vision-default', [ModerationKindKey::PROFILE_PHOTO, ModerationTablesTestDirectory::PICTURE]],
        ] as [$settingKey, $default, $kindKeys]) {
            Hilos::$db->settings->actions->add($settingKey, 'new-model', Hilos::$setting->catalog());
            $this->assertModelMutations($table, $this->onlyChange(), $kindKeys, 'new-model');
            Hilos::$db->settings[$settingKey]->actions->updateValue('edited-model');
            $updated = $this->onlyChange();
            $this->assertArrayNotHasKey(ObjectSetting::key, $updated->row);
            $this->assertModelMutations($table, $updated, $kindKeys, 'edited-model');
            Hilos::$db->settings[$settingKey]->actions->delete();
            $deleted = $this->onlyChange();
            $this->assertModelMutations($table, $deleted, $kindKeys, $default);
            $this->assertModelMutations($table, new SourceChange(
                $deleted->kind, $deleted->sourceKey, $deleted->sourceId, $deleted->mutationType, [], $deleted->row,
            ), $kindKeys, $default);
        }
    }

    public function testUnrelatedFactsAndSettingsProduceNoKindMutations(): void
    {
        $table = new HilosModerationKindsTable();
        Hilos::$db->settings->actions->add(ModerationTablesSettingsCatalog::UNRELATED, 'value', Hilos::$setting->catalog());
        $this->assertSame([], $table->buildMutationsForSourceEvent($this->onlyChange()));
        Hilos::$db->settings[ModerationTablesSettingsCatalog::UNRELATED]->actions->updateValue('changed');
        $this->assertSame([], $table->buildMutationsForSourceEvent($this->onlyChange()));
        Hilos::$db->settings[ModerationTablesSettingsCatalog::UNRELATED]->actions->delete();
        $this->assertSame([], $table->buildMutationsForSourceEvent($this->onlyChange()));
        foreach ([
            SourceChange::dbCreated(HilosDbContext::users, '1', []),
            SourceChange::dbDeleted(HilosDbContext::moderationRules, '999', [ObjectModerationRule::kind => 'unknown_kind']),
            SourceChange::dbUpdated(HilosDbContext::moderationRules, '999', [ObjectModerationRule::rule => 'Gone.']),
            SourceChange::rtCreated(HilosDbContext::settings, '1', [ObjectSetting::key => ModerationSettingsCatalog::TEXT_MODEL]),
        ] as $change) {
            $this->assertSame([], $table->buildMutationsForSourceEvent($change));
        }
    }

    public function testSourceInterestNamesBothRealRowsAndModelWireOriginUsesTheInputsSetting(): void
    {
        $table = new HilosModerationKindsTable();
        $configs = BrowserSourceConfig::fromArray($table::BROWSER)->rowConfigs();
        $this->assertSame([
            [BrowserSourceKey::TYPE => BrowserSourceType::DB, BrowserSourceKey::KEY => HilosDbContext::moderationRules],
            [BrowserSourceKey::TYPE => BrowserSourceType::DB, BrowserSourceKey::KEY => HilosDbContext::settings],
        ], array_column($configs, BrowserTableFieldKey::SOURCE));
        $this->assertSame([ObjectModerationRule::kind, ObjectSetting::id], array_column($configs, BrowserTableFieldKey::ROW_KEY));
        $this->assertSame([
            [ObjectModerationRule::id, ObjectModerationRule::kind, ObjectModerationRule::rule],
            [ObjectSetting::id, ObjectSetting::key, ObjectSetting::value],
        ], array_column($configs, BrowserTableFieldKey::FIELDS));
        $origins = $table->wireFields();
        $this->assertSame(['kind', 'title', 'owner', 'input', 'model', 'ownRuleCount', 'profile', 'reasons'], array_keys($origins));
        foreach (['kind', 'title', 'owner', 'input', 'ownRuleCount', 'profile', 'reasons'] as $field) {
            $this->assertFalse($origins[$field]->isColumn());
            $this->assertFalse($origins[$field]->isSetting());
            $this->assertNull($origins[$field]->fields);
        }
        $this->assertTrue($origins['model']->isSetting());
        foreach ($table->getPage(new TableQueryDTO())->rows as $row) {
            $this->assertSame(
                $row->input === ModerationInput::IMAGE->value ? ModerationSettingsCatalog::IMAGE_MODEL : ModerationSettingsCatalog::TEXT_MODEL,
                ($origins['model']->settingKeySource)($row->toArray()),
            );
        }
    }

    public function testRowReaderRefusesMissingOrMistypedFieldsAndNegativeCounts(): void
    {
        $row = new HilosModerationKindTableRow('test_note', 'Note', 'project', 'text', 'model', 0, 'hilos.moderation.text', ['spam', 'rude']);
        $this->assertSame(['spam', 'rude'], HilosModerationKindTableRow::fromArray($row->toArray())->reasons);
        $this->assertSame([], HilosModerationKindTableRow::fromArray(array_replace($row->toArray(), ['reasons' => []]))->reasons);
        foreach (array_keys($row->toArray()) as $field) {
            $payload = $row->toArray();
            unset($payload[$field]);
            try {
                HilosModerationKindTableRow::fromArray($payload);
                $this->fail('A missing field must be refused: ' . $field);
            } catch (InvalidFormatException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (['1', -1] as $count) {
            try {
                HilosModerationKindTableRow::fromArray(array_replace($row->toArray(), ['ownRuleCount' => $count]));
                $this->fail('A mistyped or negative count must be refused');
            } catch (InvalidFormatException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach ([
            ['profile' => null], ['profile' => 1], ['profile' => []], ['profile' => (object) []],
            ['reasons' => null], ['reasons' => 'spam'], ['reasons' => (object) []],
            ['reasons' => ['name' => 'spam']], ['reasons' => [1 => 'spam']], ['reasons' => ['spam', 1]],
            ['reasons' => [false]], ['reasons' => [null]], ['reasons' => [[]]], ['reasons' => [(object) []]],
        ] as $invalid) {
            try {
                HilosModerationKindTableRow::fromArray(array_replace($row->toArray(), $invalid));
                $this->fail('A mistyped profile or a non-string reason list must be refused');
            } catch (InvalidFormatException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** Built-in policy stays outside the table even while the database has no own rules. */
    public function testRulesExposeOnlyOwnRowsOfThePublishedKindWithTheExactTypedShape(): void
    {
        $table = new HilosModerationRulesTable();
        $this->assertSame('hilosModerationRules', $table::TABLE);
        $this->assertSame('kind', $table::FILTER_KIND);
        $this->assertSame(25, $table->windowSize());
        $this->assertSame([['field' => 'id', 'direction' => 'asc']], $table->defaultSort()->toArray());
        $this->assertSame(HilosModerationRuleTableRow::class, $table->getRowClass());
        $this->assertSame('id', HilosModerationRuleTableRow::keyField());
        foreach (self::kindKeys() as $kind) {
            $snapshot = $table->getPage(new TableQueryDTO(filter: [$table::FILTER_KIND => $kind]));
            $this->assertSame([], $snapshot->rows);
            $this->assertSame(0, $snapshot->totalCount);
            $this->assertTrue($snapshot->totalExact);
            $this->assertNotEmpty(Hilos::$db->moderationRules->effectiveForKind($kind));
        }
        $this->assertCount(0, Hilos::$db->moderationRules);

        $note = Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'First own note rule.');
        $picture = Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::PICTURE, 'Own picture rule.');
        $second = Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'Second own note rule.');
        $snapshot = $table->getPage(new TableQueryDTO(filter: [$table::FILTER_KIND => ModerationTablesTestDirectory::NOTE]));
        $this->assertSame([$note->id, $second->id], self::ruleIds($snapshot));
        $this->assertSame(2, $snapshot->totalCount);
        $this->assertTrue($snapshot->totalExact);
        $this->assertSame([
            ['id' => $note->id, 'kind' => 'test_note', 'rule' => 'First own note rule.'],
            ['id' => $second->id, 'kind' => 'test_note', 'rule' => 'Second own note rule.'],
        ], array_map(static fn (HilosModerationRuleTableRow $row): array => $row->toArray(), $snapshot->rows));
        foreach ($snapshot->rows as $row) {
            $this->assertInstanceOf(HilosModerationRuleTableRow::class, $row);
            $this->assertSame(['id', 'kind', 'rule'], array_keys($row->toArray()));
            $this->assertIsInt($row->getRowKey());
            $this->assertGreaterThan(0, $row->getRowKey());
            $this->assertSame($row->id, $row->getRowKey());
            $this->assertSame($row->toArray(), HilosModerationRuleTableRow::fromArray($row->toArray())->toArray());
            $this->assertSame([
                BrowserPageSignalData::rowKey => $row->id,
                BrowserPageSignalData::sources => ['rule' => $row->toArray()],
            ], $table->browserRow($row));
        }
        $this->assertSame([$picture->id], self::ruleIds($table->getPage(new TableQueryDTO(
            filter: [$table::FILTER_KIND => ModerationTablesTestDirectory::PICTURE],
        ))));
        $this->assertCount(3, Hilos::$db->moderationRules);
    }

    /** Invalid filters cannot widen a window to rules of every kind. */
    public function testRulesRequireAPublishedStringKindAndNeverRevealTheUnfilteredSet(): void
    {
        $note = Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'An own rule.');
        Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::PICTURE, 'Another own rule.');
        $this->changes->taken = [];
        $table = new HilosModerationRulesTable();
        foreach ([[], ['kind' => null], ['kind' => ''], ['kind' => 1], ['kind' => true], ['kind' => []], ['kind' => 'unknown']] as $filter) {
            foreach ([null, 'own'] as $search) {
                $query = new TableQueryDTO(search: $search, limit: 1, filter: $filter);
                $snapshot = $table->getPage($query);
                $this->assertSame([], $snapshot->rows);
                $this->assertSame(0, $snapshot->totalCount);
                $this->assertTrue($snapshot->totalExact);
                $this->assertFalse($table->containsRow($note->id, $query));
            }
        }
        $this->assertSame([], $this->changes->taken);
        $this->assertCount(2, Hilos::$db->moderationRules);
    }

    /** Equal rule texts still page by a stable primary-key tie-breaker. */
    public function testRuleOrdersPageByAnchorAndIndexWithoutRepeatingTies(): void
    {
        $zebra = Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'Zebra');
        Hilos::$db->moderationRules->actions->create(ModerationKindKey::PROFILE_NAME, 'Excluded name rule.');
        $alpha = Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'Alpha');
        $alphaAgain = Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'Alpha');
        $beta = Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'Beta');
        $table = new HilosModerationRulesTable();
        $filter = [$table::FILTER_KIND => ModerationTablesTestDirectory::NOTE];
        $this->assertSame([$zebra->id, $alpha->id, $alphaAgain->id, $beta->id], self::ruleIds($table->getPage(
            new TableQueryDTO(filter: $filter),
        )));
        foreach ([
            'id' => [$zebra->id, $alpha->id, $alphaAgain->id, $beta->id],
            'rule' => [$alpha->id, $alphaAgain->id, $beta->id, $zebra->id],
        ] as $field => $ascending) {
            foreach ([TableConstants::ORDER_ASC, TableConstants::ORDER_DESC] as $direction) {
                $sort = TableSortOrderDTO::of(new TableSortDTO($field, $direction));
                $whole = $table->getPage(new TableQueryDTO(sort: $sort, filter: $filter));
                $this->assertSame(
                    $direction === TableConstants::ORDER_ASC ? $ascending : array_reverse($ascending),
                    self::ruleIds($whole),
                );
                $first = $table->getPage(new TableQueryDTO(sort: $sort, limit: 2, filter: $filter));
                $next = $table->getPage(new TableQueryDTO(sort: $sort, limit: 2, filter: $filter, anchor: $first->lastAnchor));
                $jump = $table->getPage(new TableQueryDTO(sort: $sort, limit: 2, filter: $filter, pageIndex: 1));
                foreach ([$first, $next, $jump] as $snapshot) {
                    $this->assertSame(4, $snapshot->totalCount);
                    $this->assertTrue($snapshot->totalExact);
                    $this->assertSame(2, $snapshot->limit);
                }
                $this->assertSame(0, $first->rowsBefore);
                $this->assertSame(2, $next->rowsBefore);
                $this->assertSame(2, $jump->rowsBefore);
                $this->assertSame(self::ruleIds($next), self::ruleIds($jump));
                $this->assertSame(self::ruleIds($whole), [...self::ruleIds($first), ...self::ruleIds($next)]);
                $this->assertCount(4, array_unique([...self::ruleIds($first), ...self::ruleIds($next)]));
            }
        }
    }

    /** Membership includes matching rows outside the delivered limit and excludes every other kind. */
    public function testRuleSearchAndMembershipUseTheSameKindAndRuleTextBeyondTheWindow(): void
    {
        $first = Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'Match first.');
        $otherKind = Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::PICTURE, 'Match picture.');
        $second = Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'Match second.');
        $outsideSearch = Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'Different text.');
        $table = new HilosModerationRulesTable();
        $filter = [$table::FILTER_KIND => ModerationTablesTestDirectory::NOTE];
        foreach ([
            ['mAtCh', [$first->id, $second->id]], ['second', [$second->id]],
            ['test_note', []], [(string) $first->id, []], ['999999', []], ['Built-in', []], ['Polite notes.', []],
        ] as [$search, $expected]) {
            $query = new TableQueryDTO(search: $search, limit: 1, filter: $filter);
            $snapshot = $table->getPage($query);
            $this->assertSame(count($expected), $snapshot->totalCount, $search);
            $this->assertTrue($snapshot->totalExact);
            $this->assertSame(array_slice($expected, 0, 1), self::ruleIds($snapshot));
            foreach ([$first->id, $second->id, $outsideSearch->id] as $id) {
                $this->assertSame(in_array($id, $expected, true), $table->containsRow($id, $query));
            }
            $this->assertFalse($table->containsRow($otherKind->id, $query));
            $this->assertFalse($table->containsRow(999999, $query));
        }
        $search = new TableQueryDTO(search: 'Match', sort: $table->defaultSort(), limit: 1, filter: $filter);
        $firstPage = $table->getPage($search);
        $nextPage = $table->getPage(new TableQueryDTO(
            search: 'Match', sort: $table->defaultSort(), limit: 1, filter: $filter, anchor: $firstPage->lastAnchor,
        ));
        $this->assertSame([$first->id, $second->id], [...self::ruleIds($firstPage), ...self::ruleIds($nextPage)]);
        $this->assertSame(2, $nextPage->totalCount);
        $this->assertSame(1, $nextPage->rowsBefore);
    }

    /** Focus lookup is independent of a kind window, while publication still gates the row. */
    public function testRuleFindByPrimaryKeyIsGlobalAndNeverCreatesMissingRowsOrPublishesHiddenKinds(): void
    {
        $note = Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'A note rule.');
        $photo = Hilos::$db->moderationRules->actions->create(ModerationKindKey::PROFILE_PHOTO, 'A photo rule.');
        $this->changes->taken = [];
        $table = new HilosModerationRulesTable();
        $this->assertSame([], $table->getPage(new TableQueryDTO())->rows);
        foreach ([$note->id, (string) $note->id] as $id) {
            $row = $table->findRow($id);
            $this->assertInstanceOf(HilosModerationRuleTableRow::class, $row);
            $this->assertSame(['id' => $note->id, 'kind' => 'test_note', 'rule' => 'A note rule.'], $row->toArray());
        }
        foreach ([null, 0, -1, '', '0', '-1', '1tail', '1.0', '+1', ' 1', '01', '99999999999999999999999999', 999999] as $id) {
            $this->assertNull($table->findRow($id));
        }
        self::bindAppClass(ModerationTablesNoPhotoTestHilos::class);
        $photoQuery = new TableQueryDTO(filter: [$table::FILTER_KIND => ModerationKindKey::PROFILE_PHOTO]);
        $this->assertNull($table->findRow($photo->id));
        $this->assertSame([], $table->getPage($photoQuery)->rows);
        $this->assertFalse($table->containsRow($photo->id, $photoQuery));
        $this->assertSame([], $this->changes->taken);
        $this->assertCount(2, Hilos::$db->moderationRules);
        $this->assertArrayNotHasKey(999999, Hilos::$db->moderationRules);
    }

    /** Real action facts drive both tables, including text-only diffs and vanished rows. */
    public function testRealRuleFactsBuildCurrentTypedRowsAndAddressDeletesAfterTheRowIsGone(): void
    {
        $table = new HilosModerationRulesTable();
        $kinds = new HilosModerationKindsTable();
        $rule = Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'Initial text.');
        $id = $rule->id;
        $created = $this->onlyChange();
        $this->assertSame((string) $id, $created->sourceId);
        $this->assertRuleMutation($table, $created, TableMutationType::Create, $id, 'Initial text.');
        $this->assertCountMutation($kinds, $created, 1);

        $rule->actions->update('Current text.');
        $updated = $this->onlyChange();
        $this->assertArrayNotHasKey(ObjectModerationRule::kind, $updated->row);
        $this->assertRuleMutation($table, $updated, TableMutationType::Update, $id, 'Current text.');
        $this->assertRuleMutation($table, $created, TableMutationType::Create, $id, 'Current text.');
        $this->assertCountMutation($kinds, $updated, 1);

        $rule->actions->delete();
        $deleted = $this->onlyChange();
        $this->assertSame((string) $id, $deleted->sourceId);
        $this->assertArrayNotHasKey($id, Hilos::$db->moderationRules);
        foreach ([$deleted, new SourceChange(
            $deleted->kind, $deleted->sourceKey, $deleted->sourceId, $deleted->mutationType, [], $deleted->row,
        ), SourceChange::dbDeleted(HilosDbContext::moderationRules, (string) $id)] as $change) {
            $mutation = $table->buildMutationForSourceEvent($change);
            $this->assertSame(TableMutationType::Delete, $mutation->type);
            $this->assertSame($id, $mutation->rowKey);
            $this->assertNull($mutation->row);
            $this->assertSame($id, $table->rowKeyForSourceEvent($change));
        }
        $this->assertCountMutation($kinds, $deleted, 0);
        $this->assertNull($table->buildMutationForSourceEvent($updated));
        $this->assertNull($table->findRow($id));
    }

    /** Only ordinary DB rule facts address rows, and deletion needs no remaining directory entry. */
    public function testRuleMutationsIgnoreOtherSourcesInvalidIdsAndUnpublishedKinds(): void
    {
        $table = new HilosModerationRulesTable();
        Hilos::$db->settings->actions->add(ModerationSettingsCatalog::TEXT_MODEL, 'new-model', Hilos::$setting->catalog());
        foreach ([
            $this->onlyChange(),
            SourceChange::dbCreated(HilosDbContext::users, '1', []),
            SourceChange::rtCreated(HilosDbContext::moderationRules, '1', []),
            SourceChange::dbCleared(HilosDbContext::moderationRules),
            SourceChange::dbCreated(HilosDbContext::moderationRules, '1tail', []),
            SourceChange::dbUpdated(HilosDbContext::moderationRules, '0', []),
            SourceChange::dbDeleted(HilosDbContext::moderationRules, '-1'),
        ] as $change) {
            $this->assertNull($table->buildMutationForSourceEvent($change));
            $this->assertNull($table->rowKeyForSourceEvent($change));
        }
        foreach ([
            SourceChange::dbCreated(HilosDbContext::moderationRules, '999999', []),
            SourceChange::dbUpdated(HilosDbContext::moderationRules, '999999', [ObjectModerationRule::rule => 'Missing.']),
        ] as $change) {
            $this->assertNull($table->buildMutationForSourceEvent($change));
            $this->assertSame(999999, $table->rowKeyForSourceEvent($change));
        }
        $photo = Hilos::$db->moderationRules->actions->create(ModerationKindKey::PROFILE_PHOTO, 'Unpublished photo rule.');
        $created = $this->onlyChange();
        self::bindAppClass(ModerationTablesNoPhotoTestHilos::class);
        $this->assertNull($table->buildMutationForSourceEvent($created));
        $photo->actions->update('Still unpublished.');
        $this->assertNull($table->buildMutationForSourceEvent($this->onlyChange()));
        $id = $photo->id;
        $photo->actions->delete();
        $deleted = $table->buildMutationForSourceEvent($this->onlyChange());
        $this->assertSame(TableMutationType::Delete, $deleted->type);
        $this->assertSame($id, $deleted->rowKey);
    }

    /** Browser interest and viewer origins reference the same mounted DB source. */
    public function testRuleSourceInterestAndWireOriginsNameExistingColumnsWithoutMutationOrBulkApis(): void
    {
        $table = new HilosModerationRulesTable();
        $source = [BrowserSourceKey::TYPE => BrowserSourceType::DB, BrowserSourceKey::KEY => HilosDbContext::moderationRules];
        $config = BrowserSourceConfig::fromArray($table::BROWSER);
        $this->assertSame([$source], $table::BROWSER[BrowserTableConfigKey::SOURCES]);
        $this->assertSame([[
            BrowserTableFieldKey::SOURCE => $source,
            BrowserTableFieldKey::ROW_KEY => ObjectModerationRule::id,
            BrowserTableFieldKey::FIELDS => [ObjectModerationRule::id, ObjectModerationRule::kind, ObjectModerationRule::rule],
        ]], $config->rowConfigs());
        $origins = $table->wireFields();
        $this->assertSame(['id', 'kind', 'rule'], array_keys($origins));
        foreach (['id', 'kind', 'rule'] as $field) {
            $this->assertTrue($origins[$field]->isColumn());
            $this->assertSame(HilosDbContext::moderationRules, $origins[$field]->collection);
            $this->assertSame($field, $origins[$field]->field);
            $this->assertFalse($origins[$field]->isSetting());
            $this->assertNull($origins[$field]->fields);
        }
        $this->assertSame([], $table->bulkActions());
        $this->assertNull($table->getItemActionsClass());
        $this->expectException(TableActionsNotConfiguredException::class);
        $table->actions;
    }

    /** Missing fixture tables exercise actual database refusals at every rule read boundary. */
    public function testRuleReadFailuresPropagateInsteadOfReturningAnEmptySuccessfulSet(): void
    {
        self::runStubs(down: true);
        $db = new ModerationTablesTestDbContext();
        $db->configure();
        Hilos::$db = $db;
        $table = new HilosModerationRulesTable();
        $query = new TableQueryDTO(filter: [$table::FILTER_KIND => ModerationTablesTestDirectory::NOTE]);
        $deleted = $table->buildMutationForSourceEvent(SourceChange::dbDeleted(HilosDbContext::moderationRules, '1'));
        $this->assertSame(TableMutationType::Delete, $deleted->type);
        $this->assertSame(1, $deleted->rowKey);
        $this->assertNull($deleted->row);
        foreach ([
            fn () => $table->getPage($query), fn () => $table->containsRow(1, $query), fn () => $table->findRow(1),
            fn () => $table->buildMutationForSourceEvent(SourceChange::dbUpdated(HilosDbContext::moderationRules, '1', [])),
        ] as $read) {
            try {
                $read();
                $this->fail('A failed database read must propagate');
            } catch (DatabaseException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** Broken directory wiring is a refusal even when no window could select its rows. */
    public function testRuleReadsDoNotTreatAMalformedKindDirectoryAsAnEmptySet(): void
    {
        $rule = Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'Own rule.');
        $created = $this->onlyChange();
        self::bindAppClass(ModerationTablesMalformedDirectoryTestHilos::class);
        $table = new HilosModerationRulesTable();
        $query = new TableQueryDTO(filter: [$table::FILTER_KIND => ModerationTablesTestDirectory::NOTE]);
        foreach ([
            fn () => $table->getPage($query), fn () => $table->containsRow($rule->id, $query),
            fn () => $table->findRow($rule->id), fn () => $table->buildMutationForSourceEvent($created),
        ] as $read) {
            try {
                $read();
                $this->fail('A malformed published directory must propagate');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** Deserialization preserves strict field types and requires a persisted positive key. */
    public function testRuleRowReaderRefusesMissingMistypedAndNonpositiveIds(): void
    {
        $row = new HilosModerationRuleTableRow(1, 'test_note', 'Own rule text.');
        foreach (array_keys($row->toArray()) as $field) {
            $payload = $row->toArray();
            unset($payload[$field]);
            try {
                HilosModerationRuleTableRow::fromArray($payload);
                $this->fail('A missing rule field must be refused: ' . $field);
            } catch (InvalidFormatException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach ([['id' => '1'], ['id' => null], ['id' => 0], ['id' => -1], ['kind' => 1], ['rule' => []]] as $invalid) {
            try {
                HilosModerationRuleTableRow::fromArray(array_replace($row->toArray(), $invalid));
                $this->fail('A mistyped rule field or nonpositive id must be refused');
            } catch (InvalidFormatException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** A selected kind keeps its live values while source facts for other kinds never enter its window. */
    public function testExactKindWindowOnlyReceivesItsOwnRuleCountsAndModelProfile(): void
    {
        $browser = $this->bootBrowser();
        $window = $this->openWindow($browser, 'selected', HilosModerationKindsTable::TABLE, 'test_note', limit: 1);
        $this->assertSame(['test_note'], array_column($window->rows, 'rowKey'));
        $this->assertSame(1, $window->totalCount);
        $unknown = $this->openWindow($browser, 'unknown', HilosModerationKindsTable::TABLE, 'unknown_kind', limit: 1);
        $this->assertSame([], $unknown->rows);
        $this->assertSame(0, $unknown->totalCount);

        $foreign = Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::PICTURE, 'Foreign picture rule.');
        $this->assertSame([], $this->flushBrowser($browser));
        Hilos::$db->moderationRules->actions->create(ModerationKindKey::PROFILE_NAME, 'Foreign text rule.');
        $this->assertSame([], $this->flushBrowser($browser));
        $foreign->actions->update('Edited foreign rule.');
        $this->assertSame([], $this->flushBrowser($browser));
        $foreign->actions->delete();
        $this->assertSame([], $this->flushBrowser($browser));

        $own = Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'Selected rule.');
        $frames = $this->flushBrowser($browser);
        $this->assertRoutes($frames, [['selected', HilosModerationKindsTable::TABLE, SignalTypeConstants::TABLE_VIEWPORT_DELTA]]);
        $this->assertKindDelta($frames[0]['data'], 'test_note', 1);
        $this->assertSame('hilos.moderation.text', $frames[0]['data']['row']['slots']['kind']['profile']);
        $this->assertSame(['rude'], $frames[0]['data']['row']['slots']['kind']['reasons']);

        Hilos::$db->settings->actions->add(ModerationSettingsCatalog::IMAGE_MODEL, 'foreign-model', Hilos::$setting->catalog());
        $this->assertSame([], $this->flushBrowser($browser));
        Hilos::$db->settings->actions->add(ModerationSettingsCatalog::TEXT_MODEL, 'selected-model', Hilos::$setting->catalog());
        $frames = $this->flushBrowser($browser);
        $this->assertRoutes($frames, [['selected', HilosModerationKindsTable::TABLE, SignalTypeConstants::TABLE_VIEWPORT_DELTA]]);
        $this->assertKindDelta($frames[0]['data'], 'test_note', 1);
        $this->assertSame('selected-model', $frames[0]['data']['row']['slots']['kind']['model']);
        $own->actions->delete();
        $frames = $this->flushBrowser($browser);
        $this->assertRoutes($frames, [['selected', HilosModerationKindsTable::TABLE, SignalTypeConstants::TABLE_VIEWPORT_DELTA]]);
        $this->assertKindDelta($frames[0]['data'], 'test_note', 0);
        $this->assertSame(1, Hilos::$sr->getTableViewport('selected', HilosModerationKindsTable::TABLE)->totalCount());
        $this->assertSame(0, Hilos::$sr->getTableViewport('unknown', HilosModerationKindsTable::TABLE)->totalCount());
    }

    /** Real delivered digests, kind filters and search scopes govern action-driven delivery. */
    public function testDeliveredWindowsFollowRuleCrudWithoutReloadsOrCrossingKindAndSearch(): void
    {
        $note = Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'Match first.');
        $picture = Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::PICTURE, 'Photo first.');
        $browser = $this->bootBrowser();
        $this->openWindow($browser, 'catalog', HilosModerationKindsTable::TABLE);
        $this->openWindow($browser, 'notes', HilosModerationRulesTable::TABLE, ModerationTablesTestDirectory::NOTE, 'Match');
        $this->openWindow($browser, 'pictures', HilosModerationRulesTable::TABLE, ModerationTablesTestDirectory::PICTURE, 'Photo');

        $note->actions->update('Match edited.');
        $this->assertArrayNotHasKey(ObjectModerationRule::kind, $this->onlyChange()->row);
        $frames = $this->flushBrowser($browser);
        $this->assertRoutes($frames, [['notes', HilosModerationRulesTable::TABLE, SignalTypeConstants::TABLE_VIEWPORT_DELTA]]);
        $this->assertRuleDelta($frames[0]['data'], $note->id, 'Match edited.');

        $excluded = Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'Outside search.');
        $frames = $this->flushBrowser($browser);
        $this->assertRoutes($frames, [['catalog', HilosModerationKindsTable::TABLE, SignalTypeConstants::TABLE_VIEWPORT_DELTA]]);
        $this->assertKindDelta($frames[0]['data'], ModerationTablesTestDirectory::NOTE, 2);

        $second = Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'Match second.');
        $frames = $this->flushBrowser($browser);
        $this->assertRoutes($frames, [
            ['catalog', HilosModerationKindsTable::TABLE, SignalTypeConstants::TABLE_VIEWPORT_DELTA],
            ['notes', HilosModerationRulesTable::TABLE, SignalTypeConstants::TABLE_VIEWPORT_APPEND],
        ]);
        $append = $this->frameFor($frames, 'notes', SignalTypeConstants::TABLE_VIEWPORT_APPEND);
        $this->assertSame(self::ruleWire($second->id, 'Match second.'), $append['row']);
        $this->assertSame(2, $append['totalCount']);
        $this->assertTrue($append['totalExact']);
        $this->assertSame(1, $append['pageCount']);
        $this->assertKindDelta($this->frameFor($frames, 'catalog', SignalTypeConstants::TABLE_VIEWPORT_DELTA), 'test_note', 3);

        $picture->actions->update('Photo edited.');
        $frames = $this->flushBrowser($browser);
        $this->assertRoutes($frames, [['pictures', HilosModerationRulesTable::TABLE, SignalTypeConstants::TABLE_VIEWPORT_DELTA]]);
        $this->assertRuleDelta($frames[0]['data'], $picture->id, 'Photo edited.', ModerationTablesTestDirectory::PICTURE);

        foreach ([[$note, 2, 1], [$second, 1, 0]] as [$deleted, $ownCount, $matchingCount]) {
            $id = $deleted->id;
            $deleted->actions->delete();
            $this->assertArrayNotHasKey($id, Hilos::$db->moderationRules);
            $frames = $this->flushBrowser($browser);
            $this->assertRoutes($frames, [
                ['catalog', HilosModerationKindsTable::TABLE, SignalTypeConstants::TABLE_VIEWPORT_DELTA],
                ['notes', HilosModerationRulesTable::TABLE, SignalTypeConstants::TABLE_VIEWPORT_DELTA],
                ['notes', HilosModerationRulesTable::TABLE, SignalTypeConstants::TABLE_VIEWPORT_COUNT],
                ['pictures', HilosModerationRulesTable::TABLE, SignalTypeConstants::TABLE_VIEWPORT_UNANNOUNCE],
            ]);
            $this->assertSame([
                'page' => ModerationTablesBrowser::PAGE, 'tableKey' => HilosModerationRulesTable::TABLE, 'rowKey' => (string) $id,
            ], $this->frameFor($frames, 'pictures', SignalTypeConstants::TABLE_VIEWPORT_UNANNOUNCE));
            $delta = $this->frameFor($frames, 'notes', SignalTypeConstants::TABLE_VIEWPORT_DELTA);
            $this->assertSame(TableViewportDeltaDTO::KIND_ROW_REMOVED, $delta['kind']);
            $this->assertSame($id, $delta['rowKey']);
            $this->assertSame(TableViewportDeltaDTO::REASON_DELETED, $delta['reason']);
            $this->assertArrayNotHasKey('row', $delta);
            $count = $this->frameFor($frames, 'notes', SignalTypeConstants::TABLE_VIEWPORT_COUNT);
            $this->assertSame($matchingCount, $count['totalCount']);
            $this->assertTrue($count['totalExact']);
            $this->assertSame(1, $count['pageCount']);
            $this->assertKindDelta($this->frameFor($frames, 'catalog', SignalTypeConstants::TABLE_VIEWPORT_DELTA), 'test_note', $ownCount);
        }
        $excluded->actions->delete();
        $frames = $this->flushBrowser($browser);
        $this->assertRoutes($frames, [
            ['catalog', HilosModerationKindsTable::TABLE, SignalTypeConstants::TABLE_VIEWPORT_DELTA],
            ['notes', HilosModerationRulesTable::TABLE, SignalTypeConstants::TABLE_VIEWPORT_UNANNOUNCE],
            ['pictures', HilosModerationRulesTable::TABLE, SignalTypeConstants::TABLE_VIEWPORT_UNANNOUNCE],
        ]);
        foreach (['notes', 'pictures'] as $acceptKey) {
            $this->assertSame([
                'page' => ModerationTablesBrowser::PAGE, 'tableKey' => HilosModerationRulesTable::TABLE, 'rowKey' => (string) $excluded->id,
            ], $this->frameFor($frames, $acceptKey, SignalTypeConstants::TABLE_VIEWPORT_UNANNOUNCE));
        }
        $this->assertKindDelta($this->frameFor($frames, 'catalog', SignalTypeConstants::TABLE_VIEWPORT_DELTA), 'test_note', 0);
        $this->assertSame([], new HilosModerationRulesTable()->getPage(new TableQueryDTO(filter: ['kind' => 'test_note']))->rows);
        $this->assertSame(0, Hilos::$sr->getTableViewport('notes', HilosModerationRulesTable::TABLE)->totalCount());
    }

    /** A row above a full window is announced; a tail beyond it changes only the exact count. */
    public function testCreatedRulesUseTheirActualPlaceForAnnouncementAndCount(): void
    {
        Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'Middle');
        Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'Zebra');
        $browser = $this->bootBrowser();
        $this->openWindow(
            $browser, 'full', HilosModerationRulesTable::TABLE, ModerationTablesTestDirectory::NOTE,
            limit: 1, sort: TableSortOrderDTO::of(new TableSortDTO('rule')),
        );
        $this->openWindow($browser, 'other', HilosModerationRulesTable::TABLE, ModerationTablesTestDirectory::PICTURE, 'Alpha');

        $above = Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'Alpha');
        $frames = $this->flushBrowser($browser);
        $this->assertRoutes($frames, [['full', HilosModerationRulesTable::TABLE, SignalTypeConstants::TABLE_VIEWPORT_ANNOUNCE]]);
        $this->assertSame((string) $above->id, $frames[0]['data']['rowKey']);
        $this->assertSame('above', $frames[0]['data']['placement']);
        $this->assertSame(3, $frames[0]['data']['totalCount']);
        $this->assertTrue($frames[0]['data']['totalExact']);
        $this->assertSame(3, $frames[0]['data']['pageCount']);

        Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'Tail');
        $frames = $this->flushBrowser($browser);
        $this->assertRoutes($frames, [['full', HilosModerationRulesTable::TABLE, SignalTypeConstants::TABLE_VIEWPORT_COUNT]]);
        $this->assertSame(4, $frames[0]['data']['totalCount']);
        $this->assertTrue($frames[0]['data']['totalExact']);
        $this->assertSame(4, $frames[0]['data']['pageCount']);
    }

    /** Model values fan out to both kinds of just their input profile through ordinary row deltas. */
    public function testModelSettingActionsDeliverOnlyTheirProfileAndIgnoreUnrelatedSettings(): void
    {
        $browser = $this->bootBrowser();
        $this->openWindow($browser, 'catalog', HilosModerationKindsTable::TABLE);
        $this->openWindow($browser, 'rules', HilosModerationRulesTable::TABLE, ModerationTablesTestDirectory::NOTE);
        foreach ([
            [ModerationSettingsCatalog::TEXT_MODEL, 'text-default', ['hilos_profile_name', 'test_note']],
            [ModerationSettingsCatalog::IMAGE_MODEL, 'vision-default', ['hilos_profile_photo', 'test_picture']],
        ] as [$key, $default, $kinds]) {
            Hilos::$db->settings->actions->add($key, 'added-model', Hilos::$setting->catalog());
            $this->assertDeliveredModels($this->flushBrowser($browser), $kinds, 'added-model');
            Hilos::$db->settings[$key]->actions->updateValue('edited-model');
            $this->assertDeliveredModels($this->flushBrowser($browser), $kinds, 'edited-model');
            Hilos::$db->settings[$key]->actions->delete();
            $this->assertDeliveredModels($this->flushBrowser($browser), $kinds, $default);
        }
        Hilos::$db->settings->actions->add(ModerationTablesSettingsCatalog::UNRELATED, 'new', Hilos::$setting->catalog());
        $this->assertSame([], $this->flushBrowser($browser));
        Hilos::$db->settings[ModerationTablesSettingsCatalog::UNRELATED]->actions->updateValue('changed');
        $this->assertSame([], $this->flushBrowser($browser));
        Hilos::$db->settings[ModerationTablesSettingsCatalog::UNRELATED]->actions->delete();
        $this->assertSame([], $this->flushBrowser($browser));
    }

    /** Viewer serialization uses real Entity verdicts and each row's setting origin, in both directions. */
    public function testViewerFramesShowRuleColumnsAndComputedKindsButOnlyTheOpenModelProfile(): void
    {
        $rule = Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'Viewer rule.');
        Hilos::$db->settings->actions->add(ModerationSettingsCatalog::TEXT_MODEL, 'text-visible', Hilos::$setting->catalog());
        Hilos::$db->settings->actions->add(ModerationSettingsCatalog::IMAGE_MODEL, 'image-visible', Hilos::$setting->catalog());
        foreach ([
            [ModerationTablesTextHiddenCatalog::class, ModerationSettingsCatalog::TEXT_MODEL, ModerationSettingsCatalog::IMAGE_MODEL,
                ['hilos_profile_photo', 'test_picture']],
            [ModerationTablesImageHiddenCatalog::class, ModerationSettingsCatalog::IMAGE_MODEL, ModerationSettingsCatalog::TEXT_MODEL,
                ['hilos_profile_name', 'test_note']],
        ] as [$catalog, $closed, $open, $openKinds]) {
            Hilos::$sr = new SignalRouter();
            Hilos::$setting = new SettingsAccessor($catalog);
            $browser = $this->bootBrowser(viewer: true);
            $window = $this->openWindow($browser, 'catalog', HilosModerationKindsTable::TABLE);
            $this->assertSame(self::kindKeys(), array_column($window->rows, 'rowKey'));
            foreach ($window->rows as $row) {
                $kind = $row['slots']['kind'];
                $this->assertSame(['kind', 'title', 'owner', 'input', 'model', 'ownRuleCount', 'profile', 'reasons'], array_keys($kind));
                $this->assertSame($row['rowKey'], $kind['kind']);
                $this->assertIsString($kind['title']);
                $this->assertContains($kind['owner'], ['framework', 'project']);
                $this->assertContains($kind['input'], ['text', 'image', 'text_with_files']);
                $this->assertSame($row['rowKey'] === 'test_note' ? 1 : 0, $kind['ownRuleCount']);
                $this->assertSame(
                    $kind['input'] === 'image' ? 'hilos.moderation.image' : 'hilos.moderation.text',
                    $kind['profile'],
                );
                $this->assertSame(Hilos::moderationKinds()[$row['rowKey']]->reasons, $kind['reasons']);
                $this->assertSame(
                    in_array($row['rowKey'], $openKinds, true) ? Hilos::$setting[$open]->string() : HiddenValue::mark(),
                    $kind['model'],
                );
            }
            $rules = $this->openWindow($browser, 'notes', HilosModerationRulesTable::TABLE, ModerationTablesTestDirectory::NOTE);
            $this->assertSame([self::ruleWire($rule->id, $rule->rule)], $rules->rows);
            $rule->actions->update($closed . ' edited rule.');
            $frames = $this->flushBrowser($browser);
            $this->assertRoutes($frames, [['notes', HilosModerationRulesTable::TABLE, SignalTypeConstants::TABLE_VIEWPORT_DELTA]]);
            $this->assertRuleDelta($frames[0]['data'], $rule->id, $closed . ' edited rule.');

            Hilos::$db->settings[$closed]->actions->updateValue($closed . '-secret-change');
            $this->assertSame([], $this->flushBrowser($browser));
            Hilos::$db->settings[$open]->actions->updateValue($open . '-visible-change');
            $frames = $this->flushBrowser($browser);
            $this->assertRoutes($frames, array_fill(0, 2, ['catalog', HilosModerationKindsTable::TABLE, SignalTypeConstants::TABLE_VIEWPORT_DELTA]));
            $this->assertSame($openKinds, array_column(array_column($frames, 'data'), 'rowKey'));
            foreach ($frames as $frame) {
                $this->assertKindDelta($frame['data'], $frame['data']['rowKey'], $frame['data']['rowKey'] === 'test_note' ? 1 : 0);
                $this->assertSame($open . '-visible-change', $frame['data']['row']['slots']['kind']['model']);
            }
        }
    }

    /** Actual SQL read failures freeze delivered surfaces; a later real rule fact pays their window debts. */
    public function testFailedReadsFreezeWithoutEmptySuccessAndTheNextSourceFactRecoversBothTables(): void
    {
        $rule = Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'Before failure.');
        $browser = $this->bootBrowser();
        $kinds = $this->openWindow($browser, 'catalog', HilosModerationKindsTable::TABLE);
        $rules = $this->openWindow($browser, 'notes', HilosModerationRulesTable::TABLE, ModerationTablesTestDirectory::NOTE);
        $rule->actions->update('Buffered before failure.');
        self::runStubs(down: true);
        Hilos::$db->forgetLoadedDataForTest();
        $browser->flushToSignalRouter();
        $frames = $this->takeBrowserFrames();
        $this->assertRoutes($frames, [
            ['catalog', HilosModerationKindsTable::TABLE, SignalTypeConstants::TABLE_VIEWPORT_FROZEN],
            ['notes', HilosModerationRulesTable::TABLE, SignalTypeConstants::TABLE_VIEWPORT_FROZEN],
        ]);
        foreach ([['catalog', $kinds], ['notes', $rules]] as [$acceptKey, $window]) {
            $this->assertTrue(Hilos::$sr->isTableWindowOwed($acceptKey, $window->tableKey));
            $viewport = Hilos::$sr->getTableViewport($acceptKey, $window->tableKey);
            $this->assertSame($window->totalCount, $viewport->totalCount());
            foreach ($window->rows as $row) {
                $this->assertTrue($viewport->matchesRow((string) $row['rowKey'], $row));
            }
        }
        self::runStubs(down: false);
        $browser->flushToSignalRouter();
        $this->assertSame([], $this->takeBrowserFrames());

        $recovered = Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'After recovery.');
        $browser->flushToSignalRouter();
        $frames = $this->takeBrowserFrames(allowWindow: true);
        $this->assertRoutes($frames, [
            ['catalog', HilosModerationKindsTable::TABLE, SignalTypeConstants::TABLE_WINDOW],
            ['notes', HilosModerationRulesTable::TABLE, SignalTypeConstants::TABLE_WINDOW],
        ]);
        $rules = $this->frameFor($frames, 'notes', SignalTypeConstants::TABLE_WINDOW);
        $this->assertSame([self::ruleWire($recovered->id, 'After recovery.')], $rules['rows']);
        $this->assertSame(1, $rules['totalCount']);
        $this->assertTrue($rules['totalExact']);
        $kinds = $this->frameFor($frames, 'catalog', SignalTypeConstants::TABLE_WINDOW);
        $this->assertSame(4, $kinds['totalCount']);
        $this->assertTrue($kinds['totalExact']);
        $this->assertSame([0, 0, 1, 0], array_column(array_column(array_column($kinds['rows'], 'slots'), 'kind'), 'ownRuleCount'));
        foreach ([['catalog', HilosModerationKindsTable::TABLE], ['notes', HilosModerationRulesTable::TABLE]] as [$acceptKey, $key]) {
            $this->assertFalse(Hilos::$sr->isTableWindowOwed($acceptKey, $key));
        }
        $recovered->actions->update('Live again.');
        $frames = $this->flushBrowser($browser);
        $this->assertRoutes($frames, [['notes', HilosModerationRulesTable::TABLE, SignalTypeConstants::TABLE_VIEWPORT_DELTA]]);
        $this->assertRuleDelta($frames[0]['data'], $recovered->id, 'Live again.');
    }

    /** An initial SQL refusal owes whole windows too; it must not report an empty rules set or Built-in counts. */
    public function testInitiallyRefusedWindowsRecoverOnARealRuleFact(): void
    {
        $browser = $this->bootBrowser();
        self::runStubs(down: true);
        foreach ([['catalog', HilosModerationKindsTable::TABLE, []], ['notes', HilosModerationRulesTable::TABLE, ['kind' => 'test_note']]] as [
            $acceptKey, $key, $filter,
        ]) {
            Hilos::$sr->subscribeToPage(
                ModerationTablesBrowser::PAGE,
                new WebSocketPageSubscribeSignalDTO($acceptKey, ModerationTablesBrowser::PAGE),
            );
            $viewport = new TableViewportSubscription(tableKey: $key, filter: $filter, limit: 5);
            Hilos::$sr->setTableViewport($acceptKey, $viewport);
            $this->assertFalse($browser->sendTableWindow(ModerationTablesBrowser::PAGE, $acceptKey, $viewport));
            $frames = $this->takeBrowserFrames();
            $this->assertRoutes($frames, [[$acceptKey, $key, SignalTypeConstants::TABLE_WINDOW_REFUSED]]);
            $this->assertSame('internal_error', $frames[0]['data']['errorCode']);
            $this->assertArrayNotHasKey('rows', $frames[0]['data']);
            $this->assertArrayNotHasKey('totalCount', $frames[0]['data']);
            $this->assertTrue(Hilos::$sr->isTableWindowOwed($acceptKey, $key));
        }
        self::runStubs(down: false);
        $rule = Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'First successful read.');
        $browser->flushToSignalRouter();
        $frames = $this->takeBrowserFrames(allowWindow: true);
        $this->assertRoutes($frames, [
            ['catalog', HilosModerationKindsTable::TABLE, SignalTypeConstants::TABLE_WINDOW],
            ['notes', HilosModerationRulesTable::TABLE, SignalTypeConstants::TABLE_WINDOW],
        ]);
        $rules = $this->frameFor($frames, 'notes', SignalTypeConstants::TABLE_WINDOW);
        $this->assertSame([self::ruleWire($rule->id, 'First successful read.')], $rules['rows']);
        $this->assertSame(1, $rules['totalCount']);
        $kinds = $this->frameFor($frames, 'catalog', SignalTypeConstants::TABLE_WINDOW);
        $this->assertSame([0, 0, 1, 0], array_column(array_column(array_column($kinds['rows'], 'slots'), 'kind'), 'ownRuleCount'));
    }

    /** Two independent contexts in one process follow existing DB-sync DTOs; this does not simulate a cluster. */
    public function testIndependentDbSyncReaderDeliversTheSameFreshRowsAndCountsWithoutSelfEcho(): void
    {
        $writerBrowser = $this->bootBrowser();
        $this->openWindow($writerBrowser, 'catalog', HilosModerationKindsTable::TABLE);
        $this->openWindow($writerBrowser, 'notes', HilosModerationRulesTable::TABLE, ModerationTablesTestDirectory::NOTE, 'Match');
        $this->openWindow($writerBrowser, 'pictures', HilosModerationRulesTable::TABLE, ModerationTablesTestDirectory::PICTURE, 'Photo');
        $writer = [Hilos::$db, Hilos::$sr];
        Hilos::$db = new ModerationTablesTestDbContext();
        Hilos::$db->configure();
        Hilos::$sr = new SignalRouter();
        $readerBrowser = $this->bootBrowser();
        $this->openWindow($readerBrowser, 'catalog', HilosModerationKindsTable::TABLE);
        $this->openWindow($readerBrowser, 'notes', HilosModerationRulesTable::TABLE, ModerationTablesTestDirectory::NOTE, 'Match');
        $this->openWindow($readerBrowser, 'pictures', HilosModerationRulesTable::TABLE, ModerationTablesTestDirectory::PICTURE, 'Photo');
        $reader = [Hilos::$db, Hilos::$sr];
        $this->assertNotSame($writer[0], $reader[0]);
        $this->assertNotSame($writer[1]->getEmitter(), $reader[1]->getEmitter());

        $ruleId = null;
        foreach (['create', 'update', 'delete'] as $operation) {
            [Hilos::$db, Hilos::$sr] = $writer;
            ModerationTablesTestHilos::initBrowser($writerBrowser);
            if ($operation === 'create') {
                $ruleId = Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'Match created.')->id;
            } elseif ($operation === 'update') {
                Hilos::$db->moderationRules[$ruleId]->actions->update('Match updated.');
            } else {
                Hilos::$db->moderationRules[$ruleId]->actions->delete();
            }
            $this->assertNotNull($ruleId);
            $sync = $this->takeDbSyncFrame();
            if ($operation === 'update') {
                $this->assertArrayNotHasKey(ObjectModerationRule::kind, $sync->row);
            }
            if ($operation === 'delete') {
                $this->assertSame(ModerationTablesTestDirectory::NOTE, $sync->row[ObjectModerationRule::kind]);
                $this->assertArrayNotHasKey($ruleId, Hilos::$db->moderationRules);
            }
            $local = $this->flushBrowser($writerBrowser);
            $this->assertNotEmpty($local);
            $this->changes->taken = [];
            self::applyDbSyncFrame($sync);
            $this->assertSame([], $this->changes->taken);
            $this->assertFalse($writerBrowser->hasChanges());
            $this->assertSame([], $this->flushBrowser($writerBrowser));

            [Hilos::$db, Hilos::$sr] = $reader;
            ModerationTablesTestHilos::initBrowser($readerBrowser);
            self::applyDbSyncFrame($sync);
            $this->assertCount(1, $this->changes->taken);
            $this->changes->taken = [];
            $this->assertSame($local, $this->flushBrowser($readerBrowser));
            $count = $operation === 'delete' ? 0 : 1;
            $this->assertSame($count, new HilosModerationKindsTable()->findRow('test_note')->ownRuleCount);
            $snapshot = new HilosModerationRulesTable()->getPage(new TableQueryDTO(search: 'Match', filter: ['kind' => 'test_note']));
            $this->assertSame($count, $snapshot->totalCount);
            $this->assertTrue($snapshot->totalExact);
            $this->assertSame($operation === 'delete' ? [] : [[
                'id' => $ruleId, 'kind' => 'test_note', 'rule' => $operation === 'create' ? 'Match created.' : 'Match updated.',
            ]], array_map(static fn (AbstractTableRow $row): array => $row->toArray(), $snapshot->rows));
        }
        foreach ([
            [ModerationSettingsCatalog::TEXT_MODEL, 'text-default', ['hilos_profile_name', 'test_note']],
            [ModerationSettingsCatalog::IMAGE_MODEL, 'vision-default', ['hilos_profile_photo', 'test_picture']],
        ] as [$key, $default, $kinds]) {
            foreach (['create', 'update', 'delete'] as $operation) {
                [Hilos::$db, Hilos::$sr] = $writer;
                ModerationTablesTestHilos::initBrowser($writerBrowser);
                if ($operation === 'create') {
                    Hilos::$db->settings->actions->add($key, 'remote-created', Hilos::$setting->catalog());
                } elseif ($operation === 'update') {
                    Hilos::$db->settings[$key]->actions->updateValue('remote-updated');
                } else {
                    Hilos::$db->settings[$key]->actions->delete();
                }
                $sync = $this->takeDbSyncFrame();
                $local = $this->flushBrowser($writerBrowser);
                $model = match ($operation) {
                    'create' => 'remote-created', 'update' => 'remote-updated', 'delete' => $default,
                };
                $this->assertDeliveredModels($local, $kinds, $model);
                $this->changes->taken = [];
                self::applyDbSyncFrame($sync);
                $this->assertSame([], $this->changes->taken);
                $this->assertSame([], $this->flushBrowser($writerBrowser));

                [Hilos::$db, Hilos::$sr] = $reader;
                ModerationTablesTestHilos::initBrowser($readerBrowser);
                self::applyDbSyncFrame($sync);
                $this->assertCount(1, $this->changes->taken);
                $this->changes->taken = [];
                $this->assertSame($local, $this->flushBrowser($readerBrowser));
                foreach ($kinds as $kind) {
                    $this->assertSame($model, new HilosModerationKindsTable()->findRow($kind)->model);
                }
            }
        }
    }

    /** The engine marks movement, loss of search membership and the originating connection on real edits. */
    public function testRuleEditsDeliverMovementAndSearchRemovalWithTheCorrectOwnFlag(): void
    {
        Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'Alpha');
        Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'Beta');
        $rule = Hilos::$db->moderationRules->actions->create(ModerationTablesTestDirectory::NOTE, 'Gamma');
        $browser = $this->bootBrowser();
        foreach (['author', 'observer'] as $acceptKey) {
            $this->openWindow(
                $browser, $acceptKey, HilosModerationRulesTable::TABLE, ModerationTablesTestDirectory::NOTE,
                sort: TableSortOrderDTO::of(new TableSortDTO('rule')),
            );
        }
        $this->openWindow($browser, 'filtered', HilosModerationRulesTable::TABLE, ModerationTablesTestDirectory::NOTE, 'Gamma');
        ExecutionContext::withOrigin('author', 'edit-rule', static fn () => $rule->actions->update('Aardvark'));
        $frames = $this->flushBrowser($browser);
        $this->assertRoutes($frames, [
            ['author', HilosModerationRulesTable::TABLE, SignalTypeConstants::TABLE_VIEWPORT_DELTA],
            ['observer', HilosModerationRulesTable::TABLE, SignalTypeConstants::TABLE_VIEWPORT_DELTA],
            ['filtered', HilosModerationRulesTable::TABLE, SignalTypeConstants::TABLE_VIEWPORT_DELTA],
            ['filtered', HilosModerationRulesTable::TABLE, SignalTypeConstants::TABLE_VIEWPORT_COUNT],
        ]);
        foreach (['author', 'observer'] as $acceptKey) {
            $delta = $this->frameFor($frames, $acceptKey, SignalTypeConstants::TABLE_VIEWPORT_DELTA);
            $this->assertSame(TableViewportDeltaDTO::KIND_ROW_MOVED, $delta['kind']);
            $this->assertSame($rule->id, $delta['rowKey']);
            $this->assertSame(self::ruleWire($rule->id, 'Aardvark'), $delta['row']);
            $this->assertSame(0, $delta['position']);
            $this->assertSame($acceptKey === 'author', $delta['own'] ?? false);
        }
        $removed = $this->frameFor($frames, 'filtered', SignalTypeConstants::TABLE_VIEWPORT_DELTA);
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_REMOVED, $removed['kind']);
        $this->assertSame($rule->id, $removed['rowKey']);
        $this->assertSame(TableViewportDeltaDTO::REASON_LEFT_SET, $removed['reason']);
        $count = $this->frameFor($frames, 'filtered', SignalTypeConstants::TABLE_VIEWPORT_COUNT);
        $this->assertSame(0, $count['totalCount']);
        $this->assertTrue($count['totalExact']);
    }

    /** @return DbSyncCreatedSignalData|DbSyncUpdatedSignalData|DbSyncDeletedSignalData Sole real action sync frame */
    private function takeDbSyncFrame(): DbSyncCreatedSignalData|DbSyncUpdatedSignalData|DbSyncDeletedSignalData
    {
        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalSource::DB, $signal->signalSource->getSource());
        $this->assertTrue($signal->data instanceof DbSyncCreatedSignalData
            || $signal->data instanceof DbSyncUpdatedSignalData || $signal->data instanceof DbSyncDeletedSignalData);
        $this->assertSame(Hilos::$sr->getEmitter(), $signal->data->emitter);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());

        return $signal->data;
    }

    /**
     * @param DbSyncCreatedSignalData|DbSyncUpdatedSignalData|DbSyncDeletedSignalData $frame Existing stamped sync payload
     */
    private static function applyDbSyncFrame(DbSyncCreatedSignalData|DbSyncUpdatedSignalData|DbSyncDeletedSignalData $frame): void
    {
        // Read the existing wire representation, without a fixture-only field or transport.
        $frame = $frame::fromArray($frame->toArray());
        if ($frame instanceof DbSyncCreatedSignalData) {
            DbSyncApplicator::applyCreated($frame, originNodeId: 'other-node');
        } elseif ($frame instanceof DbSyncUpdatedSignalData) {
            DbSyncApplicator::applyUpdated($frame, originNodeId: 'other-node');
        } else {
            DbSyncApplicator::applyDeleted($frame, originNodeId: 'other-node');
        }
    }

    /**
     * @param bool $viewer Whether the fixture gate admits the connections as viewers
     * @return ModerationTablesBrowser Local browser over the real framework tables
     */
    private function bootBrowser(bool $viewer = false): ModerationTablesBrowser
    {
        Hilos::$table = new ModerationTablesTableContext();
        Hilos::$table->configure();
        $browser = new ModerationTablesBrowser($viewer);
        ModerationTablesTestHilos::initBrowser($browser);
        $this->changes->deliverToBrowser = true;
        $this->changes->taken = [];
        $this->takeBrowserFrames();

        return $browser;
    }

    /**
     * Serves an actual initial window, including its delivered digests and anchors.
     *
     * @param ModerationTablesBrowser $browser Browser serving the local subscription
     * @param string $acceptKey Address of the connection
     * @param string $tableKey Registered table key
     * @param ?string $kind Exact kind selection, absent for the complete directory
     * @param ?string $search Search within the selected kind
     * @param int $limit Requested rows per window
     * @param ?TableSortOrderDTO $sort Requested order, defaulting to the table's own
     * @return TableWindowSignalData Actually queued initial window
     */
    private function openWindow(
        ModerationTablesBrowser $browser,
        string $acceptKey,
        string $tableKey,
        ?string $kind = null,
        ?string $search = null,
        int $limit = 5,
        ?TableSortOrderDTO $sort = null,
    ): TableWindowSignalData {
        Hilos::$sr->subscribeToPage(
            ModerationTablesBrowser::PAGE,
            new WebSocketPageSubscribeSignalDTO($acceptKey, ModerationTablesBrowser::PAGE),
        );
        $filter = $kind === null ? [] : [
            ($tableKey === HilosModerationKindsTable::TABLE ? HilosModerationKindsTable::FILTER_KIND : HilosModerationRulesTable::FILTER_KIND)
                => $kind,
        ];
        if ($search !== null) {
            $filter[TableConstants::FILTER_KEY_SEARCH] = $search;
        }
        $viewport = $browser->viewportForViewer(ModerationTablesBrowser::PAGE, $acceptKey, new TableViewportSubscription(
            tableKey: $tableKey, filter: $filter, sort: $sort ?? Hilos::$table->get($tableKey)->defaultSort(), limit: $limit,
        ));
        Hilos::$sr->setTableViewport($acceptKey, $viewport);
        $this->assertTrue($browser->sendTableWindow(ModerationTablesBrowser::PAGE, $acceptKey, $viewport));
        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::TABLE_WINDOW, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame($acceptKey, $signal->data->targetAcceptKey);
        $window = $signal->data->data;
        $this->assertInstanceOf(TableWindowSignalData::class, $window);
        $this->assertSame(ModerationTablesBrowser::PAGE, $window->page);
        $this->assertSame($tableKey, $window->tableKey);
        $this->assertTrue($window->totalExact);
        foreach ($window->rows as $row) {
            $key = (string) $row[PagePayload::rowKey];
            $this->assertTrue($viewport->hasRow($key));
            $this->assertTrue($viewport->matchesRow($key, $row));
            $this->assertArrayNotHasKey(BrowserPageSignalData::sources, $row);
            $this->assertSame([$tableKey === HilosModerationKindsTable::TABLE ? 'kind' : 'rule'], array_keys($row[PagePayload::slots]));
        }
        $this->assertSame($window->totalCount, $viewport->totalCount());
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());

        return $window;
    }

    /**
     * @param BrowserContext $browser Browser with real action facts in its buffer
     * @return list<array{target: string, name: string, data: array<string, mixed>}> Addressed frames
     */
    private function flushBrowser(BrowserContext $browser): array
    {
        $this->assertSame([], $browser->flushToSignalRouter());

        return $this->takeBrowserFrames();
    }

    /**
     * @param bool $allowWindow Whether this is a recovery flush allowed to send the owed window
     * @return list<array{target: string, name: string, data: array<string, mixed>}> All addressed frames, without DB sync traffic
     */
    private function takeBrowserFrames(bool $allowWindow = false): array
    {
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalSource->getSource() === SignalSource::DB) {
                continue;
            }
            $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
            $name = $signal->signalName->getName();
            $this->assertNotSame(SignalTypeConstants::PAGE_RESPONSE, $name);
            if (!$allowWindow) {
                $this->assertNotSame(SignalTypeConstants::TABLE_WINDOW, $name);
            }
            $payload = $signal->data->data->toArray();
            $this->assertSame(ModerationTablesBrowser::PAGE, $payload['page']);
            $frames[] = ['target' => $signal->data->targetAcceptKey, 'name' => $name, 'data' => $payload];
        }

        return $frames;
    }

    /**
     * @param list<array{target: string, name: string, data: array<string, mixed>}> $frames Entire flush
     * @param list<array{string, string, string}> $expected Exact target, table and signal triples, including multiplicity
     */
    private function assertRoutes(array $frames, array $expected): void
    {
        $actual = array_map(static fn (array $frame): array => [$frame['target'], $frame['data']['tableKey'], $frame['name']], $frames);
        sort($actual);
        sort($expected);
        $this->assertSame($expected, $actual);
    }

    /**
     * @param list<array{target: string, name: string, data: array<string, mixed>}> $frames Entire flush
     * @param string $target Expected connection
     * @param string $name Expected signal
     * @return array<string, mixed> The only matching payload
     */
    private function frameFor(array $frames, string $target, string $name): array
    {
        $matching = array_values(array_filter($frames, static fn (array $frame): bool => $frame['target'] === $target && $frame['name'] === $name));
        $this->assertCount(1, $matching);

        return $matching[0]['data'];
    }

    /**
     * @param array<string, mixed> $delta Delivered rule update
     * @param int $id Expected rule primary key
     * @param string $text Expected current text
     * @param string $kind Expected kind
     */
    private function assertRuleDelta(array $delta, int $id, string $text, string $kind = ModerationTablesTestDirectory::NOTE): void
    {
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $delta['kind']);
        $this->assertSame($id, $delta['rowKey']);
        $this->assertSame(self::ruleWire($id, $text, $kind), $delta['row']);
    }

    /**
     * @param int $id Persisted rule key
     * @param string $text Expected text
     * @param string $kind Expected published kind
     * @return array<string, mixed> Exact rule wire fragment
     */
    private static function ruleWire(int $id, string $text, string $kind = ModerationTablesTestDirectory::NOTE): array
    {
        return ['rowKey' => $id, 'slots' => ['rule' => ['id' => $id, 'kind' => $kind, 'rule' => $text]]];
    }

    /**
     * @param array<string, mixed> $delta Delivered directory update
     * @param string $kind Expected kind
     * @param int $count Expected complete own-rule count
     */
    private function assertKindDelta(array $delta, string $kind, int $count): void
    {
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $delta['kind']);
        $this->assertSame($kind, $delta['rowKey']);
        $this->assertSame(['rowKey', 'slots'], array_keys($delta['row']));
        $this->assertSame(['kind'], array_keys($delta['row']['slots']));
        $row = $delta['row']['slots']['kind'];
        $this->assertSame(['kind', 'title', 'owner', 'input', 'model', 'ownRuleCount', 'profile', 'reasons'], array_keys($row));
        $this->assertSame($kind, $row['kind']);
        $this->assertSame($count, $row['ownRuleCount']);
    }

    /**
     * @param list<array{target: string, name: string, data: array<string, mixed>}> $frames Entire flush
     * @param list<string> $kinds Exactly the affected input profile
     * @param string $model Expected effective model
     */
    private function assertDeliveredModels(array $frames, array $kinds, string $model): void
    {
        $this->assertRoutes($frames, array_fill(0, count($kinds), [
            'catalog', HilosModerationKindsTable::TABLE, SignalTypeConstants::TABLE_VIEWPORT_DELTA,
        ]));
        $this->assertSame($kinds, array_column(array_column($frames, 'data'), 'rowKey'));
        foreach ($frames as $frame) {
            $this->assertKindDelta($frame['data'], $frame['data']['rowKey'], 0);
            $this->assertSame($model, $frame['data']['row']['slots']['kind']['model']);
        }
    }

    /** @return SourceChange The sole fact since the previous read, consumed by this helper */
    private function onlyChange(): SourceChange
    {
        $this->assertCount(1, $this->changes->taken);
        $change = $this->changes->taken[0];
        $this->changes->taken = [];

        return $change;
    }

    /**
     * @param HilosModerationRulesTable $table Table to build the mutation
     * @param SourceChange $change Actual rule action fact
     * @param TableMutationType $type Expected source operation
     * @param int $id Expected persisted row key
     * @param string $text Expected current rule text
     */
    private function assertRuleMutation(
        HilosModerationRulesTable $table,
        SourceChange $change,
        TableMutationType $type,
        int $id,
        string $text,
    ): void {
        $mutation = $table->buildMutationForSourceEvent($change);
        $this->assertSame($type, $mutation->type);
        $this->assertSame($id, $mutation->rowKey);
        $this->assertSame($id, $table->rowKeyForSourceEvent($change));
        $this->assertInstanceOf(HilosModerationRuleTableRow::class, $mutation->row);
        $this->assertSame(['id' => $id, 'kind' => 'test_note', 'rule' => $text], $mutation->row->toArray());
        $this->assertSame($table->findRow($id)->toArray(), $mutation->row->toArray());
    }

    /**
     * @param HilosModerationKindsTable $table Table to build the mutation
     * @param SourceChange $change Actual rule action fact
     * @param int $count Expected current count
     */
    private function assertCountMutation(HilosModerationKindsTable $table, SourceChange $change, int $count): void
    {
        $mutations = $table->buildMutationsForSourceEvent($change);
        $this->assertCount(1, $mutations);
        $this->assertSame(TableMutationType::Update, $mutations[0]->type);
        $this->assertSame(ModerationTablesTestDirectory::NOTE, $mutations[0]->rowKey);
        $this->assertInstanceOf(HilosModerationKindTableRow::class, $mutations[0]->row);
        $this->assertSame($count, $mutations[0]->row->ownRuleCount);
        $this->assertSame($table->findRow(ModerationTablesTestDirectory::NOTE)->toArray(), $mutations[0]->row->toArray());
    }

    /**
     * @param HilosModerationKindsTable $table Table to build the mutations
     * @param SourceChange $change Actual setting action fact
     * @param list<string> $kindKeys Expected affected kinds in directory order
     * @param string $model Expected effective model
     */
    private function assertModelMutations(HilosModerationKindsTable $table, SourceChange $change, array $kindKeys, string $model): void
    {
        $mutations = $table->buildMutationsForSourceEvent($change);
        $this->assertSame($kindKeys, array_map(static fn (TableRowMutationDTO $mutation): string => $mutation->rowKey, $mutations));
        foreach ($mutations as $mutation) {
            $this->assertSame(TableMutationType::Update, $mutation->type);
            $this->assertSame($model, $mutation->row->model);
            $this->assertSame($table->findRow($mutation->rowKey)->toArray(), $mutation->row->toArray());
        }
    }

    /**
     * @param TableSnapshotDTO $snapshot Typed snapshot to inspect
     * @return list<string> Row keys in window order
     */
    private static function keys(TableSnapshotDTO $snapshot): array
    {
        return array_map(static fn (AbstractTableRow $row): string => $row->getRowKey(), $snapshot->rows);
    }

    /**
     * @param TableSnapshotDTO $snapshot Rules snapshot to inspect
     * @return list<int> Persisted rule ids in window order
     */
    private static function ruleIds(TableSnapshotDTO $snapshot): array
    {
        return array_map(static fn (HilosModerationRuleTableRow $row): int => $row->getRowKey(), $snapshot->rows);
    }

    /** @return list<string> All fixture kinds in their declared order */
    private static function kindKeys(): array
    {
        return [
            ModerationKindKey::PROFILE_NAME, ModerationKindKey::PROFILE_PHOTO,
            ModerationTablesTestDirectory::NOTE, ModerationTablesTestDirectory::PICTURE,
        ];
    }

    /** @param bool $down Whether to remove rather than create the real stub tables */
    private static function runStubs(bool $down): void
    {
        // external-boundary: the up stub carries no suffix.
        $suffix = $down ? '_down' : '';
        foreach ($down ? array_reverse(self::TABLES) : self::TABLES as $table) {
            Database::sqlRun((string) file_get_contents(dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql"));
        }
    }

    /** @param class-string<Hilos> $hilosClass Test application facade to bind */
    private static function bindAppClass(string $hilosClass): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $hilosClass);
    }
}

/** Framework collections without a project extension. */
final class ModerationTablesTestDbContext extends HilosDbContext
{
}

/** The three input forms and both directory owners, with two kinds per model profile. */
final class ModerationTablesTestDirectory extends ModerationKindDirectory
{
    public const string NOTE = 'test_note';
    public const string PICTURE = 'test_picture';

    /** @return list<ModerationKind> Framework kinds followed by two project kinds */
    protected static function kinds(): array
    {
        return [
            ...parent::kinds(),
            new ModerationKind(self::NOTE, 'Project note', ModerationInput::TEXT_WITH_FILES, ['rude'], ['Polite notes.'], 'test_owner'),
            new ModerationKind(self::PICTURE, 'Project picture', ModerationInput::IMAGE, ['nudity'], ['Safe pictures.'], 'test_owner'),
        ];
    }
}

/** Stable model defaults independent of the stand's environment. */
final class ModerationTablesSettingsCatalog implements CatalogProviderInterface
{
    public const string UNRELATED = 'test.unrelated';

    /** @return array<string, array<string, mixed>> Test defaults over the real moderation catalog */
    public static function getCatalog(): array
    {
        return [
            ModerationSettingsCatalog::TEXT_MODEL => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 'text-default',
                SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
            ],
            ModerationSettingsCatalog::IMAGE_MODEL => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 'vision-default',
                SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
            ],
            self::UNRELATED => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 'unrelated-default',
            ],
        ];
    }
}

/** A project with profile photos and its own directory entries. */
abstract class ModerationTablesTestHilos extends Hilos
{
    public const array PAGES = [ModerationTablesBrowser::PAGE => ModerationTablesPage::class];

    protected const array FEATURES = [HilosFeature::PROFILE_PHOTO];
    protected const string MODERATION_KIND_DIRECTORY = ModerationTablesTestDirectory::class;
}

/** The same project without profile photos. */
abstract class ModerationTablesNoPhotoTestHilos extends Hilos
{
    protected const string MODERATION_KIND_DIRECTORY = ModerationTablesTestDirectory::class;
}

/** Repeated framework kinds make this directory invalid. */
final class ModerationTablesMalformedDirectory extends ModerationKindDirectory
{
    /** @return list<ModerationKind> Deliberately duplicated directory entries */
    protected static function kinds(): array
    {
        return [...parent::kinds(), ...parent::kinds()];
    }
}

/** An invalid directory must refuse a read rather than turn it into an empty success. */
abstract class ModerationTablesMalformedDirectoryTestHilos extends Hilos
{
    protected const string MODERATION_KIND_DIRECTORY = ModerationTablesMalformedDirectory::class;
}

/** Records real bus facts and bridges them to the local browser without starting worker IPC. */
final class ModerationTablesChangeLog implements SourceChangeSubscriberInterface
{
    /** @var list<SourceChange> Announced database facts since the last read */
    public array $taken = [];

    /** Whether this fixture forwards real bus facts to the currently mounted browser. */
    public bool $deliverToBrowser = false;

    /**
     * @param SourceChange $change Fact announced by the real collection
     * @param SourceChangeProvenance $provenance Origin supplied by the bus
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($change->kind === SourceChange::KIND_DB) {
            $this->taken[] = $change;
            if ($this->deliverToBrowser) {
                Hilos::$browser?->record($change);
            }
        }
    }
}

/** Test-only page metadata; no production route, signal or page is registered. */
final class ModerationTablesPage extends AbstractPage
{
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::ADMIN;
    public const bool OPEN_WHILE_FROZEN = true;
}

/** The existing browser engine, with local page bindings and a settled test identity. */
final class ModerationTablesBrowser extends BrowserContext
{
    public const string PAGE = 'moderation_tables_test';

    /** @param bool $viewer Whether the fixture admits a look-only connection */
    public function __construct(private readonly bool $viewer)
    {
        parent::__construct();
    }

    /**
     * @param string $pageClass Local test page
     * @param string $acceptKey Local connection
     * @return bool The fixture's admitted viewer verdict
     */
    public function isAdminViewModeViewer(string $pageClass, string $acceptKey): bool
    {
        return $this->viewer;
    }

    /**
     * @param int $userId Test administrator
     * @return bool The fixture identity has administrator rights
     */
    public function isAdmin(int $userId): bool
    {
        return true;
    }

    /**
     * @param string $acceptKey Local connection
     * @return ConnectionIdentity Settled test administrator identity
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return ConnectionIdentity::resolved(7);
    }

    /**
     * @param string $page Local page key
     * @return ?BrowserPageConfig Existing window transport metadata
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        return $page === self::PAGE ? BrowserPageConfig::fromArray([BrowserConfigKey::SIGNAL => 'moderation_tables_test_signal']) : null;
    }

    /**
     * @param string $page Local page key
     * @return BrowserPageBindings Both real moderation tables
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        return $page === self::PAGE ? BrowserPageBindings::fromArray([
            HilosModerationKindsTable::TABLE => [], HilosModerationRulesTable::TABLE => [],
        ]) : BrowserPageBindings::empty();
    }
}

/** Mounts existing framework tables without extending their behavior. */
final class ModerationTablesTableContext extends TableContext
{
    /** Registers the two production table definitions. */
    public function configure(): void
    {
        $this->register(HilosModerationKindsTable::TABLE, new HilosModerationKindsTable());
        $this->register(HilosModerationRulesTable::TABLE, new HilosModerationRulesTable());
    }
}

/** Closes just the text model, leaving the image model independently visible. */
final class ModerationTablesTextHiddenCatalog implements CatalogProviderInterface
{
    /** @return array<string, array<string, mixed>> One closed input profile */
    public static function getCatalog(): array
    {
        return array_replace_recursive(ModerationTablesSettingsCatalog::getCatalog(), [
            ModerationSettingsCatalog::TEXT_MODEL => [SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => false],
        ]);
    }
}

/** Closes just the image model, leaving the text model independently visible. */
final class ModerationTablesImageHiddenCatalog implements CatalogProviderInterface
{
    /** @return array<string, array<string, mixed>> The opposite closed input profile */
    public static function getCatalog(): array
    {
        return array_replace_recursive(ModerationTablesSettingsCatalog::getCatalog(), [
            ModerationSettingsCatalog::IMAGE_MODEL => [SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => false],
        ]);
    }
}
