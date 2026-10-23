<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\ExternalWriterException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Core\TruthSource\TruthSourceOwner;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Actions\Collection\DbActions;
use Hilos\Database\Actions\Item\DbActions as ItemDbActions;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Database\View\Item\DbItem;
use Hilos\Hilos;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The hole HIL-716 closed: the right to write was asked inside a switch over the lazy-loading
 * strategy, so only a collection loaded whole was ever guarded. Every hot table is lazy, and
 * every one of them was written by anybody in silence.
 *
 * These cases stand on all four strategies and stop at the door, which is the last point before
 * a write reaches the database: refusing there costs no connection, so the cases need none. The
 * three LAZY strategies are the branches that used to be a bare `break`. LAZY_STRATEGY_NONE was
 * left out until HIL-1144, because its branch loaded the whole table at the door and so needed a
 * database; now the door asks the right and loads nothing. The explicit fixture chains have no
 * configured database, so an accidental load at a door still fails the case.
 */
final class DbWriteGuardLazyCollectionsTest extends TestCase
{
    private const string COLLECTION = 'unit_guard_lazy';
    private const string AGENT = 'unit_guard_agent';
    private const string OWN_SET = '42';
    private const string FOREIGN_SET = '7';
    private const string EXTERNAL = 'unit_guard_external';

