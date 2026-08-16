<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Database\Actions\Collection\DbActions;
use Hilos\Database\Actions\Exception\DuplicateIdException;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Reproduces the HIL-375 fault: an external db-reset under a live daemon restarts the
 * autoincrement, the DB mints id=1, but the in-memory ObjectCollection still holds the
 * stale pre-reset id=1 -> DuplicateIdException on register.
 *
 * The daemon now re-hydrates its DB-backed collections when it detects a DB generation
 * change, so the fresh id is inserted instead of rejected; a genuine duplicate (no DB
 * change) still throws.
 */
final class DbReHydrateOnResetTest extends TestCase
{
    private ?DbContext $previousDb = null;

    protected function setUp(): void
    {
        $this->previousDb = Hilos::$db;
    }

    protected function tearDown(): void
    {
        Hilos::$db = $this->previousDb;
    }

    public function testRegisterAfterDbResetReHydratesInsteadOfThrowing(): void
    {
        $objectCollection = ReHydrateObjects::initDB(Objects::LAZY_STRATEGY_KEY);
        $staleObject = ReHydrateObject::fromEntity(ReHydrateEntity::withId(1));
        $objectCollection[$staleObject->getIdString()] = $staleObject;

        $dbCollection = ReHydrateDbCollection::init();
        $dbCollection->setObjectCollection($objectCollection);
        $dbCollection->setActionsClass(ReHydrateDbActions::class);

        $context = ReHydrateDbContext::create('gen-a', 'events', $objectCollection, $dbCollection);
        $context->refreshDbGeneration();
        Hilos::$db = $context;

        $actions = $dbCollection->actions;
        $freshObject = ReHydrateObject::fromEntity(ReHydrateEntity::withId(1));

        // The db-reset dropped and recreated the schema: the generation marker changed.
        $context->setGeneration('gen-b');
        $actions->addPublic($freshObject);

        $this->assertSame($freshObject, $objectCollection->get('1'));
    }

    public function testGenuineDuplicateWithoutDbChangeStillThrows(): void
    {
        $objectCollection = ReHydrateObjects::initDB(Objects::LAZY_STRATEGY_KEY);
        $existingObject = ReHydrateObject::fromEntity(ReHydrateEntity::withId(1));
        $objectCollection[$existingObject->getIdString()] = $existingObject;

        $dbCollection = ReHydrateDbCollection::init();
        $dbCollection->setObjectCollection($objectCollection);
        $dbCollection->setActionsClass(ReHydrateDbActions::class);

        $context = ReHydrateDbContext::create('gen-a', 'events', $objectCollection, $dbCollection);
        $context->refreshDbGeneration();
        Hilos::$db = $context;

        $actions = $dbCollection->actions;
        $duplicateObject = ReHydrateObject::fromEntity(ReHydrateEntity::withId(1));

        // No db-reset: the generation is unchanged, so the collision is a genuine duplicate.
        $this->expectException(DuplicateIdException::class);
        $actions->addPublic($duplicateObject);
    }
}

/**
 * Minimal single-column entity fixture for the re-hydrate reproduction.
 */
final class ReHydrateEntity extends Entity
{
    public const string _table = 'rehydrate_test';
    public const string _primary = 'id';
    public const array _columns = ['id'];
    public const array _types = ['id' => 'integer'];

    public ?int $id = null;

    public static function withId(int $id): self
    {
        $entity = new self();
        $entity->id = $id;

        return $entity;
    }
}

/**
 * Minimal object fixture wrapping the re-hydrate entity.
 */
final class ReHydrateObject extends Object_
{
    public const string ENTITY_CLASS = ReHydrateEntity::class;
}

/**
 * Minimal object collection fixture for the re-hydrate reproduction.
 */
final class ReHydrateObjects extends Objects
{
    public const string OBJECT_CLASS = ReHydrateObject::class;
}

/**
 * Minimal DB collection fixture wrapping the re-hydrate object collection.
 */
final class ReHydrateDbCollection extends DbCollection
{
}

/**
 * Exposes the protected collision-handling add for the reproduction.
 */
final class ReHydrateDbActions extends DbActions
{
    public function addPublic(Object_ $object): void
    {
        $this->addObjectToCollection($object);
    }
}

/**
 * Test context that drives the DB generation marker without a real DB.
 */
final class ReHydrateDbContext extends DbContext
{
    private ?string $generation = null;

    public static function create(?string $generation, string $name, Objects $objectCollection, DbCollection $dbCollection): self
    {
        $context = new self();
        $context->generation = $generation;
        $context->_objectCollections[$name] = $objectCollection;
        $context->_dbItemCollections[$name] = $dbCollection;

        return $context;
    }

    public function configure(): void
    {
    }

    public function setGeneration(?string $generation): void
    {
        $this->generation = $generation;
    }

    protected function readDbGeneration(): ?string
    {
        return $this->generation;
    }
}
