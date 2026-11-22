<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\Actions\CartLineRules;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Hilos;
use Hilos\Auth\Session\DTO\SessionRotateSignalData;
use Hilos\Auth\Session\DTO\SessionsSweptSignalData;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\ActionFailureReason;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\HilosException;
use Hilos\Testing\IntegrationTest;

/**
 * The real shop schema and the guest cart actions under their library's claim; the rollback scope proves writes, not commit.
 *
 * Warehouses, products of either kind and their stock are rows written straight into their tables past their
 * libraries, before the first cart action of a case reads them; a case that lowers the stock after that does it
 * through the stock library. A session is only a number here: the cart has no foreign key on the session row. The
 * carts and the stock are read back past the ORM.
 */
#[IntegrationTest]
final class GuestCartsLibraryTest extends GuestCartsLibraryEnvironment
{
    /** The library's start declares the carts complete in its process. */
    public function testTheStartDeclaresTheCartsComplete(): void
    {
        $carts = Hilos::$db->sessionProductCarts->getObjectCollection();

        self::assertNotNull($carts);
        self::assertTrue($carts->isAllLoaded());
    }

    /** A stock item gets a line of one unit, one unit more on the same line, against the stock of every warehouse summed. */
    public function testAStockItemGetsALineAndOneUnitMore(): void
    {
        $bulbs = self::product(ProductKind::STOCK);
        self::stock($bulbs, self::warehouse(), 1);
        self::stock($bulbs, self::warehouse(), 1);

        Hilos::$db->sessionProductCarts->actions->add(101, $bulbs);
        $lines = self::storedLines(101);
        self::assertCount(1, $lines);
        self::assertSame(['productId' => $bulbs, 'quantity' => 1], self::withoutId($lines[0]));

        Hilos::$db->sessionProductCarts->actions->add(101, $bulbs);
        self::assertSame([['id' => $lines[0]['id'], 'productId' => $bulbs, 'quantity' => 2]], self::storedLines(101));
    }

    /** One unit more than the stock, and any unit of a stock item no warehouse keeps, is refused and leaves the cart as it was. */
    public function testAStockItemAboveItsStockOrOutOfStockIsRefused(): void
    {
        $bulbs = self::product(ProductKind::STOCK);
        self::stock($bulbs, self::warehouse(), 2);
        $seeds = self::product(ProductKind::STOCK);
        Hilos::$db->sessionProductCarts->actions->add(101, $bulbs);
        Hilos::$db->sessionProductCarts->actions->add(101, $bulbs);
        $stored = self::storedLines(101);

        self::assertRefused('Only 2 in stock', static fn () => Hilos::$db->sessionProductCarts->actions->add(101, $bulbs));
        self::assertRefused('This product is out of stock', static fn () => Hilos::$db->sessionProductCarts->actions->add(101, $seeds));
        self::assertRefused('This product does not exist', static fn () => Hilos::$db->sessionProductCarts->actions->add(101, $seeds + 1));

        self::assertSame($stored, self::storedLines(101));
    }

    /** A unique piece is put in once: no warehouse is a refusal, a second time changes nothing and is no refusal. */
    public function testAUniquePieceIsPutInOnce(): void
    {
        $vase = self::product(ProductKind::UNIQUE);
        $bowl = self::product(ProductKind::UNIQUE);
        self::stock($vase, self::warehouse(), 1);

        self::assertRefused('This piece is not in a warehouse yet', static fn () => Hilos::$db->sessionProductCarts->actions->add(101, $bowl));
        self::assertSame([], self::storedLines(101));

        Hilos::$db->sessionProductCarts->actions->add(101, $vase);
        $lines = self::storedLines(101);
        self::assertCount(1, $lines);
        self::assertSame(['productId' => $vase, 'quantity' => 1], self::withoutId($lines[0]));

        Hilos::$db->sessionProductCarts->actions->add(101, $vase);
        self::assertSame($lines, self::storedLines(101));
    }

    /** A step goes one unit up or down; down from one unit and up above the stock are refused. */
    public function testAStepGoesOneUnitUpOrDownWithinOneAndTheStock(): void
    {
        $bulbs = self::product(ProductKind::STOCK);
        self::stock($bulbs, self::warehouse(), 2);
        Hilos::$db->sessionProductCarts->actions->add(101, $bulbs);
        $line = self::storedLines(101)[0]['id'];

        self::assertRefused(
            'A cart line keeps one unit at least: Remove takes it out',
            static fn () => Hilos::$db->sessionProductCarts->actions->step(101, $bulbs, CartLineRules::STEP_DOWN),
        );
        Hilos::$db->sessionProductCarts->actions->step(101, $bulbs, CartLineRules::STEP_UP);
        self::assertSame([['id' => $line, 'productId' => $bulbs, 'quantity' => 2]], self::storedLines(101));

        self::assertRefused(
            'Only 2 in stock',
            static fn () => Hilos::$db->sessionProductCarts->actions->step(101, $bulbs, CartLineRules::STEP_UP),
        );
        Hilos::$db->sessionProductCarts->actions->step(101, $bulbs, CartLineRules::STEP_DOWN);
        self::assertSame([['id' => $line, 'productId' => $bulbs, 'quantity' => 1]], self::storedLines(101));
    }