    private ?HilosDbContext $previousDb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = Hilos::$db;
        Hilos::$db = null;
    }

    protected function tearDown(): void
    {
        ExecutionContext::setCurrentAgentId(null);
        TruthSourceRegistry::unregisterAgent(self::AGENT);
        RtTruthSourceRegistry::unregisterAgent(self::AGENT);
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(self::AGENT));
        SourceInterestRegistry::readsWhatItMounts();
        Hilos::$db = $this->previousDb;

        parent::tearDown();
    }

    /**
     * @return list<array{int}> One case per strategy, by its own name
     */
    public static function lazyStrategies(): array
    {
        return [
            'none' => [Objects::LAZY_STRATEGY_NONE],
            'key' => [Objects::LAZY_STRATEGY_KEY],
            'batch' => [Objects::LAZY_STRATEGY_BATCH],
            'full on access' => [Objects::LAZY_STRATEGY_FULL_ON_ACCESS],
        ];
    }

    /**
     * @param int $strategy Lazy-loading strategy the collection is registered with
     */
    #[DataProvider('lazyStrategies')]
    public function testLazyCollectionRefusesAWriteNobodyClaimed(int $strategy): void
    {
        $actions = $this->actionsFor(GuardedObjects::class, $strategy);
        ExecutionContext::setCurrentAgentId(self::AGENT);

        $this->expectException(WriteNotAllowedException::class);
        $actions->writePublic();
    }

    /**
     * @param int $strategy Lazy-loading strategy the collection is registered with
     */
    #[DataProvider('lazyStrategies')]
    public function testLazyCollectionAllowsTheAgentThatClaimedIt(int $strategy): void
    {
        $actions = $this->actionsFor(GuardedObjects::class, $strategy);
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::all(), self::AGENT);
        ExecutionContext::setCurrentAgentId(self::AGENT);

        $actions->writePublic();

        $this->assertTrue(TruthSourceRegistry::hasTruthSource(self::COLLECTION));
    }

    public function testCreatingIsJudgedApartFromEditing(): void
    {
        $actions = $this->actionsFor(GuardedObjects::class, Objects::LAZY_STRATEGY_KEY);
        TruthSourceRegistry::registerCreate(self::COLLECTION, self::AGENT);
        ExecutionContext::setCurrentAgentId(self::AGENT);

        // A grant of zero width may mint a row and owns none, so editing is still refused.
        $actions->createPublic();

        $this->expectException(WriteNotAllowedException::class);
        $actions->writePublic();
    }

    public function testEditingRightDoesNotByItselfAllowCreating(): void
    {
        $actions = $this->actionsFor(GuardedObjects::class, Objects::LAZY_STRATEGY_KEY);
        TruthSourceRegistry::register(
            self::COLLECTION,
            TruthSourceKeys::listed('1'),
            self::AGENT,
            TruthSourceOperations::of(TruthSourceOperation::Update),
        );
        ExecutionContext::setCurrentAgentId(self::AGENT);

        $this->expectException(CreateNotAllowedException::class);
        $actions->createPublic();
    }

    public function testDeleteAllIsJudgedOverTheWholeCollection(): void
    {
        $actions = $this->actionsFor(GuardedObjects::class, Objects::LAZY_STRATEGY_KEY);
        // Owning one row is not owning the table, and a truncate names no row at all.
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::listed('1'), self::AGENT);
        ExecutionContext::setCurrentAgentId(self::AGENT);

        $this->expectException(WriteNotAllowedException::class);
        $actions->deleteAllPublic();
    }

    public function testDeleteAllAsksForRemoveBeforeReachingTheTable(): void
    {
        $actions = $this->actionsFor(GuardedObjects::class, Objects::LAZY_STRATEGY_KEY);
        // The whole table is claimed, but without the right to drop rows: the truncate stops at the door.
        TruthSourceRegistry::register(
            self::COLLECTION,
            TruthSourceKeys::all(),
            self::AGENT,
            TruthSourceOperations::of(TruthSourceOperation::Add, TruthSourceOperation::Update),
        );
        ExecutionContext::setCurrentAgentId(self::AGENT);

        $this->expectException(WriteNotAllowedException::class);
        $this->expectExceptionMessage("may not remove rows across the whole table");
        $actions->deleteAllPublic();
    }

    public function testCollectionWriteDoorAsksForTheOperationItWasNamed(): void
    {
        $actions = $this->actionsFor(GuardedObjects::class, Objects::LAZY_STRATEGY_KEY);
        TruthSourceRegistry::register(
            self::COLLECTION,
            TruthSourceKeys::all(),
            self::AGENT,
            TruthSourceOperations::of(TruthSourceOperation::Add, TruthSourceOperation::Remove),
        );
        ExecutionContext::setCurrentAgentId(self::AGENT);

        $actions->removePublic();

        $this->expectException(WriteNotAllowedException::class);
        $this->expectExceptionMessage("may not update");
        $actions->writePublic();
    }

    /**
     * @param int $strategy Lazy-loading strategy the collection is registered with
     */
    #[DataProvider('lazyStrategies')]
    public function testSetDoorLetsTheSetOwnerWriteItsSetAndRefusesAnother(int $strategy): void
    {
        $actions = $this->actionsFor(GuardedObjects::class, $strategy);
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::set(self::OWN_SET), self::AGENT);
        ExecutionContext::setCurrentAgentId(self::AGENT);

        $actions->writeSetPublic(self::OWN_SET);
        $actions->removeSetPublic(self::OWN_SET);

        $this->expectException(WriteNotAllowedException::class);
        $this->expectExceptionMessage("set '7': it holds set '42'.");
        $actions->removeSetPublic(self::FOREIGN_SET);
    }

    public function testSetDoorAsksForTheOperationItWasNamed(): void
    {
        $actions = $this->actionsFor(GuardedObjects::class, Objects::LAZY_STRATEGY_KEY);
        TruthSourceRegistry::register(
            self::COLLECTION,
            TruthSourceKeys::set(self::OWN_SET),
            self::AGENT,
            TruthSourceOperations::of(TruthSourceOperation::Remove),
        );
        ExecutionContext::setCurrentAgentId(self::AGENT);

        $actions->removeSetPublic(self::OWN_SET);

        $this->expectException(WriteNotAllowedException::class);
        $this->expectExceptionMessage("may not update rows of set '42'");
        $actions->writeSetPublic(self::OWN_SET);
    }

    /**
     * @param int $strategy Lazy-loading strategy the collection is registered with
     */
    #[DataProvider('lazyStrategies')]
    public function testCreateInSetDoorLetsTheSetOwnerCreateInItsSetAndRefusesAnother(int $strategy): void
    {
        $actions = $this->actionsFor(GuardedObjects::class, $strategy);
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::set(self::OWN_SET), self::AGENT);
        ExecutionContext::setCurrentAgentId(self::AGENT);

        $actions->createInSetPublic(self::OWN_SET);

        $this->expectException(CreateNotAllowedException::class);
        $this->expectExceptionMessage("it holds set '42' with operations [add, update, remove], and the new row's set keys are [7].");
        $actions->createInSetPublic(self::FOREIGN_SET);
    }

    /**
     * The door that names no set hands the new row to nobody's set, which is not the set owner's to create in.
     */
    public function testCreateDoorRefusesTheSetOwner(): void
    {
        $actions = $this->actionsFor(GuardedObjects::class, Objects::LAZY_STRATEGY_KEY);
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::set(self::OWN_SET), self::AGENT);
        ExecutionContext::setCurrentAgentId(self::AGENT);

        $this->expectException(CreateNotAllowedException::class);
        $this->expectExceptionMessage("and the new row's set keys are [].");
        $actions->createPublic();
    }

    public function testTheWholeTablePassesBothCreateDoors(): void
    {
        $actions = $this->actionsFor(GuardedObjects::class, Objects::LAZY_STRATEGY_KEY);
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::all(), self::AGENT);
        ExecutionContext::setCurrentAgentId(self::AGENT);

        $actions->createPublic();
        $actions->createInSetPublic(self::OWN_SET);
        $actions->createInSetPublic(self::FOREIGN_SET);

        $this->assertTrue(TruthSourceRegistry::hasCreateSource(self::COLLECTION));
    }

    public function testManualCollectionWithNoKeyIsNotJudged(): void
    {
        $actions = $this->actionsFor(UnkeyedObjects::class, Objects::LAZY_STRATEGY_KEY);
        ExecutionContext::setCurrentAgentId(self::AGENT);

        $actions->writePublic();
        $actions->createPublic();
        $actions->createInSetPublic(self::OWN_SET);
        $actions->writeSetPublic(self::OWN_SET);

        $this->assertFalse(TruthSourceRegistry::hasTruthSource(''));
    }

    /** @return iterable<string, array{string, int, string, bool}> Write doors, strategies, stale grants and execution modes */
    public static function externalWriteDoors(): iterable
    {
        foreach (['registry', 'guard'] as $door) {
            foreach (['create', 'collection update', 'collection remove', 'item update', 'item remove', 'set update', 'set remove'] as $path) {
                foreach (self::lazyStrategies() as $strategyName => [$strategy]) {
                    foreach (['none', 'whole', 'set'] as $grant) {
                        foreach ([false, true] as $asAgent) {
                            yield "{$door} {$path}, {$strategyName}, {$grant}, " . ($asAgent ? 'agent' : 'no agent') => [
                                "{$door} {$path}", $strategy, $grant, $asAgent,
                            ];
                        }
                    }
                }
            }
        }
    }

    /**
     * @param string $path Public registry or guard door
     * @param int $strategy Mounted loading strategy
     * @param string $grant Erroneous grant registered before the external chain is mounted
     * @param bool $asAgent Whether the current execution names an agent
     */
    #[DataProvider('externalWriteDoors')]
    public function testExternalWriterRefusesEveryDoorBeforeGrantOrSetLookup(
        string $path,
        int $strategy,
        string $grant,
        bool $asAgent,
    ): void {
        if ($grant !== 'none') {
            TruthSourceRegistry::register(
                self::EXTERNAL,
                $grant === 'whole' ? TruthSourceKeys::all() : TruthSourceKeys::set(self::OWN_SET),
                self::AGENT,
            );
        }
        $context = new GuardedDbContext();
        $context->mountFixture($strategy, external: true);
        Hilos::$db = $context;
        SourceInterestRegistry::readsWhatIsDelivered();
        ExecutionContext::setCurrentAgentId($asAgent ? self::AGENT : null);
        $interests = SourceInterestRegistry::collections(SourceChange::KIND_DB);
        $sql = new ReflectionProperty(Database::class, 'lastSql')->getValue();
        $setKeys = static function (): array {
            self::fail('An external writer refusal must precede the set lookup');
        };

        try {
            match ($path) {
                'registry create' => TruthSourceRegistry::checkCanCreate(self::EXTERNAL, $setKeys),
                'registry collection update' => TruthSourceRegistry::checkCanWrite(self::EXTERNAL, TruthSourceOperation::Update),
                'registry collection remove' => TruthSourceRegistry::checkCanWrite(self::EXTERNAL, TruthSourceOperation::Remove),
                'registry item update' => TruthSourceRegistry::checkCanWriteItem(self::EXTERNAL, '1', $setKeys, TruthSourceOperation::Update),
                'registry item remove' => TruthSourceRegistry::checkCanWriteItem(self::EXTERNAL, '1', $setKeys, TruthSourceOperation::Remove),
                'registry set update' => TruthSourceRegistry::checkCanWriteSet(self::EXTERNAL, self::OWN_SET, $setKeys, TruthSourceOperation::Update),
                'registry set remove' => TruthSourceRegistry::checkCanWriteSet(self::EXTERNAL, self::OWN_SET, $setKeys, TruthSourceOperation::Remove),
                'guard create' => DbWriteGuard::guardCreate(self::EXTERNAL, $setKeys),
                'guard collection update' => DbWriteGuard::guardCollectionWrite(self::EXTERNAL, TruthSourceOperation::Update),
                'guard collection remove' => DbWriteGuard::guardCollectionWrite(self::EXTERNAL, TruthSourceOperation::Remove),
                'guard item update' => DbWriteGuard::guardItemWrite(self::EXTERNAL, '1', $setKeys, TruthSourceOperation::Update),
                'guard item remove' => DbWriteGuard::guardItemWrite(self::EXTERNAL, '1', $setKeys, TruthSourceOperation::Remove),
                'guard set update' => DbWriteGuard::guardSetWrite(self::EXTERNAL, self::OWN_SET, $setKeys, TruthSourceOperation::Update),
                'guard set remove' => DbWriteGuard::guardSetWrite(self::EXTERNAL, self::OWN_SET, $setKeys, TruthSourceOperation::Remove),
            };
            self::fail('Every application write to an external collection must be refused');
        } catch (ExternalWriterException $failure) {
            self::assertStringContainsString(self::EXTERNAL, $failure->getMessage());
            self::assertStringContainsString('external database writer', $failure->getMessage());
        } finally {
            self::assertSame($sql, new ReflectionProperty(Database::class, 'lastSql')->getValue());
            self::assertSame($interests, SourceInterestRegistry::collections(SourceChange::KIND_DB));
            self::assertFalse(SourceInterestRegistry::isDeclared(SourceChange::KIND_DB, self::EXTERNAL));
        }
    }

    /** @return iterable<string, array{bool, bool}> Registration forms and a stale grant carrying Add already */
    public static function externalClaims(): iterable
    {
        yield 'claim without grant' => [false, false];
        yield 'claim with grant' => [false, true];
        yield 'create without grant' => [true, false];
        yield 'create with grant' => [true, true];
    }

    /**
     * @param bool $createOnly Whether to register the create permission
     * @param bool $staleGrant Whether a grant predates the mount
     */
    #[DataProvider('externalClaims')]
    public function testExternalWriterCannotBeClaimed(bool $createOnly, bool $staleGrant): void
    {
        if ($staleGrant) {
            TruthSourceRegistry::register(self::EXTERNAL, TruthSourceKeys::all(), self::AGENT);
        }
        $before = new ReflectionProperty(TruthSourceRegistry::class, 'sources')->getValue();
        $context = new GuardedDbContext();
        $context->mountFixture(Objects::LAZY_STRATEGY_KEY, external: true);
        Hilos::$db = $context;
        SourceInterestRegistry::readsWhatIsDelivered();

        try {
            if ($createOnly) {
                TruthSourceRegistry::registerCreate(self::EXTERNAL, self::AGENT);
            } else {
                TruthSourceRegistry::register(
                    self::EXTERNAL,
                    TruthSourceKeys::listed('1'),
                    self::AGENT,
                    TruthSourceOperations::of(TruthSourceOperation::Update),
                );
            }
            self::fail('An application may not claim externally written rows');
        } catch (ExternalWriterException $failure) {
            self::assertStringContainsString(self::EXTERNAL, $failure->getMessage());
            self::assertStringContainsString('external database writer', $failure->getMessage());
            self::assertSame($before, new ReflectionProperty(TruthSourceRegistry::class, 'sources')->getValue());
            self::assertFalse(SourceInterestRegistry::isDeclared(SourceChange::KIND_DB, self::EXTERNAL));
        }
    }

    /** The shared registration contract keeps the DB metadata guard out of runtime claims. */
    public function testExternalDbClaimDoesNotRestrictRuntimeClaimOfTheSameKey(): void
    {
        $context = new GuardedDbContext();
        $context->mountFixture(Objects::LAZY_STRATEGY_KEY, external: true);
        Hilos::$db = $context;
        SourceInterestRegistry::readsWhatIsDelivered();
        $sql = new ReflectionProperty(Database::class, 'lastSql')->getValue();

        try {
            OwnershipDeclaration::claimDb(ExternalGuardedOwner::class, self::AGENT);
            self::fail('The mounted external DB collection must refuse its application claim');
        } catch (ExternalWriterException) {
            self::assertFalse(TruthSourceRegistry::hasTruthSource(self::EXTERNAL));
            self::assertFalse(SourceInterestRegistry::isDeclared(SourceChange::KIND_DB, self::EXTERNAL));
            self::assertFalse(SourceInterestRegistry::isReady(SourceChange::KIND_DB, self::EXTERNAL));
        }

        OwnershipDeclaration::claimRt(ExternalGuardedOwner::class, self::AGENT);
        self::assertTrue(RtTruthSourceRegistry::hasTruthSource(self::EXTERNAL));
        self::assertTrue(SourceInterestRegistry::isReady(SourceChange::KIND_RT, self::EXTERNAL));
        self::assertFalse(TruthSourceRegistry::hasTruthSource(self::EXTERNAL));
        self::assertFalse(SourceInterestRegistry::isDeclared(SourceChange::KIND_DB, self::EXTERNAL));
        self::assertSame($sql, new ReflectionProperty(Database::class, 'lastSql')->getValue());

        ExecutionContext::setCurrentAgentId(self::AGENT);
        RtTruthSourceRegistry::checkCanWriteState(self::EXTERNAL, '1', [], TruthSourceOperation::Add);
        RtTruthSourceRegistry::checkCanWriteState(self::EXTERNAL, '1', [], TruthSourceOperation::Remove);

        $this->expectException(RtTruthSourceWriteNotAllowedException::class);
        $this->expectExceptionMessage('may not update');
        RtTruthSourceRegistry::checkCanWriteState(self::EXTERNAL, '1', [], TruthSourceOperation::Update);
    }

    /** @return iterable<string, array{string, int}> Real action doors on every loading strategy */
    public static function externalActionDoors(): iterable
    {
        foreach ([
            'create', 'create in set', 'update', 'remove', 'update set', 'remove set', 'delete all',
            'item update', 'item remove', 'object create', 'object update', 'object remove',
        ] as $path) {
            foreach (self::lazyStrategies() as $strategyName => [$strategy]) {
                yield "{$path}, {$strategyName}" => [$path, $strategy];
            }
        }
    }

    /**
     * @param string $path Collection action door
     * @param int $strategy Loading strategy the action must not turn into a read
     */
    #[DataProvider('externalActionDoors')]
    public function testMountedExternalActionsRefuseBeforeSqlOrMembership(string $path, int $strategy): void
    {
        TruthSourceRegistry::register(self::EXTERNAL, TruthSourceKeys::all(), self::AGENT);
        $context = new GuardedDbContext();
        $context->mountFixture($strategy, external: true);
        Hilos::$db = $context;
        ExecutionContext::setCurrentAgentId(self::AGENT);
        $objects = $context->mountedObjectCollection(self::EXTERNAL);
        $view = $context->getDbItemCollection(self::EXTERNAL);
        $object = ExternalGuardedObject::fromEntity(ExternalGuardedEntity::fromRow(['id' => 1, 'owner_id' => 42]));
        $objects->seed($object);
        $item = new ExternalGuardedDbItem($object);
        $item->setCollection($view);
        $item->setActionsClass(ExternalGuardedItemActions::class);
        $before = serialize($objects);
        $sql = new ReflectionProperty(Database::class, 'lastSql')->getValue();

        try {
            match ($path) {
                'create' => $view->actions->createPublic(),
                'create in set' => $view->actions->createInSetPublic(self::OWN_SET),
                'update' => $view->actions->writePublic(),
                'remove' => $view->actions->removePublic(),
                'update set' => $view->actions->writeSetPublic(self::OWN_SET),
                'remove set' => $view->actions->removeSetPublic(self::OWN_SET),
                'delete all' => $view->actions->deleteAllPublic(),
                'item update' => $item->actions->writePublic(TruthSourceOperation::Update),
                'item remove' => $item->actions->writePublic(TruthSourceOperation::Remove),
                'object create' => ExternalGuardedObject::create()->sync(),
                'object update' => $object->sync(),
                'object remove' => $object->delete(),
            };
            self::fail('A stale grant must not let an external action through');
        } catch (ExternalWriterException) {
            self::assertSame($before, serialize($objects));
            self::assertSame($sql, new ReflectionProperty(Database::class, 'lastSql')->getValue());
        }
    }

    public function testMountedChainKeepsOrdinaryWritesAndExternalReadInterests(): void
    {
        $context = new GuardedDbContext();
        $context->mountFixture(Objects::LAZY_STRATEGY_KEY, external: false);
        $context->mountFixture(Objects::LAZY_STRATEGY_KEY, external: true);
        Hilos::$db = $context;
        self::assertSame([
            self::COLLECTION => GuardedEntity::class,
            self::EXTERNAL => ExternalGuardedEntity::class,
        ], $context->getMountedEntities());
        self::assertSame('_externalWriter', Entity::META_EXTERNAL_WRITER);
        self::assertFalse(GuardedEntity::_externalWriter);
        self::assertTrue(ExternalGuardedEntity::_externalWriter);
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::all(), self::AGENT);
        TruthSourceRegistry::registerCreate(self::COLLECTION, self::AGENT);
        ExecutionContext::setCurrentAgentId(self::AGENT);
        $view = $context->getDbItemCollection(self::COLLECTION);
        $view->actions->createPublic();
        $view->actions->writePublic();
        $view->actions->removePublic();
        $view->actions->writeSetPublic(self::OWN_SET);
        $view->actions->removeSetPublic(self::OWN_SET);
        DbWriteGuard::guardItemWrite(self::COLLECTION, '1', static fn(): array => [], TruthSourceOperation::Update);
        DbWriteGuard::guardItemWrite(self::COLLECTION, '1', static fn(): array => [], TruthSourceOperation::Remove);

        SourceInterestRegistry::readsWhatIsDelivered();
        SourceInterestRegistry::register(SourceChange::KIND_DB, self::EXTERNAL, SourceConsumer::agent(self::AGENT));
        SourceInterestRegistry::markReady(SourceChange::KIND_DB, self::EXTERNAL);
        self::assertSame($context->mountedObjectCollection(self::EXTERNAL), $context->getObjectCollection(self::EXTERNAL));
        self::assertFalse(TruthSourceRegistry::hasTruthSource(self::EXTERNAL));
    }

    public function testExternalMetadataResolutionDoesNotUseTheReaderDoor(): void
    {
        $context = new GuardedDbContext();
        $context->mountFixture(Objects::LAZY_STRATEGY_KEY, external: true);
        Hilos::$db = $context;
        SourceInterestRegistry::readsWhatIsDelivered();
        try {
            TruthSourceRegistry::checkCanWrite(self::EXTERNAL, TruthSourceOperation::Update);
            self::fail('External metadata must be resolved without reader interest');
        } catch (ExternalWriterException) {
            self::assertFalse(SourceInterestRegistry::isDeclared(SourceChange::KIND_DB, self::EXTERNAL));
        }

        $this->expectException(DbCollectionNotReadableException::class);
        $context->getObjectCollection(self::EXTERNAL);
    }

    /** @return iterable<string, array{string, bool}> Direct Entity entry points with new and hydrated rows */
    public static function directExternalWrites(): iterable
    {
        foreach (['save', 'save columns', 'changed diff', 'unchanged diff', 'delete'] as $path) {
            yield "new {$path}" => [$path, false];
            yield "stored {$path}" => [$path, true];
        }
    }

    /**
     * @param string $path Direct Entity write entry point
     * @param bool $stored Whether fromRow() marked the Entity related
     */
    #[DataProvider('directExternalWrites')]
    public function testManualExternalEntityRefusesBeforeBindingSqlOrSavedMemory(string $path, bool $stored): void
    {
        $entity = $stored
            ? ManualExternalGuardedEntity::fromRow(['id' => '1', 'owner_id' => '42'])
            : new ManualExternalGuardedEntity();
        self::assertSame($stored, $entity->isRelated());
        self::assertNull(Hilos::$db);
        $original = clone $entity;
        if ($path === 'changed diff') {
            $entity->owner_id = 7;
        }
        $before = serialize($entity);
        $sql = new ReflectionProperty(Database::class, 'lastSql')->getValue();

        try {
            match ($path) {
                'save' => $entity->save(),
                'save columns' => $entity->save(['owner_id']),
                'changed diff', 'unchanged diff' => $entity->saveDiff($original),
                'delete' => $entity->delete(),
            };
            self::fail('Even an unmounted Entity must refuse external writes');
        } catch (ExternalWriterException $failure) {
            self::assertStringContainsString('guard_lazy_test', $failure->getMessage());
            self::assertStringContainsString('external database writer', $failure->getMessage());
            self::assertSame($before, serialize($entity));
            self::assertSame($sql, new ReflectionProperty(Database::class, 'lastSql')->getValue());
        }
    }

    public function testHydrationAndOrdinaryEntityDiffKeepTheirExistingContract(): void
    {
        $entity = ExternalGuardedEntity::fromRow(['id' => '1', 'owner_id' => '42']);
        self::assertSame(['id' => 1, 'owner_id' => 42], $entity->toArray());
        self::assertTrue($entity->isRelated());
        $ordinary = GuardedEntity::fromRow(['id' => '1', 'owner_id' => '42']);
        self::assertTrue($ordinary->saveDiff(clone $ordinary));
    }

    /**
     * Builds the collection door of a fixture collection on one lazy strategy.
     *
     * @param class-string<Objects> $objectsClass Object-collection fixture to stand the door on
     * @param int $strategy Lazy-loading strategy the collection is registered with
     * @return GuardedDbActions The door, ready to be asked
     */
    private function actionsFor(string $objectsClass, int $strategy): GuardedDbActions
    {
        $objectCollection = $objectsClass::initDB($strategy);
        $dbCollection = GuardedDbCollection::init();
        $dbCollection->setObjectCollection($objectCollection);
        $dbCollection->setActionsClass(GuardedDbActions::class);

        return $dbCollection->actions;
    }
}

