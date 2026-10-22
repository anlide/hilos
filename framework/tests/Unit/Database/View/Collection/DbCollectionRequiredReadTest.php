<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database\View\Collection;

use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\Countries;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Database\View\Collection\Languages;
use Hilos\Database\View\Collection\Locales;
use Hilos\Database\View\Collection\OAuthProviders;
use Hilos\Database\View\Collection\Settings;
use Hilos\Database\View\Collection\Users;
use Hilos\HilosException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A direct `[]` read of a DB View collection hands back the item or refuses (HIL-1702).
 *
 * The Entity, Object and View layers stay real; only the Object loader and the business-key
 * finder are doubled, so the getter under test is the real one on every collection.
 */
final class DbCollectionRequiredReadTest extends TestCase
{
    /**
     * @param class-string<DbCollection> $collectionClass Collection read by primary id
     * @param ?string $finder Object-layer business-key reader, none on the base getter
     * @throws HilosException When the fixture or read fails
     */
    #[DataProvider('readers')]
    public function testPresentPrimaryKeyReadsTheItemAndRepeatsTheWrapper(
        string $collectionClass,
        ?string $finder,
    ): void {
        $object = $this->objectFor($collectionClass);
        $objects = $this->loaderFor($collectionClass, $finder);
        $objects->expects(self::once())->method('offsetGet')->with(41)->willReturn($object);
        $collection = $collectionClass::init();
        $collection->setObjectCollection($objects);

        $item = $collection[41];

        self::assertInstanceOf($collectionClass::DB_ITEM_CLASS, $item);
        self::assertSame($object, $item->getObject());
        self::assertSame($item, $collection[41]);
    }

    /**
     * @param class-string<DbCollection> $collectionClass Collection read by primary id
     * @param ?string $finder Object-layer business-key reader, none on the base getter
     * @throws HilosException When the read refuses, as expected
     */
    #[DataProvider('readers')]
    public function testMissingPrimaryKeyRefusesWithTheKey(string $collectionClass, ?string $finder): void
    {
        $objects = $this->loaderFor($collectionClass, $finder);
        $objects->expects(self::once())->method('offsetGet')->with(99)->willReturn(null);
        $collection = $collectionClass::init();
        $collection->setObjectCollection($objects);

        $this->expectItemNotFound($collectionClass, '99');
        $collection[99];
    }

    /**
     * A null key refuses before the loader or the finder is asked anything.
     *
     * @param class-string<DbCollection> $collectionClass Collection read by primary id
     * @param ?string $finder Object-layer business-key reader, none on the base getter
     * @throws HilosException When the read refuses, as expected
     */
    #[DataProvider('readers')]
    public function testNullKeyRefusesWithoutAskingTheLoader(string $collectionClass, ?string $finder): void
    {
        $objects = $this->loaderFor($collectionClass, $finder);
        $objects->expects(self::never())->method('offsetGet');
        if ($finder !== null) {
            $objects->expects(self::never())->method($finder);
        }
        $collection = $collectionClass::init();
        $collection->setObjectCollection($objects);

        $this->expectItemNotFound($collectionClass, 'NULL');
        $collection[null];
    }

    /**
     * A manual selection answers from its own items: the present id reads, the missing one and null refuse.
     *
     * @param class-string<DbCollection> $collectionClass Collection read by primary id
     * @param ?string $finder Unused Object reader in manual mode
     * @throws HilosException When the fixture or read fails
     */
    #[DataProvider('readers')]
    public function testManualSelectionReadsItsPresentIdAndRefusesTheMissingOne(
        string $collectionClass,
        ?string $finder,
    ): void {
        $collection = $collectionClass::initEmpty();
        $itemClass = $collectionClass::DB_ITEM_CLASS;
        $item = new $itemClass($this->objectFor($collectionClass));
        $collection->add($item);

        self::assertSame($item, $collection[41]);
        self::assertFalse(isset($collection[99]));
        self::assertFalse(isset($collection[null]));

        $this->expectItemNotFound($collectionClass, '99');
        $collection[99];
    }

