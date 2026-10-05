<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database;

use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Entity\Item\User as EntityUser;
use Hilos\Database\Exception\InvalidMountedCollectionException;
use Hilos\Database\Object\Collection\Identities as ObjectIdentities;
use Hilos\Database\Object\Collection\Users as ObjectUsers;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use PHPUnit\Framework\TestCase;

/** Mounted entities are resolved from the actual registry without reading rows. */
final class DbContextMountedEntitiesTest extends TestCase
{
    public function testEmptyContextAndRegistrationOrder(): void
    {
        $context = new MountedEntitiesContext();
        $this->assertSame([], $context->getMountedEntities());

        $context->put('second', ObjectIdentities::initDB());
        $context->put('first', ObjectUsers::initDB());
        $this->assertSame(
            ['second' => EntityIdentity::class, 'first' => EntityUser::class],
            $context->getMountedEntities(),
        );
    }

    public function testMalformedClassLinksAndTableRefuseWithActualKey(): void
    {
        foreach ([
            'OBJECT_CLASS' => BrokenObjectClassCollection::class,
            'ENTITY_CLASS' => BrokenEntityClassCollection::class,
            '_table' => BrokenTableCollection::class,
        ] as $link => $collectionClass) {
            $context = new MountedEntitiesContext();
            $context->put('actual', $collectionClass::initDB());
            try {
                $context->getMountedEntities();
                $this->fail("{$link} was accepted");
            } catch (InvalidMountedCollectionException $failure) {
                $this->assertStringContainsString('actual', $failure->getMessage());
                $this->assertStringContainsString($collectionClass, $failure->getMessage());
                $this->assertStringContainsString($link, $failure->getMessage());
            }
        }
    }

    public function testDeclaredMountUsesCollectionKeyAndRefusesSecondMount(): void
    {
        $context = new MountedEntitiesContext();
        $context->putDeclared(ObjectUsers::class);
        $this->assertSame([HilosDbContext::users => EntityUser::class], $context->getMountedEntities());

        $this->expectException(InvalidMountedCollectionException::class);
        $context->putDeclared(ObjectUsers::class);
    }

    public function testDeclaredMountRefusesEmptyKey(): void
    {
        $context = new MountedEntitiesContext();

        $this->expectException(InvalidMountedCollectionException::class);
        $this->expectExceptionMessage('empty or occupied key');
        $context->putDeclared(EmptyKeyCollection::class);
    }
}

/** Minimal context with a test entrance to its mounted map. */
final class MountedEntitiesContext extends DbContext
{
    public function configure(): void
    {
    }

    /**
     * @param string $key Actual registration key
     * @param Objects $objects Mounted collection
     */
    public function put(string $key, Objects $objects): void
    {
        $this->_objectCollections[$key] = $objects;
    }

    /** @param class-string<Objects> $collectionClass Collection to mount by its declared key */
    public function putDeclared(string $collectionClass): void
    {
        $this->mountObjectCollection($collectionClass, Objects::LAZY_STRATEGY_KEY);
    }
}

/** @extends Objects<Object_> */
final class BrokenObjectClassCollection extends Objects
{
    public const string OBJECT_CLASS = '';
}

/** @extends Objects<Object_> */
final class EmptyKeyCollection extends Objects
{
}

/** @extends Objects<BrokenEntityClassObject> */
final class BrokenEntityClassCollection extends Objects
{
    public const string OBJECT_CLASS = BrokenEntityClassObject::class;
}

final class BrokenEntityClassObject extends Object_
{
}

/** @extends Objects<BrokenTableObject> */
final class BrokenTableCollection extends Objects
{
    public const string OBJECT_CLASS = BrokenTableObject::class;
}

final class BrokenTableObject extends Object_
{
    public const string ENTITY_CLASS = BrokenTableEntity::class;
}

final class BrokenTableEntity extends Entity
{
    public const string _table = '';
}