/**
 * Minimal entity fixture for the guard cases, cut into sets by its owner column.
 *
 * The set door climbs the value it is handed by the table's set column (HIL-1111), so a table
 * that declared none would be in nobody's set and refuse every claim over one. The column is a
 * soft reference: the value is the top as it is.
 */
class GuardedEntity extends Entity
{
    public const string _table = 'guard_lazy_test';
    public const string _primary = 'id';
    public const array _columns = ['id', 'owner_id'];
    public const array _types = ['id' => 'integer', 'owner_id' => 'integer'];
    public const string _setVia = 'owner_id';
    public const bool _setRoot = false;

    public ?int $id = null;
    public ?int $owner_id = null;
}

/** Entity collection link of the ordinary fixture chain. */
class GuardedEntities extends EntityCollection
{
    public const string ENTITY_CLASS = GuardedEntity::class;
}

/**
 * Minimal object fixture wrapping the guard entity.
 */
class GuardedObject extends Object_
{
    public const string ENTITY_CLASS = GuardedEntity::class;
    public const string OBJECT_COLLECTION_CLASS = GuardedObjects::class;
}

/**
 * A named collection: the kind the guard judges.
 *
 * @method GuardedObject|null first()
 * @method GuardedObject|null last()
 * @method GuardedObject|null get(int|string|null $id)
 * @method GuardedObject|null offsetGet(mixed $offset)
 */