    /**
     * @param class-string<DbCollection> $collectionClass Collection read by primary id
     * @param ?string $finder Unused Object reader in manual mode
     * @throws HilosException When the read refuses, as expected
     */
    #[DataProvider('readers')]
    public function testManualSelectionRefusesNull(string $collectionClass, ?string $finder): void
    {
        $collection = $collectionClass::initEmpty();
        $itemClass = $collectionClass::DB_ITEM_CLASS;
        $collection->add(new $itemClass($this->objectFor($collectionClass)));

        $this->expectItemNotFound($collectionClass, 'NULL');
        $collection[null];
    }

    /**
     * The optional forms never reach the refusal: a miss and null answer false and the fallback,
     * and coalescing a present key hands back the item the getter reads.
     *
     * @param class-string<DbCollection> $collectionClass Collection read by primary id
     * @param ?string $finder Object-layer business-key reader, none on the base getter
     * @throws HilosException When the fixture or read fails
     */
    #[DataProvider('readers')]
    public function testOptionalFormsSkipTheRefusalAndCoalesceAPresentKeyToTheItem(
        string $collectionClass,
        ?string $finder,
    ): void {
        $object = $this->objectFor($collectionClass);
        $objects = $this->loaderFor($collectionClass, $finder);
        $objects->method('offsetGet')
            ->willReturnCallback(static fn (int $key): ?Object_ => $key === 41 ? $object : null);
        $collection = $collectionClass::init();
        $collection->setObjectCollection($objects);
        $itemClass = $collectionClass::DB_ITEM_CLASS;
        $fallback = new $itemClass($object);

        self::assertFalse(isset($collection[99]));
        self::assertSame($fallback, $collection[99] ?? $fallback);
        self::assertFalse(isset($collection[null]));
        self::assertSame($fallback, $collection[null] ?? $fallback);
        self::assertTrue(isset($collection[41]));
        self::assertSame($collection[41], $collection[41] ?? $fallback);
        self::assertSame($object, ($collection[41] ?? $fallback)->getObject());
    }

    /**
     * A loader that fails keeps its own exception; the refusal is for a key that holds nothing.
     *
     * @param class-string<DbCollection> $collectionClass Collection read by primary id
     * @param ?string $finder Object-layer business-key reader, none on the base getter
     * @throws HilosException When the loader fails, as expected
     */
    #[DataProvider('readers')]
    public function testPrimaryKeyReadPropagatesTheLoaderFailure(string $collectionClass, ?string $finder): void
    {
        $objects = $this->loaderFor($collectionClass, $finder);
        $failure = new DatabaseException('Required primary-key read failed');
        $objects->expects(self::once())->method('offsetGet')->with(41)->willThrowException($failure);
        $collection = $collectionClass::init();
        $collection->setObjectCollection($objects);

        $this->expectExceptionObject($failure);
        $collection[41];
    }

    /**
     * @param class-string<DbCollection> $collectionClass Collection read by primary id
     * @param ?string $finder Object-layer business-key reader, none on the base getter
     * @throws HilosException When the fixture or read fails
     */
    #[DataProvider('readers')]
    public function testEmptyCollectionsKeepANullFirstAndLast(string $collectionClass, ?string $finder): void
    {
        $manual = $collectionClass::initEmpty();
        $automatic = $collectionClass::init();
        $automatic->setObjectCollection($this->loaderFor($collectionClass, $finder));

        self::assertNull($manual->first());
        self::assertNull($manual->last());
        self::assertNull($automatic->first());
        self::assertNull($automatic->last());
    }

    /**
     * A business key reads through the finder to the same wrapper the primary id reads.
     *
     * @param class-string<DbCollection> $collectionClass Collection with a business-key offset
     * @param string $finder Object-layer business-key reader
     * @param string $businessKey Existing business key
     * @throws HilosException When the fixture or read fails
     */
    #[DataProvider('businessKeyReaders')]
    public function testPresentBusinessKeyReadsTheItemOfItsPrimaryKey(
        string $collectionClass,
        string $finder,
        string $businessKey,
    ): void {
        $object = $this->objectFor($collectionClass);
        $objects = $this->loaderFor($collectionClass, $finder);
        $objects->expects(self::once())->method($finder)->with($businessKey)->willReturn($object);
        $objects->expects(self::once())->method('offsetGet')->with(41)->willReturn($object);
        $collection = $collectionClass::init();
        $collection->setObjectCollection($objects);

        $item = $collection[$businessKey];

        self::assertInstanceOf($collectionClass::DB_ITEM_CLASS, $item);
        self::assertSame($object, $item->getObject());
        self::assertSame($item, $collection[41]);
    }

