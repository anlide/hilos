<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Backup\Anonymization\AnonymizationCoverageValidator;
use Hilos\Backup\Anonymization\LiveSchemaReader;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Actions\Collection\StatusMaintenancePeriodsActions;
use Hilos\Database\Actions\Collection\StatusWorkComponentsActions;
use Hilos\Database\Actions\Collection\StatusWorksActions;
use Hilos\Database\Actions\Item\StatusMaintenancePeriodActions;
use Hilos\Database\Actions\Item\StatusWorkActions;
use Hilos\Database\Actions\Item\StatusWorkComponentActions;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Entity\Item\StatusMaintenancePeriod as EntityStatusMaintenancePeriod;
use Hilos\Database\Entity\Item\StatusWork as EntityStatusWork;
use Hilos\Database\Entity\Item\StatusWorkComponent as EntityStatusWorkComponent;
use Hilos\Database\Object\Collection\StatusMaintenancePeriods as ObjectStatusMaintenancePeriods;
use Hilos\Database\Object\Collection\StatusWorkComponents as ObjectStatusWorkComponents;
use Hilos\Database\Object\Collection\StatusWorks as ObjectStatusWorks;
use Hilos\Database\Object\Objects;
use Hilos\Database\Schema\EntitySchemaAudit;
use Hilos\Database\Schema\Schema;
use Hilos\Database\View\Collection\StatusMaintenancePeriods;
use Hilos\Database\View\Collection\StatusWorkComponents;
use Hilos\Database\View\Collection\StatusWorks;
use Hilos\Hilos;
use Hilos\Status\StatusAgent;
use Hilos\Testing\BreaksOrmState;
use Hilos\Testing\IntegrationTest;
use ReflectionProperty;

/** Real ORM writes, independent persisted assertions and bounded storage reads; no owner lifecycle or mail transport. */
#[IntegrationTest]
final class StatusWorksStorageIntegrationTest extends StatusWorksStorageIntegrationTestCase
{
    /** Audits the real stubs and the mounted verdict used by anonymized restores. */
    public function testThreeProductionChainsMatchSchemaSetsAndCompleteNonpersonalVerdicts(): void
    {
        self::assertSame([], EntitySchemaAudit::audit([
            EntityStatusWork::class, EntityStatusWorkComponent::class, EntityStatusMaintenancePeriod::class,
        ]));
        self::assertSame(Entity::SET_STANDALONE, EntityStatusWork::_setVia);
        self::assertTrue(EntityStatusWork::_setRoot);
        self::assertSame(EntityStatusWorkComponent::work_id, EntityStatusWorkComponent::_setVia);
        self::assertFalse(EntityStatusWorkComponent::_setRoot);
        self::assertSame(Entity::SET_STANDALONE, EntityStatusMaintenancePeriod::_setVia);
        self::assertFalse(EntityStatusMaintenancePeriod::_setRoot);
        $schema = LiveSchemaReader::read(0);
        $tables = [];
        foreach ([EntityStatusWork::class, EntityStatusWorkComponent::class, EntityStatusMaintenancePeriod::class] as $entity) {
            self::assertSame([], $entity::_pii);
            self::assertSame($entity::_columns, $entity::_piiNotPersonal);
            self::assertFalse($entity::_journaled);
            $tables[$entity::_table] = $schema[$entity::_table];
        }
        AnonymizationCoverageValidator::validateLiveSchema(PiiRegistry::collect(), [0 => $tables]);
        self::assertInstanceOf(StatusWorksActions::class, Hilos::$db->statusWorks->actions);
        self::assertInstanceOf(StatusWorkComponentsActions::class, Hilos::$db->statusWorkComponents->actions);
        self::assertInstanceOf(StatusMaintenancePeriodsActions::class, Hilos::$db->statusMaintenancePeriods->actions);
    }

