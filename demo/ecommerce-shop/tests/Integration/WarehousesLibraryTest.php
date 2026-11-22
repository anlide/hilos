<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\WarehousesLibraryAgent;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Action\ActionFailureReason;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\HilosException;
use Hilos\Testing\IntegrationTest;
use Hilos\Testing\RollbackIntegrationTestCase;

/** Mounts the shop before the managed rollback scope and writes warehouses as their library does. */
abstract class WarehousesLibraryEnvironment extends RollbackIntegrationTestCase
{
    use EmptyCatalog;

    private ?WarehousesLibraryAgent $library = null;
    private ?int $executionToken = null;

    /**
     * Uses the migrated test database without taking over an already mounted project context.
     *
     * What the ORM loaded earlier in the process holds the seeded catalog, so it is forgotten here,
     * before the scope: forgetting refuses inside an open transaction.
     */
    protected function prepareIntegrationEnvironment(): void
    {
        Database::initialize(initHilos: Hilos::$db === null);
        Hilos::$db->forgetLoadedDataForTest();
        $this->library = new WarehousesLibraryAgent();
        OwnershipDeclaration::claimAll($this->library);
        $this->executionToken = ExecutionContext::push(new ExecutionFrame(agentId: $this->library->getId()));
    }

    /** Starts the case from an empty catalog; the rollback brings the seed back. */
    protected function setUpInTransaction(): void
    {
        self::emptyCatalog();
    }

    /** Releases the library's claim and restores the caller after the harness rolls back. */
    protected function releaseIntegrationEnvironment(): void
    {
        if ($this->library !== null) {
            TruthSourceRegistry::unregisterAgent($this->library->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->library->getId()));
            $this->library = null;
        }
        if ($this->executionToken !== null) {
            ExecutionContext::pop($this->executionToken);
            $this->executionToken = null;
        }
    }
}

/** The real shop schema and the warehouse actions under their library's claim; the rollback scope proves writes, not commit. */
#[IntegrationTest]
final class WarehousesLibraryTest extends WarehousesLibraryEnvironment
{
    use CatalogFixtureRows;

    /** A new warehouse is stored with its name and address trimmed, under the id the action returns. */
    public function testCreateStoresTheTrimmedNameAndAddress(): void
    {
        $warehouse = Hilos::$db->warehouses->actions->create('  Kyiv · Podil  ', "\tVolodymyrska St, 1 \n");

        self::assertNotNull($warehouse->id);
        self::assertSame([['name' => 'Kyiv · Podil', 'address' => 'Volodymyrska St, 1']], self::storedRows($warehouse->id));
    }

    /** An edit writes the new name and the new address. */
    public function testUpdateStoresTheNewNameAndAddress(): void
    {
        $id = Hilos::$db->warehouses->actions->create('Kyiv · Podil', 'Volodymyrska St, 1')->id;

        Hilos::$db->warehouses[$id]->actions->update('Lviv · Rynok', 'Rynok Sq, 10');

        self::assertSame([['name' => 'Lviv · Rynok', 'address' => 'Rynok Sq, 10']], self::storedRows($id));
    }

    /** A deleted warehouse leaves no row behind. */
    public function testDeleteRemovesTheRow(): void
    {
        $id = Hilos::$db->warehouses->actions->create('Kyiv · Podil', 'Volodymyrska St, 1')->id;

        Hilos::$db->warehouses[$id]->actions->delete();

        self::assertSame([], self::storedRows($id));
    }