    /**
     * @param class-string<DbCollection> $collectionClass Collection with a business-key offset
     * @param string $finder Object-layer business-key reader
     * @param string $businessKey Missing business key
     * @throws HilosException When the read refuses, as expected
     */
    #[DataProvider('businessKeyReaders')]
    public function testMissingBusinessKeyRefusesWithTheKey(
        string $collectionClass,
        string $finder,
        string $businessKey,
    ): void {
        $objects = $this->loaderFor($collectionClass, $finder);
        $objects->expects(self::once())->method($finder)->with($businessKey)->willReturn(null);
        $objects->expects(self::never())->method('offsetGet');
        $collection = $collectionClass::init();
        $collection->setObjectCollection($objects);

        $this->expectItemNotFound($collectionClass, "'{$businessKey}'");
        $collection[$businessKey];
    }

    /**
     * A numeric string is a business key: it goes to the finder, never to the primary loader.
     *
     * @param class-string<DbCollection> $collectionClass Collection with a business-key offset
     * @param string $finder Object-layer business-key reader
     * @param string $businessKey Unused business key of this collection
     * @throws HilosException When the read refuses, as expected
     */
    #[DataProvider('businessKeyReaders')]
    public function testNumericStringIsABusinessKeyNotAPrimaryId(
        string $collectionClass,
        string $finder,
        string $businessKey,
    ): void {
        $objects = $this->loaderFor($collectionClass, $finder);
        $objects->expects(self::once())->method($finder)->with('41')->willReturn(null);
        $objects->expects(self::never())->method('offsetGet');
        $collection = $collectionClass::init();
        $collection->setObjectCollection($objects);

        $this->expectItemNotFound($collectionClass, "'41'");
        $collection['41'];
    }

    /**
     * @param class-string<DbCollection> $collectionClass Collection with a business-key offset
     * @param string $finder Object-layer business-key reader
     * @param string $businessKey Business key whose read fails
     * @throws HilosException When the finder fails, as expected
     */
    #[DataProvider('businessKeyReaders')]
    public function testBusinessKeyReadPropagatesTheFinderFailure(
        string $collectionClass,
        string $finder,
        string $businessKey,
    ): void {
        $objects = $this->loaderFor($collectionClass, $finder);
        $failure = new DatabaseException('Required business-key read failed');
        $objects->expects(self::once())->method($finder)->with($businessKey)->willThrowException($failure);
        $objects->expects(self::never())->method('offsetGet');
        $collection = $collectionClass::init();
        $collection->setObjectCollection($objects);

        $this->expectExceptionObject($failure);
        $collection[$businessKey];
    }

    /** @return array<string, array{class-string<DbCollection>, ?string}> The base getter and its five overrides */
    public static function readers(): array
    {
        $readers = ['users' => [Users::class, null]];
        foreach (self::businessKeyReaders() as $name => [$collectionClass, $finder]) {
            $readers[$name] = [$collectionClass, $finder];
        }

        return $readers;
    }

    /** @return array<string, array{class-string<DbCollection>, string, string}> The five business-key overrides */
    public static function businessKeyReaders(): array
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
     * @param class-string<DbCollection> $collectionClass Collection the refusal names
     * @param string $exportedKey Key as the refusal exports it: `99`, `'us'`, `NULL`
     */
    private function expectItemNotFound(string $collectionClass, string $exportedKey): void
    {
        $this->expectException(ItemNotFoundException::class);
        $this->expectExceptionMessage("Item not found in collection [{$collectionClass}] for key [{$exportedKey}]");
    }

    /**
     * @param class-string<DbCollection> $collectionClass Collection whose Object loader is doubled
     * @param ?string $finder Business-key reader doubled beside the loader, none on the base getter
     * @return Objects&MockObject Object collection with only its loader and finder doubled
     */
    private function loaderFor(string $collectionClass, ?string $finder): Objects&MockObject
    {
        $methods = $finder === null ? ['offsetGet'] : ['offsetGet', $finder];

        return $this->getMockBuilder($collectionClass::OBJECT_COLLECTION_CLASS)->onlyMethods($methods)->getMock();
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