    /** Checks persisted values after edits, no-ops, component replacement and queue handoffs. */
    public function testWorkIdentityVersionNoOpAndMailHandoffAreIndependent(): void
    {
        $work = Hilos::$db->statusWorks->actions->create('Planned work', null, 10000, 20000, 1000, ['web', 'api', 'api']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $work->publicId);
        self::assertSame($work->id, Hilos::$db->statusWorks->findByPublicId($work->publicId)->id);
        self::assertNull(Hilos::$db->statusWorks->findByPublicId(str_repeat('f', 32)));
        self::assertInstanceOf(StatusWorkActions::class, $work->actions);
        self::assertCount(2, $work->components);
        $before = $this->storedWork($work->id);
        $componentIds = $this->storedComponents($work->id);
        $work->actions->change('Planned work', null, 10000, 20000, 2000, ['api', 'web']);
        self::assertSame($before, $this->storedWork($work->id));
        self::assertSame($componentIds, $this->storedComponents($work->id));
        $work->actions->change('Changed work', 'Public details', 11000, 21000, 3000, ['api', 'database']);
        $row = $this->storedWork($work->id);
        self::assertSame($before['public_id'], $row['public_id']);
        self::assertSame('Changed work', $row['title']);
        self::assertSame(2, (int)$row['version']);
        self::assertSame(3000, (int)$row['updated_at']);
        self::assertSame(['api', 'database'], array_column($this->storedComponents($work->id), 'component_key'));
        self::assertSame($componentIds[0]['id'], $this->storedComponents($work->id)[0]['id']);
        $work->actions->markMailQueued(4000);
        $work->actions->markMailQueued(3500);
        $row = $this->storedWork($work->id);
        self::assertSame(4000, (int)$row['last_mail_queued_at']);
        self::assertSame(3000, (int)$row['updated_at']);
        self::assertSame(2, (int)$row['version']);
        $work->actions->cancel(5000);
        $work->actions->cancel(6000);
        self::assertSame(5000, (int)$this->storedWork($work->id)['canceled_at']);
        self::assertSame(3, (int)$this->storedWork($work->id)['version']);
        self::assertNull(Hilos::$db->statusWorks->matchingAnnouncement(12000));
    }

    /** Exercises both ends of half-open windows and terminal work states against real SQL. */
    public function testIntervalBoundariesIgnoreComponentsAndAllowAdjacentWindows(): void
    {
        $first = Hilos::$db->statusWorks->actions->create('First', null, 10000, 20000, 1000, ['api']);
        $next = Hilos::$db->statusWorks->actions->create('Adjacent', null, 20000, 30000, 1000, ['web']);
        self::assertNull(Hilos::$db->statusWorks->conflictingWindow(0, 10000));
        self::assertNull(Hilos::$db->statusWorks->conflictingWindow(30000, 40000));
        self::assertSame($first->id, Hilos::$db->statusWorks->conflictingWindow(19999, 20001)->id);
        self::assertSame($next->id, Hilos::$db->statusWorks->conflictingWindow(20000, 30000, $first->id)->id);
        self::assertNull(Hilos::$db->statusWorks->conflictingWindow(10000, 20000, $first->id));
        self::assertNull(Hilos::$db->statusWorks->matchingAnnouncement(9999));
        self::assertSame($first->id, Hilos::$db->statusWorks->matchingAnnouncement(10000)->id);
        self::assertSame($next->id, Hilos::$db->statusWorks->matchingAnnouncement(20000)->id);
        self::assertNull(Hilos::$db->statusWorks->matchingAnnouncement(30000));
        $first->actions->consume(12000);
        $first->actions->consume(13000);
        self::assertSame(2, (int)$this->storedWork($first->id)['version']);
        self::assertSame(12000, (int)$this->storedWork($first->id)['consumed_at']);
        self::assertNull(Hilos::$db->statusWorks->matchingAnnouncement(15000));
        self::assertSame($first->id, Hilos::$db->statusWorks->conflictingWindow(15000, 16000)->id);
        $next->actions->expire(30000);
        $next->actions->expire(31000);
        self::assertSame(2, (int)$this->storedWork($next->id)['version']);
        self::assertSame(30000, (int)$this->storedWork($next->id)['expired_at']);
    }

    /** Rolls back a joined transaction across all three storage chains and their held objects. */
    public function testRollbackRestoresWorkComponentsObservationAndVersionTogether(): void
    {
        $work = Hilos::$db->statusWorks->actions->create('Before', null, 10000, 20000, 1000, ['api']);
        $before = $this->storedWork($work->id);
        $components = $this->storedComponents($work->id);
        Database::transactionStart();
        try {
            $work->actions->change('After', 'Changed', 11000, 21000, 2000, ['web']);
            $period = Hilos::$db->statusMaintenancePeriods->actions->create($work->id, $work->publicId, true, 'Observed', ['web'], 12000);
            self::assertSame('After', $this->storedWork($work->id)['title']);
            self::assertCount(1, Database::sql('SELECT * FROM `hilos_status_maintenance_period` WHERE `id` = ?', [$period->id])->rows());
        } finally {
            Database::transactionRollback();
        }
        self::assertSame($before, $this->storedWork($work->id));
        self::assertSame($components, $this->storedComponents($work->id));
        self::assertSame('Before', $work->title);
        self::assertSame(1, $work->version);
        self::assertSame('api', $work->components->first()->componentKey);
        self::assertNull(Hilos::$db->statusMaintenancePeriods->findByPublicId($period->publicId));
    }