    /** A line left above the stock when the stock fell may still go down, not up, and an exact number for it stays within the stock. */
    public function testALineAboveAFallenStockMayGoDown(): void
    {
        $kyiv = self::warehouse();
        $bulbs = self::product(ProductKind::STOCK);
        self::stock($bulbs, $kyiv, 3);
        Hilos::$db->sessionProductCarts->actions->add(101, $bulbs);
        Hilos::$db->sessionProductCarts->actions->setQuantity(101, $bulbs, 3);
        $line = self::storedLines(101)[0]['id'];

        ExecutionContext::run(
            new ExecutionFrame(agentId: AgentType::STOCK_LIBRARY),
            static fn () => Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $kyiv, 1),
        );

        self::assertRefused(
            'Only 1 in stock',
            static fn () => Hilos::$db->sessionProductCarts->actions->step(101, $bulbs, CartLineRules::STEP_UP),
        );
        self::assertRefused('Only 1 in stock', static fn () => Hilos::$db->sessionProductCarts->actions->setQuantity(101, $bulbs, 2));
        Hilos::$db->sessionProductCarts->actions->setQuantity(101, $bulbs, 3);
        self::assertSame([['id' => $line, 'productId' => $bulbs, 'quantity' => 3]], self::storedLines(101));

        Hilos::$db->sessionProductCarts->actions->step(101, $bulbs, CartLineRules::STEP_DOWN);
        self::assertSame([['id' => $line, 'productId' => $bulbs, 'quantity' => 2]], self::storedLines(101));
    }

    /** A step and an exact number belong to a stock item in the cart: a unique piece, no line and no product are refused. */
    public function testAStepOrANumberOfAUniquePieceOrOfNoLineIsRefused(): void
    {
        $vase = self::product(ProductKind::UNIQUE);
        self::stock($vase, self::warehouse(), 1);
        $bulbs = self::product(ProductKind::STOCK);
        self::stock($bulbs, self::warehouse(), 5);
        Hilos::$db->sessionProductCarts->actions->add(101, $vase);
        $stored = self::storedLines(101);

        $unique = 'A unique piece has no quantity: it is one of a kind';
        self::assertRefused($unique, static fn () => Hilos::$db->sessionProductCarts->actions->step(101, $vase, CartLineRules::STEP_UP));
        self::assertRefused($unique, static fn () => Hilos::$db->sessionProductCarts->actions->setQuantity(101, $vase, 1));
        self::assertRefused(
            'This product is not in the cart',
            static fn () => Hilos::$db->sessionProductCarts->actions->step(101, $bulbs, CartLineRules::STEP_UP),
        );
        self::assertRefused(
            'This product is not in the cart',
            static fn () => Hilos::$db->sessionProductCarts->actions->setQuantity(101, $bulbs, 2),
        );
        self::assertRefused(
            'This product does not exist',
            static fn () => Hilos::$db->sessionProductCarts->actions->step(101, $bulbs + 1, CartLineRules::STEP_UP),
        );

        self::assertSame($stored, self::storedLines(101));
    }

    /** An exact number between one unit and the stock is set; zero and above the stock are refused, the same number writes nothing. */
    public function testAnExactNumberIsSetWithinOneAndTheStock(): void
    {
        $bulbs = self::product(ProductKind::STOCK);
        self::stock($bulbs, self::warehouse(), 5);
        Hilos::$db->sessionProductCarts->actions->add(101, $bulbs);
        $line = self::storedLines(101)[0]['id'];

        Hilos::$db->sessionProductCarts->actions->setQuantity(101, $bulbs, 4);
        self::assertSame([['id' => $line, 'productId' => $bulbs, 'quantity' => 4]], self::storedLines(101));

        self::assertRefused(
            'A cart line keeps one unit at least: Remove takes it out',
            static fn () => Hilos::$db->sessionProductCarts->actions->setQuantity(101, $bulbs, 0),
        );
        self::assertRefused('Only 5 in stock', static fn () => Hilos::$db->sessionProductCarts->actions->setQuantity(101, $bulbs, 6));
        Hilos::$db->sessionProductCarts->actions->setQuantity(101, $bulbs, 4);

        self::assertSame([['id' => $line, 'productId' => $bulbs, 'quantity' => 4]], self::storedLines(101));
    }

    /** Remove takes the line of a product out of one cart; a product not in it is left as it is. */
    public function testRemoveTakesTheLineOut(): void
    {
        $bulbs = self::product(ProductKind::STOCK);
        self::stock($bulbs, self::warehouse(), 5);
        $vase = self::product(ProductKind::UNIQUE);
        self::stock($vase, self::warehouse(), 1);
        Hilos::$db->sessionProductCarts->actions->add(101, $bulbs);
        Hilos::$db->sessionProductCarts->actions->add(101, $vase);
        Hilos::$db->sessionProductCarts->actions->add(102, $bulbs);
        $other = self::storedLines(102);

        Hilos::$db->sessionProductCarts->actions->remove(101, $bulbs);
        $lines = self::storedLines(101);
        self::assertCount(1, $lines);
        self::assertSame(['productId' => $vase, 'quantity' => 1], self::withoutId($lines[0]));

        Hilos::$db->sessionProductCarts->actions->remove(101, $bulbs);
        self::assertSame($lines, self::storedLines(101));
        self::assertSame($other, self::storedLines(102));
    }

    /** The sweep frame removes every line of the named sessions and none of another; no line of them removes nothing. */
    public function testTheSweepFrameRemovesTheCartsOfTheSweptSessions(): void
    {
        $bulbs = self::product(ProductKind::STOCK);
        self::stock($bulbs, self::warehouse(), 5);
        $vase = self::product(ProductKind::UNIQUE);
        self::stock($vase, self::warehouse(), 1);
        Hilos::$db->sessionProductCarts->actions->add(101, $bulbs);
        Hilos::$db->sessionProductCarts->actions->add(101, $vase);
        Hilos::$db->sessionProductCarts->actions->add(102, $bulbs);
        Hilos::$db->sessionProductCarts->actions->add(103, $bulbs);
        $kept = self::storedLines(102);

        $this->sweep([101, 103, 104]);

        self::assertSame([], self::storedLines(101));
        self::assertSame([], self::storedLines(103));
        self::assertSame($kept, self::storedLines(102));

        $this->sweep([104]);
        self::assertSame($kept, self::storedLines(102));
        self::assertSame(0, Hilos::$db->sessionProductCarts->actions->deleteForSessions([]));
    }

    /** A sweep frame carrying another payload is a contract fault, and no cart is touched. */
    public function testASweepFrameWithAnotherPayloadIsRefused(): void
    {
        $bulbs = self::product(ProductKind::STOCK);
        self::stock($bulbs, self::warehouse(), 5);
        Hilos::$db->sessionProductCarts->actions->add(101, $bulbs);
        $stored = self::storedLines(101);

        try {
            $this->carts()->onSignalAgent(
                new AgentSignalData(new SessionRotateSignalData('rotate-ticket')),
                '',
                HilosSignalConstants::HILOS_SESSIONS_SWEPT,
            );
            self::fail('The sweep branch must narrow its payload');
        } catch (InvalidAgentSignalPayloadException) {
            self::assertSame($stored, self::storedLines(101));
        }
    }

    /** A cart ask carrying another payload is a contract fault: the library does not guess the session. */
    public function testACartAskWithAnotherPayloadIsRefused(): void
    {
        $this->expectException(InvalidAgentSignalPayloadException::class);

        $this->carts()->onSignalAgent(
            new AgentSignalData(new SessionRotateSignalData('rotate-ticket')),
            '',
            EcommerceShopSignalConstants::GUEST_CART_SEND,
        );
    }

    /** No cart action writes the stock. */
    public function testTheCartLeavesTheStockAsItWas(): void
    {
        $bulbs = self::product(ProductKind::STOCK);
        self::stock($bulbs, self::warehouse(), 3);
        $vase = self::product(ProductKind::UNIQUE);
        self::stock($vase, self::warehouse(), 1);
        $stock = self::storedStock();

        Hilos::$db->sessionProductCarts->actions->add(101, $bulbs);
        Hilos::$db->sessionProductCarts->actions->add(101, $vase);
        Hilos::$db->sessionProductCarts->actions->step(101, $bulbs, CartLineRules::STEP_UP);
        Hilos::$db->sessionProductCarts->actions->setQuantity(101, $bulbs, 3);
        Hilos::$db->sessionProductCarts->actions->remove(101, $vase);
        $this->sweep([101]);

        self::assertSame($stock, self::storedStock());
    }

    /** A process other than the library may neither add a line nor change or remove one; a call that changes nothing asks no right. */
    public function testAWriterOtherThanTheLibraryIsRefused(): void
    {
        $bulbs = self::product(ProductKind::STOCK);
        self::stock($bulbs, self::warehouse(), 5);
        $vase = self::product(ProductKind::UNIQUE);
        self::stock($vase, self::warehouse(), 1);
        Hilos::$db->sessionProductCarts->actions->add(101, $bulbs);
        Hilos::$db->sessionProductCarts->actions->add(101, $vase);
        $stored = self::storedLines(101);

        self::assertRefusedToAnotherWriter(
            CreateNotAllowedException::class,
            static fn () => Hilos::$db->sessionProductCarts->actions->add(102, $bulbs),
        );
        self::assertRefusedToAnotherWriter(
            WriteNotAllowedException::class,
            static fn () => Hilos::$db->sessionProductCarts->actions->add(101, $bulbs),
        );
        self::assertRefusedToAnotherWriter(
            WriteNotAllowedException::class,
            static fn () => Hilos::$db->sessionProductCarts->actions->step(101, $bulbs, CartLineRules::STEP_UP),
        );
        self::assertRefusedToAnotherWriter(
            WriteNotAllowedException::class,
            static fn () => Hilos::$db->sessionProductCarts->actions->setQuantity(101, $bulbs, 3),
        );
        self::assertRefusedToAnotherWriter(
            WriteNotAllowedException::class,
            static fn () => Hilos::$db->sessionProductCarts->actions->remove(101, $bulbs),
        );
        self::assertRefusedToAnotherWriter(
            WriteNotAllowedException::class,
            static fn () => Hilos::$db->sessionProductCarts->actions->deleteForSessions([101]),
        );

        ExecutionContext::run(new ExecutionFrame(agentId: AgentType::ECOMMERCE_SHOP), static function () use ($bulbs, $vase): void {
            Hilos::$db->sessionProductCarts->actions->add(101, $vase);
            Hilos::$db->sessionProductCarts->actions->setQuantity(101, $bulbs, 1);
            Hilos::$db->sessionProductCarts->actions->remove(102, $bulbs);
            self::assertSame(0, Hilos::$db->sessionProductCarts->actions->deleteForSessions([102]));
        });

        self::assertSame($stored, self::storedLines(101));
    }

    /**
     * Hands the library the frame the sessions library sends after a sweep.
     *
     * @param list<int> $sessionIds Numbers of the swept session rows
     */
    private function sweep(array $sessionIds): void
    {
        $tokens = [];
        foreach ($sessionIds as $sessionId) {
            $tokens[] = 'swept-' . $sessionId;
        }
        $this->carts()->onSignalAgent(
            new AgentSignalData(new SessionsSweptSignalData($tokens, $sessionIds)),
            '',
            HilosSignalConstants::HILOS_SESSIONS_SWEPT,
        );
    }

    /**
     * Runs a write that must be refused, and checks the refusal reaches the person word for word.
     *
     * @param string $sentence The sentence the person is shown
     * @param callable(): mixed $write Write to refuse
     */
    private static function assertRefused(string $sentence, callable $write): void
    {
        try {
            $write();
        } catch (ValidationException $e) {
            self::assertSame(ValidationException::class, $e::class);
            self::assertSame($sentence, $e->getMessage());
            self::assertSame($sentence, ActionFailureReason::forClient($e));

            return;
        }

        self::fail("Expected the refusal: {$sentence}");
    }

    /**
     * Runs a write as the shop's app agent, which does not own the carts, and checks it is refused.
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

        self::fail("Expected {$expected} for a writer other than the guest carts library");
    }

    /**
     * @param int $sessionId Number of the session
     * @return list<array{id: int, productId: int, quantity: int}> The lines of the session's cart read past the ORM, in
     *     the order they were added; empty when it has none
     */
    private static function storedLines(int $sessionId): array
    {
        $lines = [];
        foreach (Database::sql(
            'SELECT id, product_id, quantity FROM session_product_cart WHERE session_id = ? ORDER BY id',
            [$sessionId],
        )->rows() as $row) {
            $lines[] = ['id' => (int)$row['id'], 'productId' => (int)$row['product_id'], 'quantity' => (int)$row['quantity']];
        }

        return $lines;
    }

    /**
     * @param array{id: int, productId: int, quantity: int} $line A stored cart line
     * @return array{productId: int, quantity: int} The line without the id the database gave it
     */
    private static function withoutId(array $line): array
    {
        unset($line['id']);

        return $line;
    }

    /**
     * @return list<array<string, mixed>> Every stored stock row, read past the ORM in the order of its id
     */
    private static function storedStock(): array
    {
        return Database::sql('SELECT id, product_id, warehouse_id, units FROM product_warehouse_stock ORDER BY id')->rows();
    }
}
