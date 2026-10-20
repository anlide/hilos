<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\View\Collection\Countries;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Database\View\Collection\Languages;
use Hilos\Database\View\Collection\Locales;
use Hilos\Database\View\Collection\OAuthProviders;
use Hilos\Database\View\Collection\Settings;
use Hilos\HilosException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use stdClass;

/** Optional reads must survive a View getter that refuses missing rows (HIL-1698). */
final class DbCollectionOptionalReadTest extends TestCase
{
    /**
     * The Object rows and View wrapping stay real below the mocked public getter.
     * Repeated predicates must reuse the wrapper that the first read created.
     *
     * @param class-string<DbCollection> $collectionClass Collection with a business-key offset
     * @param string $finder Object-layer business-key reader
     * @param string $businessKey Existing business key
     * @throws HilosException When the fixture or read fails
     */
    #[DataProvider('collections')]
    public function testPresentPrimaryAndBusinessKeysDoNotCallTheGetter(
        string $collectionClass,
        string $finder,
        string $businessKey,
    ): void {
        $object = $this->objectFor($collectionClass);
        $objects = $this->getMockBuilder($collectionClass::OBJECT_COLLECTION_CLASS)
            ->onlyMethods([$finder, 'offsetGet'])->getMock();
        $objects->expects(self::once())->method('offsetGet')->with(41)->willReturn($object);
        $objects->expects(self::once())->method($finder)->with($businessKey)->willReturn($object);
        $collection = $this->withoutGetter($collectionClass);
        $collection->setObjectCollection($objects);

        self::assertArrayHasKey(41, $collection);
        self::assertArrayHasKey($businessKey, $collection);
        self::assertArrayHasKey(41, $collection);
    }

    /**
     * Missing and unsupported offsets never reach the View getter, including through ??.
     * A numeric string is a business key, not a primary integer.
     *
     * @param class-string<DbCollection> $collectionClass Collection with a business-key offset
     * @param string $finder Object-layer business-key reader
     * @param string $businessKey Missing business key
     * @throws HilosException When the fixture or read fails
     */
    #[DataProvider('collections')]
    public function testAbsentAndUnsupportedKeysPermitAnOptionalRead(
        string $collectionClass,
        string $finder,
        string $businessKey,
    ): void {
        $objects = $this->getMockBuilder($collectionClass::OBJECT_COLLECTION_CLASS)
            ->onlyMethods([$finder, 'offsetGet'])->getMock();
        $objects->expects(self::exactly(2))->method('offsetGet')->with(99)->willReturn(null);
        $objects->expects(self::exactly(4))->method($finder)
            ->willReturnCallback(static function (string $key) use ($businessKey): ?Object_ {
                self::assertContains($key, [$businessKey, '41']);
                return null;
            });
        $collection = $this->withoutGetter($collectionClass);
        $collection->setObjectCollection($objects);

        foreach ([99, $businessKey, '41'] as $key) {
            self::assertArrayNotHasKey($key, $collection);
            self::assertNull($collection[$key] ?? null);
        }
        foreach ([null, false, 41.0, [], new stdClass()] as $key) {
            self::assertFalse($collection->offsetExists($key));
            self::assertNull($collection[$key] ?? null);
        }
    }

    /**
     * A manual selection answers from its own items without any backing Object collection.
     *
     * @param class-string<DbCollection> $collectionClass Collection with a business-key offset
     * @param string $finder Unused Object reader in manual mode
     * @param string $businessKey Unused business key in this primary-key selection
     * @throws HilosException When the fixture or read fails
     */
    #[DataProvider('collections')]
    public function testManualSelectionsKeepPresentAndMissingPrimaryKeys(
        string $collectionClass,
        string $finder,
        string $businessKey,
    ): void {
        $collection = $collectionClass::initEmpty();
        $itemClass = $collectionClass::DB_ITEM_CLASS;
        $item = new $itemClass($this->objectFor($collectionClass));
        $collection->add($item);

        self::assertArrayHasKey(41, $collection);
        self::assertSame($item, $collection[41] ?? null);
        self::assertArrayNotHasKey(99, $collection);
        self::assertNull($collection[99] ?? null);
        self::assertNull($collection[null] ?? null);
    }

    /**
     * Coalescing skips the getter only for absence; a present key still reads through it.
     *
     * @param class-string<DbCollection> $collectionClass Collection with a business-key offset
     * @param string $finder Object-layer business-key reader
     * @param string $businessKey Existing business key
     * @throws HilosException When the fixture or read fails
     */
    #[DataProvider('collections')]
    public function testCoalescingAPresentKeyCallsTheGetter(
        string $collectionClass,
        string $finder,
        string $businessKey,
    ): void {
        $object = $this->objectFor($collectionClass);
        $objects = $this->getMockBuilder($collectionClass::OBJECT_COLLECTION_CLASS)
            ->onlyMethods([$finder, 'offsetGet'])->getMock();
        $objects->method($finder)->with($businessKey)->willReturn($object);
        $objects->method('offsetGet')->with(41)->willReturn($object);
        $collection = $this->getMockBuilder($collectionClass)->onlyMethods(['offsetGet'])->getMock();
        $collection->setObjectCollection($objects);
        $itemClass = $collectionClass::DB_ITEM_CLASS;
        $item = new $itemClass($object);
        $collection->expects(self::once())->method('offsetGet')->with($businessKey)->willReturn($item);

        self::assertSame($item, $collection[$businessKey] ?? null);
    }