    /** Traverses a multi-portion audience without retaining its rows in the cold source store. */
    public function testBoundedUpcomingAndSummaryLeaveTheColdSourceEmpty(): void
    {
        self::assertSame(0, Hilos::$db->statusWorks->summary(1000)->upcoming);
        self::assertNull(Hilos::$db->statusWorks->summary(1000)->nextStartsAt);
        for ($index = 0; $index < 40; $index++) {
            Hilos::$db->statusWorks->actions->create('Work ' . $index, null, 10000 + $index * 2000, 11000 + $index * 2000, 1000);
        }
        $canceled = Hilos::$db->statusWorks->actions->create('Canceled', null, 100000, 101000, 1000);
        self::assertCount(0, $canceled->components);
        $canceled->actions->cancel(2000);
        $consumed = Hilos::$db->statusWorks->actions->create('Consumed', null, 102000, 103000, 1000);
        $consumed->actions->consume(102000);
        $expired = Hilos::$db->statusWorks->actions->create('Expired', null, 104000, 105000, 1000);
        $expired->actions->expire(105000);
        $cold = new StatusWorksStorageDbContext();
        $cold->configure();
        self::assertSame(40, $cold->statusWorks->summary(9999)->upcoming);
        self::assertSame(10000, $cold->statusWorks->summary(9999)->nextStartsAt);
        self::assertSame(40, $cold->statusWorks->summary(10000)->upcoming);
        self::assertSame(40, $cold->statusWorks->summary(10500)->upcoming);
        self::assertSame(39, $cold->statusWorks->summary(11000)->upcoming);
        foreach ([9999, 10000, 10500] as $now) {
            self::assertSame(10000, $cold->statusWorks->upcomingPortion($now, null, 1)->first()->startsAt);
        }
        self::assertSame(12000, $cold->statusWorks->upcomingPortion(11000, null, 1)->first()->startsAt);
        $first = $cold->statusWorks->upcomingPortion(9999, null, 1000);
        self::assertCount(32, $first);
        $second = $cold->statusWorks->upcomingPortion(9999, $first->last()->id, 32);
        self::assertCount(8, $second);
        self::assertCount(0, $cold->statusWorks->upcomingPortion(9999, $second->last()->id, 32));
        self::assertCount(0, $cold->statusWorks->upcomingPortion(9999, null, 0));
        self::assertCount(0, $cold->statusWorks->upcomingPortion(9999, null, -1));
        self::assertFalse($cold->statusWorks->getObjectCollection()->isAllLoaded());
        self::assertSame([], (new ReflectionProperty(Objects::class, 'objects'))->getValue($cold->statusWorks->getObjectCollection()));
        self::assertCount(32, $cold->statusWorks->queryPage(new TableQueryDTO())[TableConstants::RESULT_KEY_ROWS]);
    }

