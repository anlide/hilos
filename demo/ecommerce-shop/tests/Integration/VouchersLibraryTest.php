<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\VouchersLibraryAgent;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\View\Item\Voucher;
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
use PHPUnit\Framework\Attributes\DataProvider;

/** Mounts the shop before the rollback scope and writes vouchers under their library's claim. */
abstract class VouchersLibraryEnvironment extends RollbackIntegrationTestCase
{
    private ?VouchersLibraryAgent $library = null;
    private ?int $executionToken = null;

    /** Starts with a cold ORM before opening the rollback scope. */
    protected function prepareIntegrationEnvironment(): void
    {
        Database::initialize(initHilos: Hilos::$db === null);
        Hilos::$db->forgetLoadedDataForTest();
        $this->library = new VouchersLibraryAgent();
        OwnershipDeclaration::claimAll($this->library);
        $this->executionToken = ExecutionContext::push(new ExecutionFrame(agentId: $this->library->getId()));
    }

    /** Releases the library claim and restores the caller after rollback. */
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

/** Exercises the voucher actions against the shop schema inside the managed rollback scope. */
#[IntegrationTest]
final class VouchersLibraryTest extends VouchersLibraryEnvironment
{
    /** @return array<string, array{string, class-string<ValidationException>, string}> Rejected codes and their exact refusals */
    public static function invalidCodes(): array
    {
        return [
            'spaces only' => ['   ', EmptyValueException::class, 'Voucher code cannot be empty'],
            '33 characters' => [str_repeat('A', 33), ValueTooLongException::class, 'Voucher code exceeds maximum length of 32 characters'],
            'hyphen' => ['SPRING-10', ValidationException::class, 'Voucher code may only contain Latin letters and digits'],
            'internal space' => ['SPRING 10', ValidationException::class, 'Voucher code may only contain Latin letters and digits'],
            'Cyrillic' => ['ВЕСНА10', ValidationException::class, 'Voucher code may only contain Latin letters and digits'],
            'accented Latin' => ['PRÏNT5', ValidationException::class, 'Voucher code may only contain Latin letters and digits'],
        ];
    }

    /** @return array<string, array{int}> Percentages outside the allowed range */
    public static function invalidDiscounts(): array
    {
        return ['zero' => [0], 'above 100' => [101], 'negative' => [-5]];
    }

    /** Creation stores the normalized code, the discount, and the requested flag. */
    public function testCreateStoresTheNormalizedCodeDiscountAndFlag(): void
    {
        $active = Hilos::$db->vouchers->actions->create('  spring10 ', 10, true);
        $inactive = Hilos::$db->vouchers->actions->create('AUTUMN20', 20, false);

        self::assertVoucherState($active, 'SPRING10', 10, true);
        self::assertVoucherState($inactive, 'AUTUMN20', 20, false);
    }

    /** An edit saves all three fields on the same voucher. */
    public function testUpdateStoresTheNewCodeDiscountAndFlag(): void
    {
        $voucher = Hilos::$db->vouchers->actions->create('SPRING10', 10, true);

        $voucher->actions->update('  autumn20 ', 20, false);

        self::assertVoucherState($voucher, 'AUTUMN20', 20, false);
    }

    /** The library can disable and re-enable a voucher, and repeating the flag leaves its row as it was. */
    public function testSetActiveChangesOnlyTheFlagAndCanBeRepeated(): void
    {
        $voucher = Hilos::$db->vouchers->actions->create('SPRING10', 10, true);

        $voucher->actions->setActive(false);
        self::assertVoucherState($voucher, 'SPRING10', 10, false);

        $voucher->actions->setActive(true);
        self::assertVoucherState($voucher, 'SPRING10', 10, true);

        $voucher->actions->setActive(true);
        self::assertVoucherState($voucher, 'SPRING10', 10, true);
    }

    /** A code already in use is refused on creation and on renaming, even in another case. */
    public function testDuplicateCodeIsRefusedOnCreateAndUpdate(): void
    {
        $existing = Hilos::$db->vouchers->actions->create('SPRING10', 10, true);
        $voucher = Hilos::$db->vouchers->actions->create('AUTUMN20', 20, false);
        $before = self::storedCount();

        self::assertRefused(
            DuplicateValueException::class,
            'A voucher with this code already exists',
            static fn () => Hilos::$db->vouchers->actions->create('spring10', 30, false),
        );
        self::assertRefused(
            DuplicateValueException::class,
            'A voucher with this code already exists',
            static fn () => $voucher->actions->update('spring10', 30, true),
        );

        self::assertSame($before, self::storedCount());
        self::assertVoucherState($existing, 'SPRING10', 10, true);
        self::assertVoucherState($voucher, 'AUTUMN20', 20, false);
    }

    /** A voucher may keep its own code when the caller enters it in another case. */
    public function testChangingTheCaseOfTheSameCodeKeepsItsNormalizedForm(): void
    {
        $voucher = Hilos::$db->vouchers->actions->create('SPRING10', 10, true);

        $voucher->actions->update('spring10', 10, true);

        self::assertVoucherState($voucher, 'SPRING10', 10, true);
    }

    /** Both ends of the discount range are accepted. */
    public function testDiscountAcceptsOneAndOneHundred(): void
    {
        $voucher = Hilos::$db->vouchers->actions->create('SPRING10', 1, true);
        self::assertVoucherState($voucher, 'SPRING10', 1, true);

        $voucher->actions->update('AUTUMN20', 100, false);
        self::assertVoucherState($voucher, 'AUTUMN20', 100, false);
    }

