<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database\Schema;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\User as EntityUser;
use Hilos\Database\Exception\InvalidMountedCollectionException;
use Hilos\Database\Object\Collection\Users as ObjectUsers;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Item\User as ObjectUser;
use Hilos\Database\Object\Objects;
use Hilos\Database\Schema\MountedCollectionKeyGuard;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/** The startup guard keeps actual mounts, collection declarations, and row announcements aligned. */
final class MountedCollectionKeyGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        Hilos::$db = null;
        parent::tearDown();
    }

    public function testEmptyAndMatchingMountsPass(): void
    {
        Hilos::$db = new MountedKeyGuardContext();
        MountedCollectionKeyGuard::assertMountedKeysAgree();

        Hilos::$db->put(ObjectUsers::COLLECTION_KEY, ObjectUsers::initDB());
        MountedCollectionKeyGuard::assertMountedKeysAgree();
        $this->assertSame(ObjectUsers::COLLECTION_KEY, GuardUserObject::key());
        $this->assertSame(ObjectUsers::COLLECTION_KEY, ObjectUsers::initDB()->getCollectionKey());
    }

    public function testActualMountKeyMismatchIsNamed(): void
    {
        Hilos::$db = new MountedKeyGuardContext();
        Hilos::$db->put('wrong', ObjectUsers::initDB());

        $this->expectException(InvalidMountedCollectionException::class);
        $this->expectExceptionMessage('under [wrong] but declares COLLECTION_KEY [' . ObjectUsers::COLLECTION_KEY . ']');
        MountedCollectionKeyGuard::assertMountedKeysAgree();
    }

    public function testMissingRowCollectionLinkIsNamed(): void
    {
        Hilos::$db = new MountedKeyGuardContext();
        Hilos::$db->put(UnlinkedObjects::COLLECTION_KEY, UnlinkedObjects::initDB());

        $this->expectException(InvalidMountedCollectionException::class);
        $this->expectExceptionMessage('OBJECT_COLLECTION_CLASS links to (missing) [(missing)]');
        MountedCollectionKeyGuard::assertMountedKeysAgree();
    }

    public function testOwnSubclassRequiresExactLink(): void
    {
        Hilos::$db = new MountedKeyGuardContext();
        Hilos::$db->put(ObjectUsers::COLLECTION_KEY, DerivedUsers::initDB());

        $this->expectException(InvalidMountedCollectionException::class);
        $this->expectExceptionMessage(ObjectUsers::class . ' [' . ObjectUsers::COLLECTION_KEY . ']');
        MountedCollectionKeyGuard::assertMountedKeysAgree();
    }

    public function testFrameworkSubclassMayKeepItsBaseRowLink(): void
    {
        Hilos::$db = new MountedKeyGuardContext(framework: true);
        Hilos::$db->configure();
        Hilos::$db->put(HilosDbContext::users, DerivedUsers::initDB());

        MountedCollectionKeyGuard::assertMountedKeysAgree();
        $this->addToAssertionCount(1);
    }
}

/** Context fixture that can mount framework keys or an isolated collection. */
final class MountedKeyGuardContext extends HilosDbContext
{
    public function __construct(private readonly bool $framework = false)
    {
        parent::__construct();
    }

    public function configure(): void
    {
        if ($this->framework) {
            parent::configure();
        }
    }

    /**
     * @param string $key Actual mount key
     * @param Objects $objects Collection mounted under it
     */
    public function put(string $key, Objects $objects): void
    {
        $this->_objectCollections[$key] = $objects;
    }
}

final class GuardUserObject extends ObjectUser
{
    /** @return string Row announcement key */
    public static function key(): string
    {
        return static::getCollectionKey();
    }
}

final class DerivedUsers extends ObjectUsers
{
}

/** @extends Objects<UnlinkedObject> */
final class UnlinkedObjects extends Objects
{
    public const string OBJECT_CLASS = UnlinkedObject::class;
    public const string COLLECTION_KEY = 'unlinked';
}

final class UnlinkedObject extends Object_
{
    public const string ENTITY_CLASS = EntityUser::class;
}