    /**
     * @param class-string<DbCollection> $collectionClass Collection with a business-key offset
     * @param string $finder Object-layer business-key reader
     * @param string $businessKey Business key whose read fails
     * @throws HilosException When the underlying read fails, as expected
     */
    #[DataProvider('collections')]
    public function testOptionalBusinessKeyReadPropagatesTheLoaderFailure(
        string $collectionClass,
        string $finder,
        string $businessKey,
    ): void {
        $objects = $this->getMockBuilder($collectionClass::OBJECT_COLLECTION_CLASS)->onlyMethods([$finder])->getMock();
        $failure = new DatabaseException('Optional business-key read failed');
        $objects->expects(self::once())->method($finder)->with($businessKey)->willThrowException($failure);
        $collection = $this->withoutGetter($collectionClass);
        $collection->setObjectCollection($objects);

        $this->expectExceptionObject($failure);
        $collection[$businessKey] ?? null;
    }

    /**
     * @param class-string<DbCollection> $collectionClass Collection with a business-key offset
     * @param string $finder Unused business-key reader
     * @param string $businessKey Unused business key
     * @throws HilosException When the underlying read fails, as expected
     */
    #[DataProvider('collections')]
    public function testPrimaryPredicatePropagatesTheLoaderFailure(
        string $collectionClass,
        string $finder,
        string $businessKey,
    ): void {
        $objects = $this->getMockBuilder($collectionClass::OBJECT_COLLECTION_CLASS)->onlyMethods(['offsetGet'])->getMock();
        $failure = new DatabaseException('Optional primary-key read failed');
        $objects->expects(self::once())->method('offsetGet')->with(41)->willThrowException($failure);
        $collection = $this->withoutGetter($collectionClass);
        $collection->setObjectCollection($objects);

        $this->expectExceptionObject($failure);
        isset($collection[41]);
    }

    /**
     * Finding a code does not bypass the View's validation of the loaded Object row.
     *
     * @param class-string<DbCollection> $collectionClass Collection with a business-key offset
     * @param string $finder Object-layer business-key reader
     * @param string $businessKey Existing code whose primary read returns the wrong Object type
     * @throws HilosException When View validation refuses the row, as expected
     */
    #[DataProvider('collections')]
    public function testBusinessPredicateValidatesTheWrappedObject(
        string $collectionClass,
        string $finder,
        string $businessKey,
    ): void {
        $objects = $this->getMockBuilder($collectionClass::OBJECT_COLLECTION_CLASS)
            ->onlyMethods([$finder, 'offsetGet'])->getMock();
        $objects->expects(self::once())->method($finder)->with($businessKey)->willReturn($this->objectFor($collectionClass));
        $objects->expects(self::once())->method('offsetGet')->with(41)->willReturn($this->createStub(Object_::class));
        $collection = $this->withoutGetter($collectionClass);
        $collection->setObjectCollection($objects);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Object must be instance of ' . $collectionClass::OBJECT_COLLECTION_CLASS::OBJECT_CLASS);
        isset($collection[$businessKey]);
    }

    /** @return array<string, array{class-string<DbCollection>, string, string}> The five predicate overrides */
    public static function collections(): array
    {
        return [
            'countries' => [Countries::class, 'findByCode', 'us'],
            'languages' => [Languages::class, 'findByCode', 'en'],
            'locales' => [Locales::class, 'findByCode', 'en-US'],
            'settings' => [Settings::class, 'findByKey', 'site.name'],
            'oauth providers' => [OAuthProviders::class, 'findByProviderKey', 'oauth:github'],
        ];
    }

    /**
     * @param class-string<DbCollection> $collectionClass Collection whose inherited predicate stays real
     * @return DbCollection&MockObject Collection with only its public getter doubled
     */
    private function withoutGetter(string $collectionClass): DbCollection&MockObject
    {
        $collection = $this->getMockBuilder($collectionClass)->onlyMethods(['offsetGet'])->getMock();
        $collection->expects(self::never())->method('offsetGet');

        return $collection;
    }

    /**
     * @param class-string<DbCollection> $collectionClass Collection whose real Object and Entity are used
     * @return Object_ Row with a primary id, ready for real View wrapping
     */
    private function objectFor(string $collectionClass): Object_
    {
        $objectClass = $collectionClass::OBJECT_COLLECTION_CLASS::OBJECT_CLASS;
        $entityClass = $objectClass::ENTITY_CLASS;
        $entity = new $entityClass();
        $entity->id = 41;

        return $objectClass::fromEntity($entity);
    }
}