    /** Compares the window, single-row membership and counts with the same search and component choice. */
    public function testWorkWindowsSearchMembershipAndFacetCountsUseOnePendingPredicate(): void
    {
        $api = Hilos::$db->statusWorks->actions->create('Needle in title', null, 10000, 20000, 1000, ['api']);
        $web = Hilos::$db->statusWorks->actions->create('Other', 'needle in description', 30000, 40000, 1000, ['web']);
        $both = Hilos::$db->statusWorks->actions->create('Needle in title', null, 50000, 60000, 1000, ['api', 'web']);
        $ended = Hilos::$db->statusWorks->actions->create('Needle canceled', null, 70000, 80000, 1000, ['api']);
        $ended->actions->cancel(2000);
        $consumed = Hilos::$db->statusWorks->actions->create('Needle consumed', null, 90000, 100000, 1000, ['api']);
        $consumed->actions->consume(90000);
        $expired = Hilos::$db->statusWorks->actions->create('Needle expired', null, 110000, 120000, 1000, ['api']);
        $expired->actions->expire(120000);
        $query = new TableQueryDTO(search: 'needle', limit: 32, filter: ['component_key' => 'api'],
            searchableFields: ['title' => 'title', 'description' => 'description']);
        $page = Hilos::$db->statusWorks->queryPage($query);
        self::assertSame(2, $page[TableConstants::RESULT_KEY_TOTAL_COUNT]);
        self::assertCount(2, $page[TableConstants::RESULT_KEY_ROWS]);
        self::assertTrue(Hilos::$db->statusWorks->containsRow($query, $api->id));
        self::assertTrue(Hilos::$db->statusWorks->containsRow($query, $both->id));
        foreach ([$web->id, $ended->id, $consumed->id, $expired->id] as $id) {
            self::assertFalse(Hilos::$db->statusWorks->containsRow($query, $id));
        }
        $facetQuery = $query->withoutFilter('component_key')->withLimit(1);
        self::assertSame(3, Hilos::$db->statusWorks->queryPage($facetQuery)[TableConstants::RESULT_KEY_TOTAL_COUNT]);
        self::assertSame(2, Hilos::$db->statusWorks->queryPage($facetQuery->withFilter('component_key', 'web'))[
            TableConstants::RESULT_KEY_TOTAL_COUNT]);
        self::assertSame(0, Hilos::$db->statusWorks->queryPage($facetQuery->withFilter('component_key', "api' OR 1=1 --"))[
            TableConstants::RESULT_KEY_TOTAL_COUNT]);
        foreach ([TableConstants::ORDER_ASC, TableConstants::ORDER_DESC] as $direction) {
            foreach (['starts_at' => 'startsAt', 'ends_at' => 'endsAt', 'title' => 'title'] as $column => $field) {
                $sorted = Hilos::$db->statusWorks->queryPage(new TableQueryDTO(
                    sort: TableSortOrderDTO::of(new TableSortDTO($column, $direction)), limit: 32,
                ))[TableConstants::RESULT_KEY_ROWS];
                self::assertCount(3, $sorted);
                for ($index = 1; $index < count($sorted); $index++) {
                    $left = [$sorted[$index - 1][$field], $sorted[$index - 1]['id']];
                    $right = [$sorted[$index][$field], $sorted[$index]['id']];
                    self::assertTrue($direction === TableConstants::ORDER_ASC ? $left < $right : $left > $right);
                }
            }
        }
    }

    /** Replays one public observation identity and refuses to turn a missed transition into an exact end. */
    public function testIdempotentImportKeepsUnknownRelationAndInexactCompletionDistinct(): void
    {
        $id = str_repeat('a', 32);
        $period = Hilos::$db->statusMaintenancePeriods->actions->import($id, null, null, false, 'Unknown', ['web', 'api', 'api'],
            1000, 2000, null, null);
        self::assertInstanceOf(StatusMaintenancePeriodActions::class, $period->actions);
        self::assertSame(['api', 'web'], $period->componentKeys);
        self::assertFalse($period->announcementKnown);
        self::assertNull($period->work);
        $same = Hilos::$db->statusMaintenancePeriods->actions->import($id, null, null, false, 'Unknown', ['api', 'web'],
            1000, 3000, null, null);
        self::assertSame($period->id, $same->id);
        $period->actions->reconcile(2000, null, null);
        self::assertSame(3000, (int)$this->storedPeriod($period->id)['last_observed_at']);
        $period->actions->reconcile(3000, null, 10000);
        $period->actions->reconcile(3000, null, null);
        $row = $this->storedPeriod($period->id);
        self::assertNull($row['ended_at']);
        self::assertSame(10000, (int)$row['completion_observed_at']);
        self::assertSame(3000, (int)$row['last_observed_at']);
        try {
            $period->actions->reconcile(3000, 10000, 10000);
            self::fail('A gap must not become an exact end');
        } catch (ValidationException) {
            self::assertSame($row, $this->storedPeriod($period->id));
        }
        self::assertCount(0, Hilos::$db->statusMaintenancePeriods->intersecting(10001, null, 32));
        self::assertCount(1, Hilos::$db->statusMaintenancePeriods->intersecting(10000, null, 32));
        $knownWithoutWork = Hilos::$db->statusMaintenancePeriods->actions->create(null, null, true, 'Unannounced', [], 12000);
        self::assertTrue($knownWithoutWork->announcementKnown);
        self::assertNull($knownWithoutWork->workPublicId);
        self::assertSame([], $knownWithoutWork->componentKeys);
    }