class GuardedObjects extends Objects
{
    public const string OBJECT_CLASS = GuardedObject::class;
    public const string ENTITY_COLLECTION_CLASS = GuardedEntities::class;
    public const string COLLECTION_KEY = 'unit_guard_lazy';
}

/**
 * A manual collection: no key, so no owner to ask for.
 */
final class UnkeyedObjects extends Objects
{
    public const string OBJECT_CLASS = GuardedObject::class;
}

/**
 * Minimal DB collection fixture holding the guard's object collection.
 *
 * @method GuardedObjects|null getObjectCollection()
 * @method GuardedDbItem|null first()
 * @method GuardedDbItem|null last()
 * @method GuardedDbItem offsetGet(mixed $offset)
 */
class GuardedDbCollection extends DbCollection
{
    public const string DB_ITEM_CLASS = GuardedDbItem::class;
    public const string OBJECT_COLLECTION_CLASS = GuardedObjects::class;
}

/** View item link of the ordinary fixture chain. */
class GuardedDbItem extends DbItem
{
}

/**
 * Exposes the protected doors the guard stands in, the write door once per operation it is asked with.
 */
class GuardedDbActions extends DbActions
{
    public function writePublic(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Update);
    }

    public function removePublic(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);
    }

    /**
     * Asks the set door for a bulk edit of one set.
     *
     * @param string $setKey Set the edit is cut by
     * @throws WriteNotAllowedException When the truth source rejects the edit of that set
     */
    public function writeSetPublic(string $setKey): void
    {
        $this->ensureCanWriteSet($setKey, TruthSourceOperation::Update);
    }

    /**
     * Asks the set door for a delete of one set.
     *
     * @param string $setKey Set the delete is cut by
     * @throws WriteNotAllowedException When the truth source rejects the delete of that set
     */
    public function removeSetPublic(string $setKey): void
    {
        $this->ensureCanWriteSet($setKey, TruthSourceOperation::Remove);
    }

    public function createPublic(): void
    {
        $this->ensureCanCreate();
    }

    /**
     * Asks the create door for a row of one set.
     *
     * @param string $setKey Set column value the new row would carry
     * @throws CreateNotAllowedException When the truth source rejects creating a row in that set
     */
    public function createInSetPublic(string $setKey): void
    {
        $this->ensureCanCreateInSet($setKey);
    }

    public function deleteAllPublic(): void
    {
        $this->deleteAllObjects();
    }
}