    /**
     * A warehouse that keeps units of a stock item or a unique piece is refused, and both the row and the
     * collection keep it; once its stock row is gone, the same warehouse is deleted.
     *
     * The stock row was written first, so the database's reference from the stock is what refuses.
     */
    public function testAWarehouseThatKeepsStockIsRefusedUntilItsStockIsGone(): void
    {
        $bulkWarehouse = Hilos::$db->warehouses->actions->create('Kyiv · Podil', 'Volodymyrska St, 1')->id;
        $pieceWarehouse = Hilos::$db->warehouses->actions->create('Lviv · Rynok', 'Rynok Sq, 10')->id;
        $bulbs = self::product(ProductKind::STOCK);
        self::stock($bulbs, $bulkWarehouse, 12);
        $vase = self::product(ProductKind::UNIQUE);
        self::stock($vase, $pieceWarehouse, 1);

        foreach ([$bulkWarehouse, $pieceWarehouse] as $id) {
            $stored = self::storedRows($id);
            self::assertRefused(
                ValidationException::class,
                'This warehouse keeps stock and cannot be deleted',
                static fn () => Hilos::$db->warehouses[$id]->actions->delete(),
            );
            self::assertSame($stored, self::storedRows($id));
            self::assertTrue(isset(Hilos::$db->warehouses[$id]));
        }

        Database::sql('DELETE FROM product_warehouse_stock WHERE product_id = ?', [$bulbs]);
        Hilos::$db->warehouses[$bulkWarehouse]->actions->delete();

        self::assertSame([], self::storedRows($bulkWarehouse));
        self::assertFalse(isset(Hilos::$db->warehouses[$bulkWarehouse]));
    }

    /** A name or an address of nothing but spaces is refused in words the person reads, and nothing is stored. */
    public function testAnEmptyNameOrAddressIsRefusedAndNothingIsStored(): void
    {
        $before = self::storedCount();

        self::assertRefused(
            EmptyValueException::class,
            'Warehouse name cannot be empty',
            static fn () => Hilos::$db->warehouses->actions->create('   ', 'Volodymyrska St, 1'),
        );
        self::assertRefused(
            EmptyValueException::class,
            'Warehouse address cannot be empty',
            static fn () => Hilos::$db->warehouses->actions->create('Kyiv · Podil', '   '),
        );

        self::assertSame($before, self::storedCount());
    }

    /** A name longer than 100 characters or an address longer than 255 is refused, and nothing is stored. */
    public function testATooLongNameOrAddressIsRefused(): void
    {
        $before = self::storedCount();

        self::assertRefused(
            ValueTooLongException::class,
            'Warehouse name exceeds maximum length of 100 characters',
            static fn () => Hilos::$db->warehouses->actions->create(str_repeat('n', 101), 'Volodymyrska St, 1'),
        );
        self::assertRefused(
            ValueTooLongException::class,
            'Warehouse address exceeds maximum length of 255 characters',
            static fn () => Hilos::$db->warehouses->actions->create('Kyiv · Podil', str_repeat('a', 256)),
        );

        self::assertSame($before, self::storedCount());
    }

    /** A name already taken is refused on creation even when written in another case. */
    public function testATakenNameIsRefusedOnCreateInAnyCase(): void
    {
        Hilos::$db->warehouses->actions->create('Kyiv · Podil', 'Volodymyrska St, 1');
        $before = self::storedCount();

        self::assertRefused(
            DuplicateValueException::class,
            'A warehouse with this name already exists',
            static fn () => Hilos::$db->warehouses->actions->create('kyiv · podil', 'Rynok Sq, 10'),
        );

        self::assertSame($before, self::storedCount());
    }

    /** Renaming into a taken name is refused, and both the row and the collection keep the old name and address. */
    public function testRenamingIntoATakenNameIsRefusedAndKeepsTheOldValues(): void
    {
        Hilos::$db->warehouses->actions->create('Kyiv · Podil', 'Volodymyrska St, 1');
        $id = Hilos::$db->warehouses->actions->create('Lviv · Rynok', 'Rynok Sq, 10')->id;

        self::assertRefused(
            DuplicateValueException::class,
            'A warehouse with this name already exists',
            static fn () => Hilos::$db->warehouses[$id]->actions->update('Kyiv · Podil', 'Svobody Ave, 2'),
        );

        self::assertSame([['name' => 'Lviv · Rynok', 'address' => 'Rynok Sq, 10']], self::storedRows($id));
        self::assertSame('Lviv · Rynok', Hilos::$db->warehouses[$id]->name);
        self::assertSame('Rynok Sq, 10', Hilos::$db->warehouses[$id]->address);
    }

