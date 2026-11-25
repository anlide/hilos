<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\GuestCartsLibraryAgent;
use Demo\EcommerceShop\Agents\Hilos\SessionsLibraryAgent;
use Demo\EcommerceShop\Agents\Hilos\UsersLibraryAgent;
use Demo\EcommerceShop\Agents\VouchersLibraryAgent;
use Demo\EcommerceShop\CLI\DTO\GuestCartSetVoucherCommandData;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\EcommerceShopCommandConstants;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Core\Router\EcommerceShopSignalRouter;
use Demo\EcommerceShop\Database\Actions\CartLineRules;
use Demo\EcommerceShop\Database\Actions\CartVoucherRules;
use Demo\EcommerceShop\Database\Actions\VoucherFields;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\View\Item\Voucher;
use Demo\EcommerceShop\Hilos;
use Hilos\Auth\Session\DTO\SessionRotateSignalData;
use Hilos\Auth\Session\DTO\SessionsSweptSignalData;
use Hilos\Auth\Session\SessionToken;
use Hilos\Constants\AppEnv;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\ActionFailureReason;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\DatabaseNames;
use Hilos\Environment\EnvAccessor;
use Hilos\HilosException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\Testing\IntegrationTest;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * The real shop schema and the guest cart actions under their library's claim; the rollback scope proves writes, not commit.
 *
 * Warehouses, products of either kind and their stock are rows written straight into their tables past their
 * libraries, before the first cart action of a case reads them. Stock changes, voucher catalog writes and session
 * writes run under their respective owners. The stored carts and stock are read back past the ORM.
 */
#[IntegrationTest]
final class GuestCartsLibraryTest extends GuestCartsLibraryEnvironment
{
    /** Stores the guests addressed by the product-action fixtures. */
    protected function setUpInTransaction(): void
    {
        parent::setUpInTransaction();
        $now = TimeHelper::nowMs();
        foreach ([101, 102, 103] as $sessionId) {
            Database::sql(
                'INSERT INTO hilos_session (id, token, created_at, last_seen_at) VALUES (?, ?, ?, ?)',
                [$sessionId, SessionToken::mint(), $now, $now],
            );
        }
    }

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

        $stock = Hilos::$db->productWarehouseStocks->getObjectCollection();
        self::assertNotNull($stock);
        self::assertFalse($stock->isAllLoaded());
        Hilos::$db->sessionProductCarts->actions->add(101, 0, $bulbs);
        self::assertFalse($stock->isAllLoaded());
        $lines = self::storedLines(101);
        self::assertCount(1, $lines);
        self::assertSame(['productId' => $bulbs, 'quantity' => 1], self::withoutId($lines[0]));