/** Item action door, without a write beyond the right being checked. */
class GuardedItemActions extends ItemDbActions
{
    /** @param TruthSourceOperation $operation Operation to ask the shared item door about */
    public function writePublic(TruthSourceOperation $operation): void
    {
        $this->ensureCanWrite($operation);
    }
}

/** External metadata is the sole change in the fixture's row contract. */
class ExternalGuardedEntity extends GuardedEntity
{
    public const bool _externalWriter = true;
}

/** An invalid binding proves a direct refusal precedes even binding resolution. */
final class ManualExternalGuardedEntity extends ExternalGuardedEntity
{
    public const string _database = 'unit_external_unconfigured';
}

/** Entity collection of the complete external fixture chain. */
final class ExternalGuardedEntities extends GuardedEntities
{
    public const string ENTITY_CLASS = ExternalGuardedEntity::class;
}

/** Object item of the complete external fixture chain. */
final class ExternalGuardedObject extends GuardedObject
{
    public const string ENTITY_CLASS = ExternalGuardedEntity::class;
    public const string OBJECT_COLLECTION_CLASS = ExternalGuardedObjects::class;
}

/**
 * Object collection of the complete external fixture chain.
 *
 * @method ExternalGuardedObject|null first()
 * @method ExternalGuardedObject|null last()
 * @method ExternalGuardedObject|null get(int|string|null $id)
 * @method ExternalGuardedObject|null offsetGet(mixed $offset)
 */