    /** Separates continuous completion from last occupied facts and traverses detached history portions. */
    public function testContinuousCompletionAndBoundedHistoryRetainTheLastOccupiedFact(): void
    {
        $period = Hilos::$db->statusMaintenancePeriods->actions->create(null, null, true, 'Continuous', [], 1000);
        $period->actions->reconcile(2000, 3000, 3000);
        $row = $this->storedPeriod($period->id);
        self::assertSame(3000, (int)$row['ended_at']);
        self::assertSame(3000, (int)$row['completion_observed_at']);
        self::assertSame(2000, (int)$row['last_observed_at']);
        self::assertCount(1, Hilos::$db->statusMaintenancePeriods->intersecting(2500, null, 32));
        self::assertCount(0, Hilos::$db->statusMaintenancePeriods->intersecting(3001, null, 32));
        for ($index = 0; $index < 40; $index++) {
            Hilos::$db->statusMaintenancePeriods->actions->create(null, null, true, 'Period ' . $index, [], 10000 + $index);
        }
        $cold = new StatusWorksStorageDbContext();
        $cold->configure();
        $first = $cold->statusMaintenancePeriods->intersecting(10000, null, 999);
        self::assertCount(32, $first);
        $second = $cold->statusMaintenancePeriods->intersecting(10000, $first->last()->id, 32);
        self::assertCount(8, $second);
        self::assertCount(0, $cold->statusMaintenancePeriods->intersecting(10000, $second->last()->id, 32));
        self::assertCount(0, $cold->statusMaintenancePeriods->intersecting(0, null, 0));
        self::assertSame([], (new ReflectionProperty(Objects::class, 'objects'))->getValue(
            $cold->statusMaintenancePeriods->getObjectCollection()));
        $this->expectException(ValidationException::class);
        $period->actions->reconcile(2500, 4000, 4000);
    }

    /** Simulates deletion and an integer id reused after restore without adopting the replacement work. */
    #[BreaksOrmState]
    public function testHistoricalBridgeRefusesReusedIntegerIdentityAndDetachedSnapshotSurvivesDelete(): void
    {
        $work = Hilos::$db->statusWorks->actions->create('Same title', null, 10000, 20000, 1000, ['api']);
        $period = Hilos::$db->statusMaintenancePeriods->actions->create($work->id, $work->publicId, true, 'Same title', ['api'], 11000);
        self::assertSame($work->id, $period->work->id);
        Database::sql('DELETE FROM `hilos_status_work` WHERE `id` = ?', [$work->id]);
        self::assertSame([], $this->storedComponents($work->id));
        $row = $this->storedPeriod($period->id);
        self::assertNull($row['work_id']);
        self::assertSame($work->publicId, $row['work_public_id']);
        self::assertSame('["api"]', $row['component_keys_json']);
        Database::sql('INSERT INTO `hilos_status_work` (`id`, `public_id`, `title`, `starts_at`, `ends_at`, `created_at`, `updated_at`, `version`) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$work->id, str_repeat('b', 32), 'Same title', 10000, 20000, 1000, 1000, 1]);
        Database::sql('UPDATE `hilos_status_maintenance_period` SET `work_id` = ? WHERE `id` = ?', [$work->id, $period->id]);
        $previous = Hilos::$db;
        Hilos::$db = new StatusWorksStorageDbContext();
        Hilos::$db->configure();
        try {
            self::assertNull(Hilos::$db->statusMaintenancePeriods[$period->id]->work);
            self::assertSame($work->publicId, Hilos::$db->statusMaintenancePeriods[$period->id]->workPublicId);
            self::assertSame(['api'], Hilos::$db->statusMaintenancePeriods[$period->id]->componentKeys);
        } finally {
            Hilos::$db = $previous;
        }
    }

    /** Refuses a known-row mutation from an unrelated agent execution frame. */
    public function testAllKnownItemWritesRespectTheOwnerOperationGrant(): void
    {
        $work = Hilos::$db->statusWorks->actions->create('Owned', null, 10000, 20000, 1000, ['api']);
        self::assertInstanceOf(StatusWorkComponentActions::class, $work->components->first()->actions);
        $token = ExecutionContext::push(new ExecutionFrame(agentId: 'unrelated-reader'));
        try {
            $this->expectException(WriteNotAllowedException::class);
            $work->actions->cancel(2000);
        } finally {
            ExecutionContext::pop($token);
        }
    }

    /** Checks invalid input against independent empty-table reads. */
    public function testInvalidWindowAndPublicTextAreRefusedBeforeAnyInsert(): void
    {
        foreach ([['', null, 10000, 20000], ['Title', null, 10000, 10000], ['Title', str_repeat('x', 2001), 10000, 20000]] as $input) {
            try {
                Hilos::$db->statusWorks->actions->create($input[0], $input[1], $input[2], $input[3], 1000);
                self::fail('Invalid content must be refused');
            } catch (ValidationException) {
                self::assertSame([], Database::sql('SELECT * FROM `hilos_status_work`')->rows());
            }
        }
    }

    /**
     * @param int $id Work primary id
     * @return array<string, mixed> Independent persisted work
     */
    private function storedWork(int $id): array
    {
        return Database::sql('SELECT * FROM `hilos_status_work` WHERE `id` = ?', [$id])->first()->first();
    }

    /**
     * @param int $id Period primary id
     * @return array<string, mixed> Independent persisted period
     */
    private function storedPeriod(int $id): array
    {
        return Database::sql('SELECT * FROM `hilos_status_maintenance_period` WHERE `id` = ?', [$id])->first()->first();
    }

    /**
     * @param int $workId Work primary id
     * @return list<array<string, mixed>> Independent selected rows
     */
    private function storedComponents(int $workId): array
    {
        return Database::sql('SELECT * FROM `hilos_status_work_component` WHERE `work_id` = ? ORDER BY `component_key`', [$workId])->rows();
    }
}

/** Owns schema outside the rollback root and restores the existing framework environment. */
abstract class StatusWorksStorageIntegrationTestCase extends FrameworkRollbackIntegrationTestCase
{
    private const string OWNER = 'status-works-storage';
    private const array TABLES = ['hilos_status_work', 'hilos_status_work_component', 'hilos_status_maintenance_period'];

