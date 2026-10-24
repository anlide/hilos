<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database\View\Collection;

use Hilos\Core\Source\SourceChangeBus;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\User as EntityUser;
use Hilos\Database\Object\Collection\Users as ObjectUsers;
use Hilos\Database\Object\Item\User as ObjectUser;
use Hilos\Database\View\Collection\Users;
use Hilos\Database\View\Item\User;
use PHPUnit\Framework\TestCase;

/** Real Users reads over a controlled Object source; no SQL or production reset is needed. */
final class UsersListAllTest extends TestCase
{
    /** Keeps fixture membership announcements local to this case. */
    protected function setUp(): void
    {
        SourceChangeBus::reset();
    }

    /** Releases the test's local announcement and transaction bookkeeping. */
    protected function tearDown(): void
    {
        Database::handlerEnd();
        SourceChangeBus::reset();
    }

    /** An incomplete key-lazy source is loaded in full before its membership is selected. */
    public function testPartialSourceLoadsEveryUserInSourceOrderAndClearsOldWrappers(): void
    {
        $objects = UsersListAllObjects::initEmpty();
        $objects->rows = [self::person(17, 'First'), self::person(4, 'Second'), self::person(29, 'Third')];
        $objects->hold(self::person(17, 'Old cached row'));
        $users = Users::init();
        $users->setObjectCollection($objects);
        $oldWrapper = $users[17];
        $objects->makePartial();
        self::assertFalse($objects->isAllLoaded());

        $snapshot = $users->listAll();

        self::assertInstanceOf(Users::class, $snapshot);
        self::assertSame([17, 4, 29], array_keys(iterator_to_array($snapshot)));
        self::assertSame(1, $objects->loads);
        self::assertTrue($objects->isAllLoaded());
        self::assertNull($snapshot->getObjectCollection());
        self::assertSame('First', $snapshot[17]->name);
        self::assertNotSame($oldWrapper, $snapshot[17]);
        foreach ($snapshot as $id => $user) {
            self::assertSame($users[$id], $user);
        }
    }

    /** Each container keeps its selected keys while wrappers continue to refer to the same rows. */
    public function testRepeatedReadsShareWrappersButKeepSeparateMembershipSnapshots(): void
    {
        $objects = UsersListAllObjects::initEmpty();
        $objects->rows = [self::person(17, 'First'), self::person(4, 'Second')];
        $objects->makePartial();
        $users = Users::init();
        $users->setObjectCollection($objects);
        $first = $users->listAll();
        $second = $users->listAll();

        self::assertNotSame($users, $first);
        self::assertNotSame($first, $second);
        self::assertSame($first[17], $second[17]);
        self::assertSame(1, $objects->loads);
        unset($objects[17]);
        $objects->hold(self::person(29, 'Added later'));
        $objects->rows[1]->name = 'Edited scalar';

        self::assertSame([17, 4], array_keys(iterator_to_array($first)));
        self::assertSame([17, 4], array_keys(iterator_to_array($second)));
        self::assertSame([4, 29], array_keys(iterator_to_array($users->listAll())));
        self::assertSame('First', $first[17]->name);
        self::assertSame('Edited scalar', $first[4]->name);
        $first->add(new User(self::person(40, 'Only in first')));
        self::assertArrayNotHasKey(40, $second);
        self::assertArrayNotHasKey(40, $users);
    }

    /** Empty automatic and lazy sources return independent, usable manual containers. */
    public function testEmptySources(): void
    {
        $users = Users::init();
        self::assertCount(0, $users->listAll());
        $objects = UsersListAllObjects::initEmpty();
        $objects->makePartial();
        $users->setObjectCollection($objects);

        $snapshot = $users->listAll();

        self::assertCount(0, $snapshot);
        self::assertSame(1, $objects->loads);
        self::assertTrue($objects->isAllLoaded());
        $snapshot->add(new User(self::person(5, 'Manual addition')));
        self::assertCount(1, $snapshot);
        self::assertCount(0, $users->listAll());
        self::assertSame(1, $objects->loads);
    }

    /** Manual input retains its order and wrapper identity, including a concrete Users subtype. */
    public function testManualSourcesAndLateStaticReturnType(): void
    {
        $users = UsersListAllSelection::initEmpty();
        self::assertCount(0, $users->listAll());
        $first = new User(self::person(12, 'First'));
        $second = new User(self::person(3, 'Second'));
        $users->add($first);
        $users->add($second);

        $snapshot = $users->listAll();

        self::assertInstanceOf(UsersListAllSelection::class, $snapshot);
        self::assertNotSame($users, $snapshot);
        self::assertNotSame($snapshot, $users->listAll());
        self::assertSame([12 => $first, 3 => $second], iterator_to_array($snapshot));
        $users->add(new User(self::person(7, 'Later')));
        self::assertSame([12 => $first, 3 => $second], iterator_to_array($snapshot));
    }

    /**
     * @param int $id Persisted identity for the fixture
     * @param string $name Scalar label
     * @return ObjectUser Unsaved fixture with a concrete primary key
     */
    private static function person(int $id, string $name): ObjectUser
    {
        return ObjectUser::fromEntity(EntityUser::fromRow(['id' => $id, 'name' => $name, 'theme_pick_initialized' => false]));
    }
}

/** Only the full-load boundary is doubled; production iteration, caching and manual add run. */
final class UsersListAllObjects extends ObjectUsers
{
    /** @var list<ObjectUser> Complete rows supplied at the load boundary */
    public array $rows = [];
    public int $loads = 0;

    /** Marks the otherwise manual fixture as an incomplete key-lazy source without a DB connection. */
    public function makePartial(): void
    {
        $this->_allowLazyLoading = true;
        $this->_lazyStrategy = self::LAZY_STRATEGY_KEY;
        $this->_allLoaded = false;
    }

    /**
     * @param ObjectUser $user Row held through the silent load seam
     */
    public function hold(ObjectUser $user): void
    {
        $this->hydrate($user->getIdString(), $user);
    }

    /** Replaces the partial map with the complete fixture in its declared order. */
    public function loadAllFromDB(): void
    {
        $this->loads++;
        $this->clearInMemory();
        foreach ($this->rows as $user) {
            $this->hold($user);
        }
        $this->_allLoaded = true;
        $this->_allowLazyLoading = false;
    }
}

/** Confirms listAll preserves the concrete View collection type. */
final class UsersListAllSelection extends Users
{
}