final class ExternalGuardedObjects extends GuardedObjects
{
    public const string OBJECT_CLASS = ExternalGuardedObject::class;
    public const string ENTITY_COLLECTION_CLASS = ExternalGuardedEntities::class;
    public const string COLLECTION_KEY = 'unit_guard_external';

    /** @param ExternalGuardedObject $object Row hydrated without SQL or a membership announcement */
    public function seed(ExternalGuardedObject $object): void
    {
        $this->hydrate($object->getIdString(), $object);
    }
}

/** View item of the complete external fixture chain. */
final class ExternalGuardedDbItem extends GuardedDbItem
{
}

/**
 * View collection of the complete external fixture chain.
 *
 * @method ExternalGuardedObjects|null getObjectCollection()
 * @method ExternalGuardedDbItem|null first()
 * @method ExternalGuardedDbItem|null last()
 * @method ExternalGuardedDbItem offsetGet(mixed $offset)
 */
final class ExternalGuardedDbCollection extends GuardedDbCollection
{
    public const string DB_ITEM_CLASS = ExternalGuardedDbItem::class;
    public const string OBJECT_COLLECTION_CLASS = ExternalGuardedObjects::class;
}

/** Collection actions of the external fixture chain. */
final class ExternalGuardedDbActions extends GuardedDbActions
{
}