    private ?int $ownerToken = null;

    /** Raises the current stubs and mounts only the real production storage chains. */
    protected function prepareIntegrationEnvironment(): void
    {
        parent::prepareIntegrationEnvironment();
        foreach (self::TABLES as $table) {
            Database::sqlRun(file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . '.sql'));
        }
        Schema::reset();
        Schema::initialize();
        OwnershipDeclaration::claimDb(StatusAgent::class, self::OWNER);
        $this->ownerToken = ExecutionContext::push(new ExecutionFrame(agentId: self::OWNER));
        Hilos::$db = new StatusWorksStorageDbContext();
        Hilos::$db->configure();
    }

    /** Drops child tables first after rollback, then releases the test's owner and reader interest. */
    protected function releaseIntegrationEnvironment(): void
    {
        try {
            foreach (array_reverse(self::TABLES) as $table) {
                Database::sqlRun('DROP TABLE IF EXISTS `' . $table . '`');
            }
        } finally {
            TruthSourceRegistry::unregisterAgent(self::OWNER);
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(self::OWNER));
            if ($this->ownerToken !== null) {
                ExecutionContext::pop($this->ownerToken);
                $this->ownerToken = null;
            }
            parent::releaseIntegrationEnvironment();
        }
    }
}

/** Binds every production Entity/Object/View/action link without enabling unrelated feature surfaces. */
final class StatusWorksStorageDbContext extends HilosDbContext
{
    /** Mounts the three lazy production chains and both action levels. */
    public function configure(): void
    {
        $this->_objectCollections[self::statusWorks] = ObjectStatusWorks::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::statusWorks, StatusWorks::class, StatusWorksActions::class, StatusWorkActions::class);
        $this->_objectCollections[self::statusWorkComponents] = ObjectStatusWorkComponents::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::statusWorkComponents, StatusWorkComponents::class,
            StatusWorkComponentsActions::class, StatusWorkComponentActions::class);
        $this->_objectCollections[self::statusMaintenancePeriods] = ObjectStatusMaintenancePeriods::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::statusMaintenancePeriods, StatusMaintenancePeriods::class,
            StatusMaintenancePeriodsActions::class, StatusMaintenancePeriodActions::class);
    }
}