        Hilos::$db->sessionProductCarts->actions->add(101, 0, $bulbs);
        self::assertSame([['id' => $lines[0]['id'], 'productId' => $bulbs, 'quantity' => 2]], self::storedLines(101));
    }

    /** One unit more than the stock, and any unit of a stock item no warehouse keeps, is refused and leaves the cart as it was. */
    public function testAStockItemAboveItsStockOrOutOfStockIsRefused(): void
    {
        $bulbs = self::product(ProductKind::STOCK);
        self::stock($bulbs, self::warehouse(), 2);
        $seeds = self::product(ProductKind::STOCK);
        Hilos::$db->sessionProductCarts->actions->add(101, 0, $bulbs);
        Hilos::$db->sessionProductCarts->actions->add(101, 0, $bulbs);
        $stored = self::storedLines(101);

        self::assertRefused('Only 2 in stock', static fn () => Hilos::$db->sessionProductCarts->actions->add(101, 0, $bulbs));
        self::assertRefused('This product is out of stock', static fn () => Hilos::$db->sessionProductCarts->actions->add(101, 0, $seeds));
        self::assertRefused('This product does not exist', static fn () => Hilos::$db->sessionProductCarts->actions->add(101, 0, $seeds + 1));

        self::assertSame($stored, self::storedLines(101));
    }

    /** A unique piece is put in once: no warehouse is a refusal, a second time changes nothing and is no refusal. */
    public function testAUniquePieceIsPutInOnce(): void
    {
        $vase = self::product(ProductKind::UNIQUE);
        $bowl = self::product(ProductKind::UNIQUE);
        self::stock($vase, self::warehouse(), 1);

        self::assertRefused('This piece is not in a warehouse yet', static fn () => Hilos::$db->sessionProductCarts->actions->add(101, 0, $bowl));
        self::assertSame([], self::storedLines(101));

        Hilos::$db->sessionProductCarts->actions->add(101, 0, $vase);
        $lines = self::storedLines(101);
        self::assertCount(1, $lines);
        self::assertSame(['productId' => $vase, 'quantity' => 1], self::withoutId($lines[0]));

        Hilos::$db->sessionProductCarts->actions->add(101, 0, $vase);
        self::assertSame($lines, self::storedLines(101));
    }

    /** A step goes one unit up or down; down from one unit and up above the stock are refused. */
    public function testAStepGoesOneUnitUpOrDownWithinOneAndTheStock(): void
    {
        $bulbs = self::product(ProductKind::STOCK);
        self::stock($bulbs, self::warehouse(), 2);
        Hilos::$db->sessionProductCarts->actions->add(101, 0, $bulbs);
        $line = self::storedLines(101)[0]['id'];

        self::assertRefused(
            'A cart line keeps one unit at least: Remove takes it out',
            static fn () => Hilos::$db->sessionProductCarts->actions->step(101, 0, $bulbs, CartLineRules::STEP_DOWN),
        );
        Hilos::$db->sessionProductCarts->actions->step(101, 0, $bulbs, CartLineRules::STEP_UP);
        self::assertSame([['id' => $line, 'productId' => $bulbs, 'quantity' => 2]], self::storedLines(101));

        self::assertRefused(
            'Only 2 in stock',
            static fn () => Hilos::$db->sessionProductCarts->actions->step(101, 0, $bulbs, CartLineRules::STEP_UP),
        );
        Hilos::$db->sessionProductCarts->actions->step(101, 0, $bulbs, CartLineRules::STEP_DOWN);
        self::assertSame([['id' => $line, 'productId' => $bulbs, 'quantity' => 1]], self::storedLines(101));
    }

    /** A line left above the stock when the stock fell may still go down, not up, and an exact number for it stays within the stock. */
    public function testALineAboveAFallenStockMayGoDown(): void
    {
        $kyiv = self::warehouse();
        $bulbs = self::product(ProductKind::STOCK);
        self::stock($bulbs, $kyiv, 3);
        Hilos::$db->sessionProductCarts->actions->add(101, 0, $bulbs);
        Hilos::$db->sessionProductCarts->actions->setQuantity(101, 0, $bulbs, 3);
        $line = self::storedLines(101)[0]['id'];

        ExecutionContext::run(
            new ExecutionFrame(agentId: AgentType::STOCK_LIBRARY),
            static fn () => Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $kyiv, 1),
        );

        self::assertRefused(
            'Only 1 in stock',
            static fn () => Hilos::$db->sessionProductCarts->actions->step(101, 0, $bulbs, CartLineRules::STEP_UP),
        );
        self::assertRefused('Only 1 in stock', static fn () => Hilos::$db->sessionProductCarts->actions->setQuantity(101, 0, $bulbs, 2));
        Hilos::$db->sessionProductCarts->actions->setQuantity(101, 0, $bulbs, 3);
        self::assertSame([['id' => $line, 'productId' => $bulbs, 'quantity' => 3]], self::storedLines(101));

        Hilos::$db->sessionProductCarts->actions->step(101, 0, $bulbs, CartLineRules::STEP_DOWN);
        self::assertSame([['id' => $line, 'productId' => $bulbs, 'quantity' => 2]], self::storedLines(101));
    }

    /** A step and an exact number belong to a stock item in the cart: a unique piece, no line and no product are refused. */
    public function testAStepOrANumberOfAUniquePieceOrOfNoLineIsRefused(): void
    {
        $vase = self::product(ProductKind::UNIQUE);
        self::stock($vase, self::warehouse(), 1);
        $bulbs = self::product(ProductKind::STOCK);
        self::stock($bulbs, self::warehouse(), 5);
        Hilos::$db->sessionProductCarts->actions->add(101, 0, $vase);
        $stored = self::storedLines(101);

        $unique = 'A unique piece has no quantity: it is one of a kind';
        self::assertRefused($unique, static fn () => Hilos::$db->sessionProductCarts->actions->step(101, 0, $vase, CartLineRules::STEP_UP));
        self::assertRefused($unique, static fn () => Hilos::$db->sessionProductCarts->actions->setQuantity(101, 0, $vase, 1));
        self::assertRefused(
            'This product is not in the cart',
            static fn () => Hilos::$db->sessionProductCarts->actions->step(101, 0, $bulbs, CartLineRules::STEP_UP),
        );
        self::assertRefused(
            'This product is not in the cart',
            static fn () => Hilos::$db->sessionProductCarts->actions->setQuantity(101, 0, $bulbs, 2),
        );
        self::assertRefused(
            'This product does not exist',
            static fn () => Hilos::$db->sessionProductCarts->actions->step(101, 0, $bulbs + 1, CartLineRules::STEP_UP),
        );

        self::assertSame($stored, self::storedLines(101));
    }

    /** An exact number between one unit and the stock is set; zero and above the stock are refused, the same number writes nothing. */
    public function testAnExactNumberIsSetWithinOneAndTheStock(): void
    {
        $bulbs = self::product(ProductKind::STOCK);
        self::stock($bulbs, self::warehouse(), 5);
        Hilos::$db->sessionProductCarts->actions->add(101, 0, $bulbs);
        $line = self::storedLines(101)[0]['id'];

        Hilos::$db->sessionProductCarts->actions->setQuantity(101, 0, $bulbs, 4);
        self::assertSame([['id' => $line, 'productId' => $bulbs, 'quantity' => 4]], self::storedLines(101));

        self::assertRefused(
            'A cart line keeps one unit at least: Remove takes it out',
            static fn () => Hilos::$db->sessionProductCarts->actions->setQuantity(101, 0, $bulbs, 0),
        );
        self::assertRefused('Only 5 in stock', static fn () => Hilos::$db->sessionProductCarts->actions->setQuantity(101, 0, $bulbs, 6));
        Hilos::$db->sessionProductCarts->actions->setQuantity(101, 0, $bulbs, 4);

        self::assertSame([['id' => $line, 'productId' => $bulbs, 'quantity' => 4]], self::storedLines(101));
    }

    /** Remove takes the line of a product out of one cart; a product not in it is left as it is. */
    public function testRemoveTakesTheLineOut(): void
    {
        $bulbs = self::product(ProductKind::STOCK);
        self::stock($bulbs, self::warehouse(), 5);
        $vase = self::product(ProductKind::UNIQUE);
        self::stock($vase, self::warehouse(), 1);
        Hilos::$db->sessionProductCarts->actions->add(101, 0, $bulbs);
        Hilos::$db->sessionProductCarts->actions->add(101, 0, $vase);
        Hilos::$db->sessionProductCarts->actions->add(102, 0, $bulbs);
        $other = self::storedLines(102);

        Hilos::$db->sessionProductCarts->actions->remove(101, 0, $bulbs);
        $lines = self::storedLines(101);
        self::assertCount(1, $lines);
        self::assertSame(['productId' => $vase, 'quantity' => 1], self::withoutId($lines[0]));

        Hilos::$db->sessionProductCarts->actions->remove(101, 0, $bulbs);
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
        Hilos::$db->sessionProductCarts->actions->add(101, 0, $bulbs);
        Hilos::$db->sessionProductCarts->actions->add(101, 0, $vase);
        Hilos::$db->sessionProductCarts->actions->add(102, 0, $bulbs);
        Hilos::$db->sessionProductCarts->actions->add(103, 0, $bulbs);
        $kept = self::storedLines(102);

        Database::sql('DELETE FROM hilos_session WHERE id IN (?, ?)', [101, 103]);
        $this->sweep([101, 103, 104]);

        self::assertSame([], self::storedLines(101));
        self::assertSame([], self::storedLines(103));
        self::assertSame($kept, self::storedLines(102));

        $this->sweep([104]);
        self::assertSame($kept, self::storedLines(102));
        self::assertSame(0, Hilos::$db->sessionProductCarts->actions->deleteForSessions([]));
    }

    /** An empty cart can accept or replace a voucher. A refusal keeps the last selection. */
    public function testApplyingAndRemovingAVoucherKeepsASingleSlot(): void
    {
        $sessionId = self::guestSessionId();
        $first = self::createVoucher('SUMMER25', 10, true);
        $second = self::createVoucher('AUTUMN20', 20, true);
        self::createVoucher('WINTER30', 30, false);

        $this->carts()->applyVoucher($sessionId, '  summer25 ');
        self::assertSame($first->id, self::storedVoucherId($sessionId));
        self::assertSame([], self::storedLines($sessionId));

        $this->carts()->applyVoucher($sessionId, 'AUTUMN20');
        $this->carts()->applyVoucher($sessionId, 'autumn20');
        self::assertSame($second->id, self::storedVoucherId($sessionId));

        $refusals = [
            [' ', 'Voucher code cannot be empty', EmptyValueException::class],
            [
                str_repeat('A', VoucherFields::CODE_MAX_LENGTH + 1),
                'Voucher code exceeds maximum length of 32 characters',
                ValueTooLongException::class,
            ],
            ['AUTUMN-20', 'Voucher code may only contain Latin letters and digits', ValidationException::class],
            ['UNKNOWN', 'Voucher code was not found', ValidationException::class],
            ['WINTER30', 'This voucher is inactive', ValidationException::class],
        ];
        foreach ($refusals as [$code, $message, $exception]) {
            self::assertRefused($message, fn () => $this->carts()->applyVoucher($sessionId, $code), $exception);
            self::assertSame($second->id, self::storedVoucherId($sessionId));
        }

        $this->carts()->removeVoucher($sessionId);
        self::assertNull(self::storedVoucherId($sessionId));
        $this->carts()->removeVoucher($sessionId);
        self::assertNull(self::storedVoucherId($sessionId));
        self::assertSame('AUTUMN20', Hilos::$db->vouchers[$second->id]->code);
        self::assertTrue(Hilos::$db->vouchers[$second->id]->active);
    }

    /** The command applies its fixture and can clear it twice even after it was disabled. */
    public function testGuestVoucherFixtureAppliesAndIdempotentlyClearsAnInactiveVoucher(): void
    {
        $sessionId = self::guestSessionId();
        $token = Hilos::$db->sessions[$sessionId]->token;
        $voucher = self::createVoucher('ZZE2EV111111111111111111111111', 10, true);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $reply = $this->voucherFixture($token, strtolower($voucher->code), true);
            self::assertTrue($reply->isOk());
            self::assertSame(['applied' => true], $reply->payload);
            self::assertSame($voucher->id, self::storedVoucherId($sessionId));
        }

        self::asOwner(new VouchersLibraryAgent(), static fn () => $voucher->actions->setActive(false));
        $reply = $this->voucherFixture($token, $voucher->code, true);
        self::assertFalse($reply->isOk());
        self::assertSame([CommandConstants::FIELD_MESSAGE => 'This voucher is inactive'], $reply->payload);
        self::assertSame($voucher->id, self::storedVoucherId($sessionId));

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $reply = $this->voucherFixture($token, $voucher->code, false);
            self::assertTrue($reply->isOk());
            self::assertSame(['applied' => false], $reply->payload);
            self::assertNull(self::storedVoucherId($sessionId));
        }
        self::assertFalse(Hilos::$db->vouchers[$voucher->id]->active);
    }

    /** @return array<string, array{string, bool, string}> Requested code, desired binding and refusal */
    public static function guestVoucherFixtureRefusals(): array
    {
        return [
            'replace another fixture' => ['ZZE2EV222222222222222222222222', true, 'This guest cart holds a different voucher'],
            'remove another fixture' => ['ZZE2EV222222222222222222222222', false, 'This guest cart holds a different voucher'],
            'apply outside the fixture space' => ['SUMMER25', true, 'A voucher fixture code must be ZZE2EV followed by 24 hexadecimal digits'],
            'clear outside the fixture space' => ['SUMMER25', false, 'A voucher fixture code must be ZZE2EV followed by 24 hexadecimal digits'],
        ];
    }

    /** A fixture command cannot replace a different binding or act on an ordinary voucher code. */
    #[DataProvider('guestVoucherFixtureRefusals')]
    public function testGuestVoucherFixtureRefusesOtherCodes(string $code, bool $applied, string $reason): void
    {
        $sessionId = self::guestSessionId();
        $voucher = self::createVoucher('ZZE2EV111111111111111111111111', 10, true);
        self::createVoucher($code, 15, true);
        $this->carts()->applyVoucher($sessionId, $voucher->code);

        $reply = $this->voucherFixture(Hilos::$db->sessions[$sessionId]->token, $code, $applied);
        self::assertFalse($reply->isOk());
        self::assertSame([CommandConstants::FIELD_MESSAGE => $reason], $reply->payload);
        self::assertSame($voucher->id, self::storedVoucherId($sessionId));
    }

    /** A reused code cannot replace the selected voucher while the cart owner's catalog mirror is stale. */
    public function testGuestVoucherFixtureRefusesAReusedCodeBeforeCatalogSync(): void
    {
        $sessionId = self::guestSessionId();
        $token = Hilos::$db->sessions[$sessionId]->token;
        $code = 'ZZE2EV111111111111111111111111';
        $renamedCode = 'ZZE2EV222222222222222222222222';
        $voucher = self::createVoucher($code, 10, true);
        $this->carts()->applyVoucher($sessionId, $code);

        // Simulate catalog writes whose sync frames have yet to reach the cart owner.
        Database::sqlRun('UPDATE voucher SET code = ? WHERE id = ?', [$renamedCode, $voucher->id]);
        Database::sqlRun('INSERT INTO voucher (code, discount_percent, active) VALUES (?, ?, ?)', [$code, 15, true]);
        self::assertSame($code, $this->carts()->cartVoucher($sessionId)?->code);

        // The rollback scope keeps after-commit publications queued. Inspect a copy of that queue.
        $level = Database::runtimeTransactionLevel();
        self::assertNotNull($level);
        $before = (clone $level)->takeHeld();

        $reply = $this->voucherFixture($token, $code, true);
        self::assertFalse($reply->isOk());
        self::assertSame([CommandConstants::FIELD_MESSAGE => 'This guest cart holds a different voucher'], $reply->payload);
        self::assertSame($voucher->id, self::storedVoucherId($sessionId));
        self::assertSame($before, (clone $level)->takeHeld());

        $reply = $this->voucherFixture($token, $renamedCode, true);
        self::assertTrue($reply->isOk());
        self::assertSame(['applied' => true], $reply->payload);
        self::assertSame($voucher->id, self::storedVoucherId($sessionId));
        self::assertSame($before, (clone $level)->takeHeld());
    }

    /** A deleted saved row is an empty slot even when the old binding is still held. */
    public function testGuestVoucherFixtureRecreatesADeletedBindingBeforeSync(): void
    {
        $sessionId = self::guestSessionId();
        $token = Hilos::$db->sessions[$sessionId]->token;
        $voucher = self::createVoucher('ZZE2EV111111111111111111111111', 10, true);
        $this->carts()->applyVoucher($sessionId, $voucher->code);

        Database::sqlRun('DELETE FROM session_voucher_cart WHERE session_id = ?', [$sessionId]);
        self::assertSame($voucher->id, $this->carts()->cartVoucher($sessionId)?->id);
        self::assertNull(self::storedVoucherId($sessionId));

        $reply = $this->voucherFixture($token, $voucher->code, true);
        self::assertTrue($reply->isOk());
        self::assertSame(['applied' => true], $reply->payload);
        self::assertSame($voucher->id, self::storedVoucherId($sessionId));
        self::assertSame($voucher->id, $this->carts()->cartVoucher($sessionId)?->id);
    }

    public function testGuestVoucherFixtureRefusesADifferentStoredBindingBeforeSync(): void
    {
        $sessionId = self::guestSessionId();
        $token = Hilos::$db->sessions[$sessionId]->token;
        $cached = self::createVoucher('ZZE2EV111111111111111111111111', 10, true);
        $stored = self::createVoucher('ZZE2EV222222222222222222222222', 15, true);
        $this->carts()->applyVoucher($sessionId, $cached->code);

        Database::sqlRun('UPDATE session_voucher_cart SET voucher_id = ? WHERE session_id = ?', [$stored->id, $sessionId]);
        self::assertSame($cached->id, $this->carts()->cartVoucher($sessionId)?->id);
        $level = Database::runtimeTransactionLevel();
        self::assertNotNull($level);
        $before = (clone $level)->takeHeld();

        foreach ([true, false] as $applied) {
            $reply = $this->voucherFixture($token, $cached->code, $applied);
            self::assertFalse($reply->isOk());
            self::assertSame([CommandConstants::FIELD_MESSAGE => 'This guest cart holds a different voucher'], $reply->payload);
            self::assertSame($stored->id, self::storedVoucherId($sessionId));
            self::assertSame($before, (clone $level)->takeHeld());
        }

        self::asOwner(new VouchersLibraryAgent(), static fn () => $stored->actions->setActive(false));
        $before = (clone $level)->takeHeld();
        $reply = $this->voucherFixture($token, $stored->code, false);
        self::assertTrue($reply->isOk());
        self::assertSame(['applied' => false], $reply->payload);
        self::assertNull(self::storedVoucherId($sessionId));
        self::assertNull($this->carts()->cartVoucher($sessionId));
        self::assertNotSame($before, (clone $level)->takeHeld());
    }

    /** @return array<string, array{bool, string}> Whether the node is in test and the expected refusal */
    public static function guestVoucherFixtureAccessRefusals(): array
    {
        return [
            'dev environment' => [false, 'Voucher fixtures require APP_ENV=test'],
            'bound session before sync' => [true, 'This guest session is no longer available'],
        ];
    }

    /** The command requires the test environment and a session that is still a guest in storage. */
    #[DataProvider('guestVoucherFixtureAccessRefusals')]
    public function testGuestVoucherFixtureRefusesOutsideTestOrAfterSignIn(bool $inTest, string $reason): void
    {
        $sessionId = self::guestSessionId();
        $session = Hilos::$db->sessions[$sessionId];
        $voucher = self::createVoucher('ZZE2EV111111111111111111111111', 10, true);
        $this->carts()->applyVoucher($sessionId, $voucher->code);

        $previousEnv = Hilos::$env;
        if ($inTest) {
            Database::sql('INSERT INTO hilos_user (name, admin, block) VALUES (?, 0, 0)', ['Signed-in shopper']);
            Database::sql('UPDATE hilos_session SET user_id = ? WHERE id = ?', [Database::lastInsertId(), $sessionId]);
            self::assertNull($session->userId);
        } else {
            $env = $this->createPartialMock(EnvAccessor::class, ['effectiveValueFor']);
            $env->method('effectiveValueFor')->with(EnvConstants::APP_ENV->name)->willReturn(AppEnv::DEV->value);
            Hilos::$env = $env;
        }
        try {
            foreach ([true, false] as $applied) {
                $reply = $this->voucherFixture($session->token, $voucher->code, $applied);
                self::assertFalse($reply->isOk());
                self::assertSame([CommandConstants::FIELD_MESSAGE => $reason], $reply->payload);
                self::assertSame($voucher->id, self::storedVoucherId($sessionId));
            }
        } finally {
            Hilos::$env = $previousEnv;
        }
    }

    /** A cart keeps the voucher ID and uses the current catalog record for its discount. */
    public function testCartVoucherFollowsCatalogChangesWithoutReapplying(): void
    {
        $sessionId = self::guestSessionId();
        $voucher = self::createVoucher('SUMMER25', 10, true);

        self::assertNull($this->carts()->cartVoucher($sessionId));
        self::assertSame(0, CartVoucherRules::effectiveDiscountPercent($this->carts()->cartVoucher($sessionId)));

        $this->carts()->applyVoucher($sessionId, 'SUMMER25');
        self::assertSame(10, CartVoucherRules::effectiveDiscountPercent($this->carts()->cartVoucher($sessionId)));

        self::asOwner(new VouchersLibraryAgent(), static fn () => $voucher->actions->update('AUTUMN20', 20, false));
        $current = $this->carts()->cartVoucher($sessionId);
        self::assertNotNull($current);
        self::assertSame('AUTUMN20', $current->code);
        self::assertSame(20, $current->discountPercent);
        self::assertFalse($current->active);
        self::assertSame(0, CartVoucherRules::effectiveDiscountPercent($current));
        self::assertSame($voucher->id, self::storedVoucherId($sessionId));

        self::asOwner(new VouchersLibraryAgent(), static fn () => $voucher->actions->setActive(true));
        self::assertSame(20, CartVoucherRules::effectiveDiscountPercent($this->carts()->cartVoucher($sessionId)));
        self::assertSame($voucher->id, self::storedVoucherId($sessionId));
    }

    /** Sweeping one guest removes its products and voucher. A delayed apply cannot recreate the binding. */
    public function testSweepClearsOnlyTheEndedGuestAndRefusesALateApply(): void
    {
        $sweptSessionId = self::guestSessionId();
        $keptSessionId = self::guestSessionId();
        $voucher = self::createVoucher('SUMMER25', 10, true);
        $product = self::product(ProductKind::STOCK);
        self::stock($product, self::warehouse(), 2);
        foreach ([$sweptSessionId, $keptSessionId] as $sessionId) {
            Hilos::$db->sessionProductCarts->actions->add($sessionId, 0, $product);
            $this->carts()->applyVoucher($sessionId, 'SUMMER25');
        }
        $keptLines = self::storedLines($keptSessionId);

        self::asOwner(new SessionsLibraryAgent(), static fn () => Hilos::$db->sessions[$sweptSessionId]->actions->delete());
        $this->sweep([$sweptSessionId]);
        self::assertSame([], self::storedLines($sweptSessionId));
        self::assertNull(self::storedVoucherId($sweptSessionId));
        self::assertSame($keptLines, self::storedLines($keptSessionId));
        self::assertSame($voucher->id, self::storedVoucherId($keptSessionId));

        $this->sweep([$sweptSessionId]);
        $this->sweep([]);
        self::assertSame($keptLines, self::storedLines($keptSessionId));
        self::assertSame($voucher->id, self::storedVoucherId($keptSessionId));
        self::assertRefused(
            'This guest session is no longer available',
            fn () => $this->carts()->applyVoucher($sweptSessionId, 'SUMMER25'),
        );
        self::assertNull(self::storedVoucherId($sweptSessionId));

        Database::sql('INSERT INTO hilos_user (name, admin, block) VALUES (?, 0, 0)', ['Signed-in shopper']);
        $userId = Database::lastInsertId();
        self::asOwner(new SessionsLibraryAgent(), static fn () => Hilos::$db->sessions[$keptSessionId]->actions->bindUser($userId));
        self::assertRefused(
            'This guest session is no longer available',
            fn () => $this->carts()->applyVoucher($keptSessionId, 'SUMMER25'),
        );
        self::assertSame($voucher->id, self::storedVoucherId($keptSessionId));
    }

    /** A swept session may still be held by the guest worker while its deletion frame is in flight. */
    public function testLateVoucherApplyRefusesADeletedSessionBeforeSync(): void
    {
        $sessionId = self::guestSessionId();
        self::createVoucher('SUMMER25', 10, true);
        self::assertNull(Hilos::$db->sessions[$sessionId]->userId);

        Database::sql('DELETE FROM hilos_session WHERE id = ?', [$sessionId]);
        self::assertNotNull(Hilos::$db->sessions[$sessionId] ?? null);

        self::assertRefused('This guest session is no longer available', fn () => $this->carts()->applyVoucher($sessionId, 'SUMMER25'));
        self::assertNull(self::storedVoucherId($sessionId));
    }

    /** A bind landed in storage while the guest worker still holds the anonymous session. */
    public function testLateVoucherApplyRefusesABoundSessionBeforeSync(): void
    {
        $sessionId = self::guestSessionId();
        $voucher = self::createVoucher('SUMMER25', 10, true);
        self::createVoucher('AUTUMN20', 20, true);
        $this->carts()->applyVoucher($sessionId, 'SUMMER25');
        self::assertNull(Hilos::$db->sessions[$sessionId]->userId);

        Database::sql('INSERT INTO hilos_user (name, admin, block) VALUES (?, 0, 0)', ['Signed-in shopper']);
        Database::sql('UPDATE hilos_session SET user_id = ? WHERE id = ?', [Database::lastInsertId(), $sessionId]);
        self::assertNull(Hilos::$db->sessions[$sessionId]->userId);

        self::assertRefused('This guest session is no longer available', fn () => $this->carts()->applyVoucher($sessionId, 'AUTUMN20'));
        self::assertSame($voucher->id, self::storedVoucherId($sessionId));
    }

    /** A restarted owner can recover both cart families from storage after the one-shot sweep failed. */
    public function testFailedSweepRollsBackBothFamiliesAndTheNextOwnerRecoversThem(): void
    {
        $sweptSessionId = self::guestSessionId();
        $keptSessionId = self::guestSessionId();
        $voucher = self::createVoucher('SUMMER25', 10, true);
        $product = self::product(ProductKind::STOCK);
        self::stock($product, self::warehouse(), 2);
        foreach ([$sweptSessionId, $keptSessionId] as $sessionId) {
            Hilos::$db->sessionProductCarts->actions->add($sessionId, 0, $product);
            $this->carts()->applyVoucher($sessionId, 'SUMMER25');
        }
        $sweptLines = self::storedLines($sweptSessionId);
        $keptLines = self::storedLines($keptSessionId);
        self::asOwner(new SessionsLibraryAgent(), static fn () => Hilos::$db->sessions[$sweptSessionId]->actions->delete());

        self::assertRemovalFails(
            EcommerceShopDbContext::sessionVoucherCarts,
            $sweptSessionId,
            fn () => $this->sweep([$sweptSessionId]),
        );
        self::assertSame($sweptLines, self::storedLines($sweptSessionId));
        self::assertSame($voucher->id, self::storedVoucherId($sweptSessionId));

        $nextOwner = new GuestCartsLibraryAgent();
        $nextOwner->onStart();
        $nextOwner->onTick();

        self::assertSame([], self::storedLines($sweptSessionId));
        self::assertNull(self::storedVoucherId($sweptSessionId));
        self::assertSame($keptLines, self::storedLines($keptSessionId));
        self::assertSame($voucher->id, self::storedVoucherId($keptSessionId));
    }

    /** Both cleanup paths retain assigned pairs, and recovery also clears inactive pairs of a live session. */
    public function testCleanupPreservesPendingAndReceivedPairsWithOrWithoutASession(): void
    {
        $swept = self::guestSessionId();
        $missed = self::guestSessionId();
        $live = self::guestSessionId();
        $product = self::product(ProductKind::STOCK);
        self::stock($product, self::warehouse(), 1);
        $recipient = self::asOwner(
            new UsersLibraryAgent(),
            static fn () => Hilos::$db->users->actions->createWithName('Cart transfer recipient'),
        );

        foreach ([$swept, $missed, $live] as $sessionId) {
            for ($epoch = 0; $epoch <= 3; $epoch++) {
                Database::sql('UPDATE hilos_session SET cart_epoch = ? WHERE id = ?', [$epoch, $sessionId]);
                Hilos::$db->sessionProductCarts->actions->add($sessionId, $epoch, $product);
            }
        }
        $sweptLines = self::storedLines($swept);
        $missedLines = self::storedLines($missed);
        $liveLines = self::storedLines($live);
        Database::sql('DELETE FROM hilos_session WHERE id IN (?, ?)', [$swept, $missed]);

        [$throughLineId] = Hilos::$db->sessionProductCarts->cartRecoveryHorizons();
        [$candidates] = Hilos::$db->sessionProductCarts->cartRowAbsencesAfter(0, $throughLineId, 0, 0, 25);
        $oldCandidates = [$missedLines[0]['id'], $missedLines[1]['id']];
        foreach ($oldCandidates as $lineId) {
            self::assertTrue($candidates[$lineId]);
        }

        foreach ([$swept, $missed, $live] as $sessionId) {
            Database::sql(
                'INSERT INTO shop_guest_cart_transfer (session_id, cart_epoch, user_id, received) VALUES (?, 0, ?, 0), (?, 1, ?, 1)',
                [$sessionId, $recipient->id, $sessionId, $recipient->id],
            );
        }
        Database::transaction(
            DatabaseNames::PRIMARY,
            static fn () => Hilos::$db->sessionProductCarts->actions->deleteRecoveryLines($oldCandidates),
        );

        $this->sweep([$swept]);

        self::assertSame(array_slice($sweptLines, 0, 2), self::storedLines($swept));
        self::assertSame($missedLines, self::storedLines($missed));
        self::assertSame($liveLines, self::storedLines($live));

        [$candidates] = Hilos::$db->sessionProductCarts->cartRowAbsencesAfter(0, $throughLineId, 0, 0, 25);
        foreach ([$sweptLines, $missedLines, $liveLines] as $lines) {
            self::assertFalse($candidates[$lines[0]['id']]);
            self::assertFalse($candidates[$lines[1]['id']]);
        }

        $previousRouter = Hilos::$sr;
        Hilos::initSignalRouter(new EcommerceShopSignalRouter());
        try {
            $this->carts()->onTick();
        } finally {
            Hilos::$sr = $previousRouter;
        }

        self::assertSame([$sweptLines[0]], self::storedLines($swept));
        self::assertSame([$missedLines[0]], self::storedLines($missed));
        self::assertSame([$liveLines[0], $liveLines[3]], self::storedLines($live));
    }

    /** One oversized orphan cart spans a failed retry, several bounded ticks and an owner restart. */
    public function testRecoveryResumesAnOversizedCartByItsRemainingRows(): void
    {
        $leftSessionId = self::guestSessionId();
        $orphanSessionId = self::guestSessionId();
        $rightSessionId = self::guestSessionId();
        $category = self::category();
        $warehouse = self::warehouse();
        $neighborProduct = self::product(ProductKind::STOCK, $category);
        self::stock($neighborProduct, $warehouse, 1);
        Hilos::$db->sessionProductCarts->actions->add($leftSessionId, 0, $neighborProduct);
        for ($i = 0; $i < 75; $i++) {
            $product = self::product(ProductKind::STOCK, $category);
            self::stock($product, $warehouse, 1);
            Hilos::$db->sessionProductCarts->actions->add($orphanSessionId, 0, $product);
        }
        Hilos::$db->sessionProductCarts->actions->add($rightSessionId, 0, $neighborProduct);
        $leftLines = self::storedLines($leftSessionId);
        $rightLines = self::storedLines($rightSessionId);
        self::asOwner(new SessionsLibraryAgent(), static fn () => Hilos::$db->sessions[$orphanSessionId]->actions->delete());

        [$throughLineId] = Hilos::$db->sessionProductCarts->cartRecoveryHorizons();
        [$linePage] = Hilos::$db->sessionProductCarts->cartRowAbsencesAfter(0, $throughLineId, 0, 0, 25);
        $orphanIds = array_column(array_slice(self::storedLines($orphanSessionId), 0, 24), 'id');
        self::assertSame([$leftLines[0]['id'] => false] + array_fill_keys($orphanIds, true), $linePage);

        $this->carts()->onTick();
        $remaining = self::storedLines($orphanSessionId);
        self::assertCount(51, $remaining);
        self::assertRemovalFails(
            EcommerceShopDbContext::sessionProductCarts,
            $remaining[1]['id'],
            fn () => self::runRecoveryTick($this->carts()),
        );
        self::assertSame($remaining, self::storedLines($orphanSessionId));

        self::runRecoveryTick($this->carts());
        self::assertSame(array_slice($remaining, 25), self::storedLines($orphanSessionId));

        $nextOwner = new GuestCartsLibraryAgent();
        $nextOwner->onStart();
        $nextOwner->onTick();
        self::assertCount(2, self::storedLines($orphanSessionId));
        self::runRecoveryTick($nextOwner);

        self::assertSame([], self::storedLines($orphanSessionId));
        self::assertSame($leftLines, self::storedLines($leftSessionId));
        self::assertSame($rightLines, self::storedLines($rightSessionId));
    }

    /** A scheduled pass also finds a voucher-only cart whose session disappeared after the owner started. */
    public function testScheduledRecoveryFindsAVoucherWithoutProductLines(): void
    {
        $this->carts()->onTick();
        $sessionId = self::guestSessionId();
        self::createVoucher('SUMMER25', 10, true);
        $this->carts()->applyVoucher($sessionId, 'SUMMER25');

        self::asOwner(new SessionsLibraryAgent(), static fn () => Hilos::$db->sessions[$sessionId]->actions->delete());
        self::assertNotNull(self::storedVoucherId($sessionId));
        self::runRecoveryTick($this->carts());

        self::assertNull(self::storedVoucherId($sessionId));
    }

    /** Both cursors return to the start of their original pass while new pages of cart rows are still arriving. */
    public function testRecoveryWrapsAtTheOriginalLastCartEvenWhenNewCartsArrive(): void
    {
        $voucher = self::createVoucher('SUMMER25', 10, true);
        $product = self::product(ProductKind::STOCK);
        self::stock($product, self::warehouse(), 1);
        $firstSessionId = self::guestSessionId();
        Hilos::$db->sessionProductCarts->actions->add($firstSessionId, 0, $product);
        $this->carts()->applyVoucher($firstSessionId, 'SUMMER25');
        for ($i = 1; $i < 60; $i++) {
            $sessionId = self::guestSessionId();
            Hilos::$db->sessionProductCarts->actions->add($sessionId, 0, $product);
            $this->carts()->applyVoucher($sessionId, 'SUMMER25');
        }
        $this->carts()->onTick();

        self::asOwner(new SessionsLibraryAgent(), static fn () => Hilos::$db->sessions[$firstSessionId]->actions->delete());
        $lateOrphanId = self::guestSessionId();
        Hilos::$db->sessionProductCarts->actions->add($lateOrphanId, 0, $product);
        $this->carts()->applyVoucher($lateOrphanId, 'SUMMER25');
        self::asOwner(new SessionsLibraryAgent(), static fn () => Hilos::$db->sessions[$lateOrphanId]->actions->delete());

        for ($page = 0; $page < 3; $page++) {
            for ($i = 0; $i < 25; $i++) {
                $sessionId = self::guestSessionId();
                Hilos::$db->sessionProductCarts->actions->add($sessionId, 0, $product);
                $this->carts()->applyVoucher($sessionId, 'SUMMER25');
            }
            self::assertCount(1, self::storedLines($firstSessionId));
            self::assertSame($voucher->id, self::storedVoucherId($firstSessionId));
            self::runRecoveryTick($this->carts());
            self::assertCount(1, self::storedLines($lateOrphanId));
            self::assertSame($voucher->id, self::storedVoucherId($lateOrphanId));
        }

        self::assertSame([], self::storedLines($firstSessionId));
        self::assertNull(self::storedVoucherId($firstSessionId));
    }

    /** A sweep frame carrying another payload is a contract fault, and no cart is touched. */
    public function testASweepFrameWithAnotherPayloadIsRefused(): void
    {
        $bulbs = self::product(ProductKind::STOCK);
        self::stock($bulbs, self::warehouse(), 5);
        Hilos::$db->sessionProductCarts->actions->add(101, 0, $bulbs);
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

        Hilos::$db->sessionProductCarts->actions->add(101, 0, $bulbs);
        Hilos::$db->sessionProductCarts->actions->add(101, 0, $vase);
        Hilos::$db->sessionProductCarts->actions->step(101, 0, $bulbs, CartLineRules::STEP_UP);
        Hilos::$db->sessionProductCarts->actions->setQuantity(101, 0, $bulbs, 3);
        Hilos::$db->sessionProductCarts->actions->remove(101, 0, $vase);
        Database::sql('DELETE FROM hilos_session WHERE id = ?', [101]);
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
        Hilos::$db->sessionProductCarts->actions->add(101, 0, $bulbs);
        Hilos::$db->sessionProductCarts->actions->add(101, 0, $vase);
        $stored = self::storedLines(101);

        self::assertRefusedToAnotherWriter(
            CreateNotAllowedException::class,
            static fn () => Hilos::$db->sessionProductCarts->actions->add(102, 0, $bulbs),
        );
        self::assertRefusedToAnotherWriter(
            WriteNotAllowedException::class,
            static fn () => Hilos::$db->sessionProductCarts->actions->add(101, 0, $bulbs),
        );
        self::assertRefusedToAnotherWriter(
            WriteNotAllowedException::class,
            static fn () => Hilos::$db->sessionProductCarts->actions->step(101, 0, $bulbs, CartLineRules::STEP_UP),
        );
        self::assertRefusedToAnotherWriter(
            WriteNotAllowedException::class,
            static fn () => Hilos::$db->sessionProductCarts->actions->setQuantity(101, 0, $bulbs, 3),
        );
        self::assertRefusedToAnotherWriter(
            WriteNotAllowedException::class,
            static fn () => Hilos::$db->sessionProductCarts->actions->remove(101, 0, $bulbs),
        );
        ExecutionContext::run(new ExecutionFrame(agentId: AgentType::ECOMMERCE_SHOP), static function () use ($bulbs, $vase): void {
            Hilos::$db->sessionProductCarts->actions->add(101, 0, $vase);
            Hilos::$db->sessionProductCarts->actions->setQuantity(101, 0, $bulbs, 1);
            Hilos::$db->sessionProductCarts->actions->remove(102, 0, $bulbs);
            self::assertSame(0, Hilos::$db->sessionProductCarts->actions->deleteForSessions([102]));
        });

        Database::sql('DELETE FROM hilos_session WHERE id = ?', [101]);
        self::assertRefusedToAnotherWriter(
            WriteNotAllowedException::class,
            static fn () => Hilos::$db->sessionProductCarts->actions->deleteForSessions([101]),
        );
        self::assertSame($stored, self::storedLines(101));
    }

    /** @param GuestCartsLibraryAgent $owner Library whose current cron rule is due */
    private static function runRecoveryTick(GuestCartsLibraryAgent $owner): void
    {
        self::assertNotNull($owner->armCronRuleForTest('guest_cart_sweep_recovery'));
        $owner->onTick();
    }

    /**
     * @param string $sourceKey Collection whose removal should fail
     * @param int $sourceId Row whose remove announcement should fail
     * @param callable(): void $remove Handler expected to surface the failure
     */
    private static function assertRemovalFails(string $sourceKey, int $sourceId, callable $remove): void
    {
        $subscriber = new class ($sourceKey, (string)$sourceId) implements SourceMirrorSubscriberInterface {
            public bool $armed = true;

            public function __construct(private readonly string $sourceKey, private readonly string $sourceId)
            {
            }

            public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
            {
                if ($this->armed && $change->sourceKey === $this->sourceKey
                    && $change->sourceId === $this->sourceId && $change->mutationType === TableMutationType::Delete
                ) {
                    $this->armed = false;
                    throw new RuntimeException('Injected guest cart deletion failure');
                }
            }
        };
        SourceChangeBus::subscribe($subscriber);
        try {
            $remove();
            self::fail('The injected failure must reach the caller');
        } catch (SourceChangeSubscriberException $failure) {
            self::assertStringContainsString('Injected guest cart deletion failure', $failure->getMessage());
        } finally {
            $subscriber->armed = false;
        }
    }

    /** @return int ID of a real anonymous session */
    private static function guestSessionId(): int
    {
        $session = self::asOwner(
            new SessionsLibraryAgent(),
            static fn () => Hilos::$db->sessions->actions->createAnonymous(SessionToken::mint()),
        );

        return $session->id ?? self::fail('The session was not persisted');
    }

    /**
     * @param string $token Guest session's cookie token
     * @param string $code Voucher fixture code
     * @param bool $applied Desired association
     * @return CommandReplyDTO The reply to the hydrated command
     */
    private function voucherFixture(string $token, string $code, bool $applied): CommandReplyDTO
    {
        $previousRouter = Hilos::$sr;
        $router = new EcommerceShopSignalRouter();
        Hilos::initSignalRouter($router);
        try {
            $name = EcommerceShopCommandConstants::GUEST_CART_SET_VOUCHER;
            $query = new GuestCartSetVoucherCommandData($token, $code, $applied);
            $request = new CommandRequestDTO('guest-voucher-fixture', $name, $query->toArray());
            $this->carts()->onSignalCommand($router->createCommandPayloadDTO($name, $request), 'command', $name);

            $replies = [];
            while (($signal = $router->getNextQueuedSignal()) !== null) {
                if ($signal->data instanceof CommandReplyDTO) {
                    $replies[] = $signal->data;
                }
            }
        } finally {
            Hilos::$sr = $previousRouter;
        }
        self::assertCount(1, $replies);
        self::assertSame($request->correlationId, $replies[0]->correlationId);

        return $replies[0];
    }

    /**
     * @param string $code Catalog code
     * @param int $discountPercent Whole percent
     * @param bool $active Whether the code can be applied
     * @return Voucher Persisted catalog fixture
     */
    private static function createVoucher(string $code, int $discountPercent, bool $active): Voucher
    {
        return self::asOwner(
            new VouchersLibraryAgent(),
            static fn () => Hilos::$db->vouchers->actions->create($code, $discountPercent, $active),
        );
    }

    /**
     * @template T
     * @param AbstractAgent $owner Library that owns the fixture
     * @param callable(): T $write Fixture operation
     * @return T Result from the operation
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
     * @param int $sessionId Browser session ID
     * @return ?int Stored voucher ID, or null for an empty slot
     */
    private static function storedVoucherId(int $sessionId): ?int
    {
        $rows = Database::sql('SELECT voucher_id FROM session_voucher_cart WHERE session_id = ?', [$sessionId])->rows();

        return $rows === [] ? null : (int)$rows[0]['voucher_id'];
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
     * @param class-string<ValidationException> $expected Refusal class
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