/** Item actions of the external fixture chain. */
final class ExternalGuardedItemActions extends GuardedItemActions
{
}

/** Declares the same key in both registries with adding and removing alone. */
final class ExternalGuardedOwner implements TruthSourceOwner
{
    public const array OWNS_DB = [ExternalGuardedObjects::COLLECTION_KEY => [TruthSourceOperation::Add, TruthSourceOperation::Remove]];
    public const array OWNS_RT = self::OWNS_DB;

    /** @return TruthSourceOperations Default operations when a declaration leaves them to its kind */
    public static function defaultTruthSourceOperations(): TruthSourceOperations
    {
        return TruthSourceOperations::all();
    }
}

/** Mounts full fixture chains through the same methods a project context uses. */
final class GuardedDbContext extends HilosDbContext
{
    /** Deliberately mounts no framework tables in this unit fixture. */
    public function configure(): void
    {
    }

    /**
     * @param int $strategy Loading strategy to mount
     * @param bool $external Whether to mount the external or ordinary chain
     */
    public function mountFixture(int $strategy, bool $external): void
    {
        if ($external) {
            $this->mountObjectCollection(ExternalGuardedObjects::class, $strategy);
            $this->setRepresent(
                ExternalGuardedObjects::COLLECTION_KEY,
                ExternalGuardedDbCollection::class,
                ExternalGuardedDbActions::class,
                ExternalGuardedItemActions::class,
            );
        } else {
            $this->mountObjectCollection(GuardedObjects::class, $strategy);
            $this->setRepresent(
                GuardedObjects::COLLECTION_KEY,
                GuardedDbCollection::class,
                GuardedDbActions::class,
                GuardedItemActions::class,
            );
        }
    }
}
