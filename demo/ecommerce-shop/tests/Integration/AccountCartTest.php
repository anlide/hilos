<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\Hilos\UserAgent;
use Demo\EcommerceShop\Agents\VouchersLibraryAgent;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\Actions\CartLineRules;
use Demo\EcommerceShop\Database\Actions\CartVoucherRules;
use Demo\EcommerceShop\Database\Actions\VoucherFields;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Action\ActionFailureReason;
use Hilos\Core\Agent\AbstractAgent;
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

/**
 * The real shop schema and the cart actions of a person with an account under the person's agent; the rollback scope
 * proves writes, not commit.
 *
 * Warehouses, products of either kind and their stock are rows written straight into their tables past their
 * libraries, before the first cart action of a case reads them; a case that lowers the stock after that does it
 * through the stock library. The cart of the other person is written past that person's agent. The carts and the
 * stock are read back past the ORM.
 */
#[IntegrationTest]
final class AccountCartTest extends AccountCartEnvironment
{
    /** A stock item gets a line of one unit, one unit more on the same line, against the stock of every warehouse summed. */
    public function testAStockItemGetsALineAndOneUnitMore(): void
    {
        $person = $this->personId();
        $bulbs = self::product(ProductKind::STOCK);
        self::stock($bulbs, self::warehouse(), 1);
        self::stock($bulbs, self::warehouse(), 1);

        Hilos::$db->userProductCarts->actions->add($person, $bulbs);
        $lines = self::storedLines($person);
        self::assertCount(1, $lines);
        self::assertSame(['productId' => $bulbs, 'quantity' => 1], self::withoutId($lines[0]));

        Hilos::$db->userProductCarts->actions->add($person, $bulbs);
        self::assertSame([['id' => $lines[0]['id'], 'productId' => $bulbs, 'quantity' => 2]], self::storedLines($person));
    }

    /** One unit more than the stock, and any unit of a stock item no warehouse keeps, is refused and leaves the cart as it was. */
    public function testAStockItemAboveItsStockOrOutOfStockIsRefused(): void
    {
        $person = $this->personId();
        $bulbs = self::product(ProductKind::STOCK);
        self::stock($bulbs, self::warehouse(), 2);
        $seeds = self::product(ProductKind::STOCK);
        Hilos::$db->userProductCarts->actions->add($person, $bulbs);
        Hilos::$db->userProductCarts->actions->add($person, $bulbs);
        $stored = self::storedLines($person);

        self::assertRefused('Only 2 in stock', static fn () => Hilos::$db->userProductCarts->actions->add($person, $bulbs));
        self::assertRefused('This product is out of stock', static fn () => Hilos::$db->userProductCarts->actions->add($person, $seeds));
        self::assertRefused('This product does not exist', static fn () => Hilos::$db->userProductCarts->actions->add($person, $seeds + 1));

        self::assertSame($stored, self::storedLines($person));
    }

    /** A unique piece is put in once: no warehouse is a refusal, a second time changes nothing and is no refusal. */
    public function testAUniquePieceIsPutInOnce(): void
    {
        $person = $this->personId();
        $vase = self::product(ProductKind::UNIQUE);
        $bowl = self::product(ProductKind::UNIQUE);
        self::stock($vase, self::warehouse(), 1);

        self::assertRefused('This piece is not in a warehouse yet', static fn () => Hilos::$db->userProductCarts->actions->add($person, $bowl));
        self::assertSame([], self::storedLines($person));

        Hilos::$db->userProductCarts->actions->add($person, $vase);
        $lines = self::storedLines($person);
        self::assertCount(1, $lines);
        self::assertSame(['productId' => $vase, 'quantity' => 1], self::withoutId($lines[0]));

        Hilos::$db->userProductCarts->actions->add($person, $vase);
        self::assertSame($lines, self::storedLines($person));
    }