    /**
     * An invalid percentage refuses the whole write, including otherwise valid edits.
     *
     * @param int $discountPercent Percentage outside the allowed range
     */
    #[DataProvider('invalidDiscounts')]
    public function testInvalidDiscountIsRefusedOnCreateAndUpdate(int $discountPercent): void
    {
        $voucher = Hilos::$db->vouchers->actions->create('SPRING10', 10, true);
        $before = self::storedCount();

        self::assertRefused(
            ValidationException::class,
            'Discount must be a whole percent from 1 to 100',
            static fn () => Hilos::$db->vouchers->actions->create('AUTUMN20', $discountPercent, false),
        );
        self::assertRefused(
            ValidationException::class,
            'Discount must be a whole percent from 1 to 100',
            static fn () => $voucher->actions->update('AUTUMN20', $discountPercent, false),
        );

        self::assertSame($before, self::storedCount());
        self::assertVoucherState($voucher, 'SPRING10', 10, true);
    }

    /**
     * An invalid code refuses the whole write, including otherwise valid edits.
     *
     * @param string $code Invalid code
     * @param class-string<ValidationException> $expected Exact refusal class
     * @param string $sentence Refusal shown to the caller
     */
    #[DataProvider('invalidCodes')]
    public function testInvalidCodeIsRefusedOnCreateAndUpdate(string $code, string $expected, string $sentence): void
    {
        $voucher = Hilos::$db->vouchers->actions->create('SPRING10', 10, true);
        $before = self::storedCount();

        self::assertRefused($expected, $sentence, static fn () => Hilos::$db->vouchers->actions->create($code, 20, false));
        self::assertRefused($expected, $sentence, static fn () => $voucher->actions->update($code, 20, false));

        self::assertSame($before, self::storedCount());
        self::assertVoucherState($voucher, 'SPRING10', 10, true);
    }

    /** The app agent may repeat the current flag. Creation, editing, and changing the flag require the library's claim. */
    public function testAnotherWriterIsRefusedUnlessTheFlagAlreadyMatches(): void
    {
        $voucher = Hilos::$db->vouchers->actions->create('SPRING10', 10, true);
        $before = self::storedCount();

        ExecutionContext::run(new ExecutionFrame(agentId: AgentType::ECOMMERCE_SHOP), static fn () => $voucher->actions->setActive(true));
        self::assertVoucherState($voucher, 'SPRING10', 10, true);

        self::assertRefusedToAnotherWriter(
            CreateNotAllowedException::class,
            "Create operation not allowed: agent 'ecommerce_shop' is not allowed to create in table 'vouchers'.",
            static fn () => Hilos::$db->vouchers->actions->create('AUTUMN20', 20, false),
        );
        $writeRefusal = "Write operation not allowed: agent 'ecommerce_shop' is not a truth source for table 'vouchers' item '{$voucher->id}'.";
        self::assertRefusedToAnotherWriter(
            WriteNotAllowedException::class,
            $writeRefusal,
            static fn () => $voucher->actions->update('AUTUMN20', 20, false),
        );
        self::assertRefusedToAnotherWriter(
            WriteNotAllowedException::class,
            $writeRefusal,
            static fn () => $voucher->actions->setActive(false),
        );

        self::assertSame($before, self::storedCount());
        self::assertVoucherState($voucher, 'SPRING10', 10, true);
    }

    /**
     * Checks both storage and the original loaded item, whose fields a refused save must preserve.
     *
     * @param Voucher $voucher Original loaded item
     * @param string $code Expected normalized code
     * @param int $discountPercent Expected whole percentage
     * @param bool $active Expected active flag
     */
    private static function assertVoucherState(Voucher $voucher, string $code, int $discountPercent, bool $active): void
    {
        self::assertNotNull($voucher->id);
        self::assertSame(
            [['code' => $code, 'discount_percent' => (string)$discountPercent, 'active' => (string)(int)$active]],
            self::storedRows($voucher->id),
        );
        self::assertSame($code, $voucher->code);
        self::assertSame($discountPercent, $voucher->discountPercent);
        self::assertSame($active, $voucher->active);
    }

    /**
     * @param class-string<ValidationException> $expected Exact refusal class
     * @param string $sentence Refusal shown to the caller
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
     * @param class-string<HilosException> $expected Exact refusal class
     * @param string $sentence Write guard's refusal
     * @param callable(): mixed $write Write to refuse
     */
    private static function assertRefusedToAnotherWriter(string $expected, string $sentence, callable $write): void
    {
        try {
            ExecutionContext::run(new ExecutionFrame(agentId: AgentType::ECOMMERCE_SHOP), $write);
        } catch (HilosException $e) {
            self::assertSame($expected, $e::class);
            self::assertSame($sentence, $e->getMessage());

            return;
        }

        self::fail("Expected {$expected}: {$sentence}");
    }

    /**
     * @param int $id Voucher id
     * @return list<array<string, mixed>> Persisted voucher fields read independently of the ORM
     */
    private static function storedRows(int $id): array
    {
        return Database::sql('SELECT code, discount_percent, active FROM voucher WHERE id = ?', [$id])->rows();
    }

    /** @return int Number of persisted vouchers, read independently of the ORM */
    private static function storedCount(): int
    {
        return (int)Database::sql('SELECT COUNT(*) AS stored FROM voucher')->field('stored');
    }
}