    /** An edit with a good name and an empty address is refused whole: neither field of the row or of the collection moves. */
    public function testAnEditWithAGoodNameAndAnEmptyAddressKeepsTheOldValues(): void
    {
        $id = Hilos::$db->warehouses->actions->create('Lviv · Rynok', 'Rynok Sq, 10')->id;

        self::assertRefused(
            EmptyValueException::class,
            'Warehouse address cannot be empty',
            static fn () => Hilos::$db->warehouses[$id]->actions->update('Kyiv · Center', '   '),
        );

        self::assertSame([['name' => 'Lviv · Rynok', 'address' => 'Rynok Sq, 10']], self::storedRows($id));
        self::assertSame('Lviv · Rynok', Hilos::$db->warehouses[$id]->name);
        self::assertSame('Rynok Sq, 10', Hilos::$db->warehouses[$id]->address);
    }

    /** An agent other than the library may neither create a warehouse nor edit or delete one. */
    public function testAWriterOtherThanTheLibraryIsRefused(): void
    {
        $id = Hilos::$db->warehouses->actions->create('Kyiv · Podil', 'Volodymyrska St, 1')->id;
        $before = self::storedCount();

        self::assertRefusedToAnotherWriter(
            CreateNotAllowedException::class,
            static fn () => Hilos::$db->warehouses->actions->create('Lviv · Rynok', 'Rynok Sq, 10'),
        );
        self::assertRefusedToAnotherWriter(
            WriteNotAllowedException::class,
            static fn () => Hilos::$db->warehouses[$id]->actions->update('Lviv · Rynok', 'Rynok Sq, 10'),
        );
        self::assertRefusedToAnotherWriter(
            WriteNotAllowedException::class,
            static fn () => Hilos::$db->warehouses[$id]->actions->delete(),
        );

        self::assertSame($before, self::storedCount());
        self::assertSame([['name' => 'Kyiv · Podil', 'address' => 'Volodymyrska St, 1']], self::storedRows($id));
    }

    /**
     * Runs a write that must be refused, and checks the refusal reaches the person word for word.
     *
     * @param class-string<ValidationException> $expected Exact refusal class
     * @param string $sentence The sentence the person is shown
     * @param callable(): mixed $write Write to refuse
     */
    private static function assertRefused(string $expected, string $sentence, callable $write): void
    {
        try {
            $write();
        } catch (ValidationException $e) {
            self::assertSame($expected, $e::class);
            self::assertSame($sentence, $e->getMessage());
            self::assertSame($sentence, ActionFailureReason::forClient($e));

            return;
        }

        self::fail("Expected {$expected}: {$sentence}");
    }

    /**
     * Runs a write as the shop's app agent, which does not own the warehouses, and checks it is refused.
     *
     * @param class-string<HilosException> $expected Exact refusal class
     * @param callable(): mixed $write Write to refuse
     */
    private static function assertRefusedToAnotherWriter(string $expected, callable $write): void
    {
        try {
            ExecutionContext::run(new ExecutionFrame(agentId: AgentType::ECOMMERCE_SHOP), $write);
        } catch (HilosException $e) {
            self::assertSame($expected, $e::class);

            return;
        }

        self::fail("Expected {$expected} for a writer other than the warehouses library");
    }

    /**
     * @param int $id Warehouse id
     * @return list<array<string, mixed>> The stored warehouse read past the ORM; empty when there is none
     */
    private static function storedRows(int $id): array
    {
        return Database::sql('SELECT name, address FROM warehouse WHERE id = ?', [$id])->rows();
    }

    /**
     * @return int Number of stored warehouses, read past the ORM
     */
    private static function storedCount(): int
    {
        return (int)Database::sql('SELECT COUNT(*) AS stored FROM warehouse')->field('stored');
    }
}