    /** A step goes one unit up or down; down from one unit and up above the stock are refused. */
    public function testAStepGoesOneUnitUpOrDownWithinOneAndTheStock(): void
    {
        $person = $this->personId();
        $bulbs = self::product(ProductKind::STOCK);
        self::stock($bulbs, self::warehouse(), 2);
        Hilos::$db->userProductCarts->actions->add($person, $bulbs);
        $line = self::storedLines($person)[0]['id'];

        self::assertRefused(
            'A cart line keeps one unit at least: Remove takes it out',
            static fn () => Hilos::$db->userProductCarts->actions->step($person, $bulbs, CartLineRules::STEP_DOWN),
        );
        Hilos::$db->userProductCarts->actions->step($person, $bulbs, CartLineRules::STEP_UP);
        self::assertSame([['id' => $line, 'productId' => $bulbs, 'quantity' => 2]], self::storedLines($person));

        self::assertRefused(
            'Only 2 in stock',
            static fn () => Hilos::$db->userProductCarts->actions->step($person, $bulbs, CartLineRules::STEP_UP),
        );
        Hilos::$db->userProductCarts->actions->step($person, $bulbs, CartLineRules::STEP_DOWN);
        self::assertSame([['id' => $line, 'productId' => $bulbs, 'quantity' => 1]], self::storedLines($person));
    }

    /** A line left above the stock when the stock fell may still go down, not up, and an exact number for it stays within the stock. */
    public function testALineAboveAFallenStockMayGoDown(): void
    {
        $person = $this->personId();
        $kyiv = self::warehouse();
        $bulbs = self::product(ProductKind::STOCK);
        self::stock($bulbs, $kyiv, 3);
        Hilos::$db->userProductCarts->actions->add($person, $bulbs);
        Hilos::$db->userProductCarts->actions->setQuantity($person, $bulbs, 3);
        $line = self::storedLines($person)[0]['id'];

        ExecutionContext::run(
            new ExecutionFrame(agentId: AgentType::STOCK_LIBRARY),
            static fn () => Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $kyiv, 1),
        );

        self::assertRefused(
            'Only 1 in stock',
            static fn () => Hilos::$db->userProductCarts->actions->step($person, $bulbs, CartLineRules::STEP_UP),
        );
        self::assertRefused('Only 1 in stock', static fn () => Hilos::$db->userProductCarts->actions->setQuantity($person, $bulbs, 2));
        Hilos::$db->userProductCarts->actions->setQuantity($person, $bulbs, 3);
        self::assertSame([['id' => $line, 'productId' => $bulbs, 'quantity' => 3]], self::storedLines($person));

        Hilos::$db->userProductCarts->actions->step($person, $bulbs, CartLineRules::STEP_DOWN);
        self::assertSame([['id' => $line, 'productId' => $bulbs, 'quantity' => 2]], self::storedLines($person));
    }

    /** A step and an exact number belong to a stock item in the cart: a unique piece, no line and no product are refused. */
    public function testAStepOrANumberOfAUniquePieceOrOfNoLineIsRefused(): void
    {
        $person = $this->personId();
        $vase = self::product(ProductKind::UNIQUE);
        self::stock($vase, self::warehouse(), 1);
        $bulbs = self::product(ProductKind::STOCK);
        self::stock($bulbs, self::warehouse(), 5);
        Hilos::$db->userProductCarts->actions->add($person, $vase);
        $stored = self::storedLines($person);

        $unique = 'A unique piece has no quantity: it is one of a kind';
        self::assertRefused($unique, static fn () => Hilos::$db->userProductCarts->actions->step($person, $vase, CartLineRules::STEP_UP));
        self::assertRefused($unique, static fn () => Hilos::$db->userProductCarts->actions->setQuantity($person, $vase, 1));
        self::assertRefused(
            'This product is not in the cart',
            static fn () => Hilos::$db->userProductCarts->actions->step($person, $bulbs, CartLineRules::STEP_UP),
        );
        self::assertRefused(
            'This product is not in the cart',
            static fn () => Hilos::$db->userProductCarts->actions->setQuantity($person, $bulbs, 2),
        );
        self::assertRefused(
            'This product does not exist',
            static fn () => Hilos::$db->userProductCarts->actions->step($person, $bulbs + 1, CartLineRules::STEP_UP),
        );

        self::assertSame($stored, self::storedLines($person));
    }

    /** An exact number between one unit and the stock is set; zero and above the stock are refused, the same number writes nothing. */
    public function testAnExactNumberIsSetWithinOneAndTheStock(): void
    {
        $person = $this->personId();
        $bulbs = self::product(ProductKind::STOCK);
        self::stock($bulbs, self::warehouse(), 5);
        Hilos::$db->userProductCarts->actions->add($person, $bulbs);
        $line = self::storedLines($person)[0]['id'];

        Hilos::$db->userProductCarts->actions->setQuantity($person, $bulbs, 4);
        self::assertSame([['id' => $line, 'productId' => $bulbs, 'quantity' => 4]], self::storedLines($person));

        self::assertRefused(
            'A cart line keeps one unit at least: Remove takes it out',
            static fn () => Hilos::$db->userProductCarts->actions->setQuantity($person, $bulbs, 0),
        );
        self::assertRefused('Only 5 in stock', static fn () => Hilos::$db->userProductCarts->actions->setQuantity($person, $bulbs, 6));
        Hilos::$db->userProductCarts->actions->setQuantity($person, $bulbs, 4);

        self::assertSame([['id' => $line, 'productId' => $bulbs, 'quantity' => 4]], self::storedLines($person));
    }

    /** Remove takes the line of a product out of the person's cart; a product not in it is left as it is, and so is another person's cart. */
    public function testRemoveTakesTheLineOut(): void
    {
        $person = $this->personId();
        $bulbs = self::product(ProductKind::STOCK);
        self::stock($bulbs, self::warehouse(), 5);
        $vase = self::product(ProductKind::UNIQUE);
        self::stock($vase, self::warehouse(), 1);
        Hilos::$db->userProductCarts->actions->add($person, $bulbs);
        Hilos::$db->userProductCarts->actions->add($person, $vase);
        self::line($this->otherPersonId(), $bulbs, 1);
        $other = self::storedLines($this->otherPersonId());

        Hilos::$db->userProductCarts->actions->remove($person, $bulbs);
        $lines = self::storedLines($person);
        self::assertCount(1, $lines);
        self::assertSame(['productId' => $vase, 'quantity' => 1], self::withoutId($lines[0]));

        Hilos::$db->userProductCarts->actions->remove($person, $bulbs);
        self::assertSame($lines, self::storedLines($person));
        self::assertSame($other, self::storedLines($this->otherPersonId()));
    }

    /** Erasing the cart removes every line of the person and none of another; an empty cart removes nothing and asks no right. */
    public function testErasingTheCartRemovesEveryLineOfThePersonAndNoneOfAnother(): void
    {
        $person = $this->personId();
        $bulbs = self::product(ProductKind::STOCK);
        self::stock($bulbs, self::warehouse(), 5);
        $vase = self::product(ProductKind::UNIQUE);
        self::stock($vase, self::warehouse(), 1);
        Hilos::$db->userProductCarts->actions->add($person, $bulbs);
        Hilos::$db->userProductCarts->actions->add($person, $vase);
        self::line($this->otherPersonId(), $bulbs, 2);
        $other = self::storedLines($this->otherPersonId());

        self::assertSame(2, Hilos::$db->userProductCarts->actions->deleteForUser($person));

        self::assertSame([], self::storedLines($person));
        self::assertSame($other, self::storedLines($this->otherPersonId()));
        ExecutionContext::run(
            new ExecutionFrame(agentId: AgentType::GUEST_CARTS_LIBRARY),
            static fn () => self::assertSame(0, Hilos::$db->userProductCarts->actions->deleteForUser($person)),
        );
    }

    /** No cart action writes the stock. */
    public function testTheCartLeavesTheStockAsItWas(): void
    {
        $person = $this->personId();
        $bulbs = self::product(ProductKind::STOCK);
        self::stock($bulbs, self::warehouse(), 3);
        $vase = self::product(ProductKind::UNIQUE);
        self::stock($vase, self::warehouse(), 1);
        $stock = self::storedStock();

        Hilos::$db->userProductCarts->actions->add($person, $bulbs);
        Hilos::$db->userProductCarts->actions->add($person, $vase);
        Hilos::$db->userProductCarts->actions->step($person, $bulbs, CartLineRules::STEP_UP);
        Hilos::$db->userProductCarts->actions->setQuantity($person, $bulbs, 3);
        Hilos::$db->userProductCarts->actions->remove($person, $vase);
        Hilos::$db->userProductCarts->actions->deleteForUser($person);

        self::assertSame($stock, self::storedStock());
    }

    /**
     * Neither the agent of another person nor the guest carts library may add a line to the person's cart or change or
     * remove one; a call that changes nothing asks no right.
     */
    public function testAWriterOtherThanThePersonsAgentIsRefused(): void
    {
        $person = $this->personId();
        $bulbs = self::product(ProductKind::STOCK);
        self::stock($bulbs, self::warehouse(), 5);
        $vase = self::product(ProductKind::UNIQUE);
        self::stock($vase, self::warehouse(), 1);
        $seeds = self::product(ProductKind::STOCK);
        self::stock($seeds, self::warehouse(), 5);
        Hilos::$db->userProductCarts->actions->add($person, $bulbs);
        Hilos::$db->userProductCarts->actions->add($person, $vase);
        $stored = self::storedLines($person);

        foreach ([new UserAgent((string)$this->otherPersonId())->getId(), AgentType::GUEST_CARTS_LIBRARY] as $writer) {
            self::assertRefusedTo(
                $writer,
                CreateNotAllowedException::class,
                static fn () => Hilos::$db->userProductCarts->actions->add($person, $seeds),
            );
            self::assertRefusedTo(
                $writer,
                WriteNotAllowedException::class,
                static fn () => Hilos::$db->userProductCarts->actions->add($person, $bulbs),
            );
            self::assertRefusedTo(
                $writer,
                WriteNotAllowedException::class,
                static fn () => Hilos::$db->userProductCarts->actions->step($person, $bulbs, CartLineRules::STEP_UP),
            );
            self::assertRefusedTo(
                $writer,
                WriteNotAllowedException::class,
                static fn () => Hilos::$db->userProductCarts->actions->setQuantity($person, $bulbs, 3),
            );
            self::assertRefusedTo(
                $writer,
                WriteNotAllowedException::class,
                static fn () => Hilos::$db->userProductCarts->actions->remove($person, $bulbs),
            );
            self::assertRefusedTo(
                $writer,
                WriteNotAllowedException::class,
                static fn () => Hilos::$db->userProductCarts->actions->deleteForUser($person),
            );

            ExecutionContext::run(new ExecutionFrame(agentId: $writer), static function () use ($person, $bulbs, $vase, $seeds): void {
                Hilos::$db->userProductCarts->actions->add($person, $vase);
                Hilos::$db->userProductCarts->actions->setQuantity($person, $bulbs, 1);
                Hilos::$db->userProductCarts->actions->remove($person, $seeds);
            });
        }

        self::assertSame($stored, self::storedLines($person));
    }

    /** The person's agent may neither add a line to another person's cart nor change or remove one, and that cart stays as it was. */
    public function testThePersonsAgentIsRefusedOnAnotherPersonsCart(): void
    {
        $other = $this->otherPersonId();
        $bulbs = self::product(ProductKind::STOCK);
        self::stock($bulbs, self::warehouse(), 5);
        $vase = self::product(ProductKind::UNIQUE);
        self::stock($vase, self::warehouse(), 1);
        self::line($other, $bulbs, 2);
        $stored = self::storedLines($other);
        $writer = $this->agent()->getId();

        self::assertRefusedTo($writer, CreateNotAllowedException::class, static fn () => Hilos::$db->userProductCarts->actions->add($other, $vase));
        self::assertRefusedTo($writer, WriteNotAllowedException::class, static fn () => Hilos::$db->userProductCarts->actions->add($other, $bulbs));
        self::assertRefusedTo(
            $writer,
            WriteNotAllowedException::class,
            static fn () => Hilos::$db->userProductCarts->actions->step($other, $bulbs, CartLineRules::STEP_DOWN),
        );
        self::assertRefusedTo(
            $writer,
            WriteNotAllowedException::class,
            static fn () => Hilos::$db->userProductCarts->actions->setQuantity($other, $bulbs, 3),
        );
        self::assertRefusedTo(
            $writer,
            WriteNotAllowedException::class,
            static fn () => Hilos::$db->userProductCarts->actions->remove($other, $bulbs),
        );
        self::assertRefusedTo($writer, WriteNotAllowedException::class, static fn () => Hilos::$db->userProductCarts->actions->deleteForUser($other));

        self::assertSame($stored, self::storedLines($other));
    }

    /** A person can replace or clear their single voucher slot even when the cart has no products. */
    public function testAnAccountCartKeepsOneVoucherAndReadsTheCurrentCatalog(): void
    {
        $agent = $this->agent();
        $userId = $this->personId();
        [$first, $second] = self::asOwner(new VouchersLibraryAgent(), static fn (): array => [
            Hilos::$db->vouchers->actions->create('SPRING10', 10, true),
            Hilos::$db->vouchers->actions->create('AUTUMN20', 20, true),
            Hilos::$db->vouchers->actions->create('WINTER30', 30, false),
        ]);

        self::assertNull($agent->cartVoucher());
        self::assertSame(0, CartVoucherRules::effectiveDiscountPercent($agent->cartVoucher()));
        $agent->applyVoucher('  spring10 ');
        self::assertSame($first->id, self::storedVoucherId($userId));
        self::assertSame([], self::storedLines($userId));
        self::assertSame(10, CartVoucherRules::effectiveDiscountPercent($agent->cartVoucher()));

        $agent->applyVoucher('AUTUMN20');
        $agent->applyVoucher('autumn20');
        self::assertSame($second->id, self::storedVoucherId($userId));

        foreach ([
            [' ', 'Voucher code cannot be empty', EmptyValueException::class],
            [
                str_repeat('A', VoucherFields::CODE_MAX_LENGTH + 1),
                'Voucher code exceeds maximum length of 32 characters',
                ValueTooLongException::class,
            ],
            ['AUTUMN-20', 'Voucher code may only contain Latin letters and digits', ValidationException::class],
            ['UNKNOWN', 'Voucher code was not found', ValidationException::class],
            ['WINTER30', 'This voucher is inactive', ValidationException::class],
        ] as [$code, $message, $exception]) {
            self::assertRefused($message, static fn () => $agent->applyVoucher($code), $exception);
            self::assertSame($second->id, self::storedVoucherId($userId));
        }

        self::asOwner(new VouchersLibraryAgent(), static fn () => $second->actions->update('AUTUMN25', 25, false));
        $current = $agent->cartVoucher();
        self::assertNotNull($current);
        self::assertSame('AUTUMN25', $current->code);
        self::assertSame(25, $current->discountPercent);
        self::assertFalse($current->active);
        self::assertSame(0, CartVoucherRules::effectiveDiscountPercent($current));
        self::assertSame($second->id, self::storedVoucherId($userId));

        self::asOwner(new VouchersLibraryAgent(), static fn () => $second->actions->setActive(true));
        self::assertSame(25, CartVoucherRules::effectiveDiscountPercent($agent->cartVoucher()));

        $agent->removeVoucher();
        self::assertNull(self::storedVoucherId($userId));
        $agent->removeVoucher();
        self::assertNull(self::storedVoucherId($userId));
        self::assertNull($agent->cartVoucher());
        self::assertSame('AUTUMN25', Hilos::$db->vouchers[$second->id]->code);
        self::assertTrue(Hilos::$db->vouchers[$second->id]->active);
    }

    /** Each person's agent can change only their own binding, even in the presence of another user's set. */
    public function testVoucherBindingsAreIsolatedByPersonAndRefuseAnotherWriter(): void
    {
        $agent = $this->agent();
        $userId = $this->personId();
        $otherId = $this->otherPersonId();
        $otherAgent = new UserAgent((string)$otherId);
        [$first, $second] = self::asOwner(new VouchersLibraryAgent(), static fn (): array => [
            Hilos::$db->vouchers->actions->create('SPRING10', 10, true),
            Hilos::$db->vouchers->actions->create('AUTUMN20', 20, true),
        ]);

        self::asOwner($otherAgent, static function () use ($agent, $otherAgent, $first, $second): void {
            self::assertRefusedTo($otherAgent->getId(), CreateNotAllowedException::class, static fn () => $agent->applyVoucher($first->code));
            $otherAgent->applyVoucher($second->code);
            self::assertSame($second->id, $otherAgent->cartVoucher()?->id);
        });
        self::assertNull($agent->cartVoucher());

        $agent->applyVoucher($first->code);
        self::asOwner($otherAgent, static function () use ($agent, $otherAgent, $first, $second): void {
            self::assertRefusedTo($otherAgent->getId(), WriteNotAllowedException::class, static fn () => $agent->applyVoucher($second->code));
            self::assertRefusedTo($otherAgent->getId(), WriteNotAllowedException::class, static fn () => $agent->applyVoucher($first->code));
            self::assertRefusedTo($otherAgent->getId(), WriteNotAllowedException::class, $agent->removeVoucher(...));
        });
        self::assertSame($first->id, self::storedVoucherId($userId));
        self::assertSame($second->id, self::storedVoucherId($otherId));

        $agent->removeVoucher();
        self::assertNull(self::storedVoucherId($userId));
        self::assertSame($second->id, self::storedVoucherId($otherId));
    }

    /**
     * @template T
     * @param AbstractAgent $owner Agent whose set or catalog the fixture writes
     * @param callable(): T $write Fixture operation
     * @return T Result of the operation
     */
    private static function asOwner(AbstractAgent $owner, callable $write): mixed
    {
        try {
            OwnershipDeclaration::claimAll($owner);

            return ExecutionContext::run(new ExecutionFrame(agentId: $owner->getId()), $write);
        } finally {
            TruthSourceRegistry::unregisterAgent($owner->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($owner->getId()));
        }
    }

    /**
     * @param int $userId Person whose cart is read
     * @return ?int Stored voucher ID, or null for an empty slot
     */
    private static function storedVoucherId(int $userId): ?int
    {
        $rows = Database::sql('SELECT voucher_id FROM user_voucher_cart WHERE user_id = ?', [$userId])->rows();

        return $rows === [] ? null : (int)$rows[0]['voucher_id'];
    }

    /**
     * Writes a cart line past the agent of the person whose cart it is.
     *
     * @param int $userId Person whose cart it is
     * @param int $productId Product of the line
     * @param int $quantity Units the line holds, one at least
     */
    private static function line(int $userId, int $productId, int $quantity): void
    {
        Database::sql(
            'INSERT INTO user_product_cart (user_id, product_id, quantity) VALUES (?, ?, ?)',
            [$userId, $productId, $quantity],
        );
    }

    /**
     * Runs a write that must be refused, and checks the refusal reaches the person word for word.
     *
     * @param string $sentence The sentence the person is shown
     * @param callable(): mixed $write Write to refuse
     * @param class-string<ValidationException> $expected Exact refusal class
     */
    private static function assertRefused(string $sentence, callable $write, string $expected = ValidationException::class): void
    {
        try {
            $write();
        } catch (ValidationException $e) {
            self::assertSame($expected, $e::class);
            self::assertSame($sentence, $e->getMessage());
            self::assertSame($sentence, ActionFailureReason::forClient($e));

            return;
        }

        self::fail("Expected the refusal: {$sentence}");
    }

    /**
     * Runs a write in the frame of a process that does not own the cart it writes, and checks it is refused.
     *
     * @param string $writer Id of the agent the write runs as
     * @param class-string<HilosException> $expected Exact refusal class
     * @param callable(): mixed $write Write to refuse
     */
    private static function assertRefusedTo(string $writer, string $expected, callable $write): void
    {
        try {
            ExecutionContext::run(new ExecutionFrame(agentId: $writer), $write);
        } catch (HilosException $e) {
            self::assertSame($expected, $e::class);

            return;
        }

        self::fail("Expected {$expected} for {$writer}, which does not own the cart");
    }

    /**
     * @param int $userId Person whose cart it is
     * @return list<array{id: int, productId: int, quantity: int}> The lines of the person's cart read past the ORM, in
     *     the order they were added; empty when it has none
     */
    private static function storedLines(int $userId): array
    {
        $lines = [];
        foreach (Database::sql(
            'SELECT id, product_id, quantity FROM user_product_cart WHERE user_id = ? ORDER BY id',
            [$userId],
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
