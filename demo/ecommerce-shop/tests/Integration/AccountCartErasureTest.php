<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Core\Router\EcommerceShopSignalRouter;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Hilos;
use Hilos\Auth\AccountOperation\AccountErasureCapture;
use Hilos\Auth\AccountOperation\AccountErasureCoverageGuard;
use Hilos\Auth\AccountOperation\AccountErasureOpening;
use Hilos\Auth\AccountOperation\AccountErasureParticipantCatalog;
use Hilos\Constants\TimeConstants;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * The shop's part of a person's erasure through the person's agent and the common step, without a live coordinator.
 *
 * Real commits: the step's transaction is the one under test. The cart lines are written past the person's agent, so
 * the agent finds them in the database rather than in its memory; the products are the seeded catalog's. A case leaves
 * nothing behind: its cart lines, its erasure and its people are removed after it, and the memory that read them is
 * forgotten.
 */
final class AccountCartErasureTest extends IntegrationTestCase
{
    /** @var list<int> People the case created, removed after it */
    private array $userIds = [];

    /** Mounts the shop's router, so the frames the steps send have somewhere to queue. */
    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initSignalRouter(new EcommerceShopSignalRouter());
    }

    /** Removes the case's cart lines, erasure and people, then forgets what was read of them, even after a failed case. */
    protected function tearDown(): void
    {
        try {
            if ($this->userIds !== []) {
                $people = implode(',', $this->userIds);
                Database::sqlRun("DELETE FROM `user_product_cart` WHERE `user_id` IN ({$people})");
                Database::sqlRun("DELETE FROM `hilos_account_operation` WHERE `root_user_id` IN ({$people})");
                Database::sqlRun("DELETE FROM `hilos_account_deletion` WHERE `user_id` IN ({$people})");
                Database::sqlRun("DELETE FROM `hilos_user` WHERE `id` IN ({$people})");
                Hilos::$db->forgetLoadedDataForTest();
            }
        } finally {
            $this->userIds = [];
            Hilos::$sr = null;
            parent::tearDown();
        }
    }

    /** The part erases the person's two cart lines and none of another person's, and commits exactly that result. */
    public function testThePartErasesThePersonsCartAndCommitsTheExactResult(): void
    {
        [$roses, $tulips] = self::seededProducts();
        $personId = $this->person('Cart leaving');
        $otherId = $this->person('Cart staying');
        self::line($personId, $roses, 2);
        self::line($personId, $tulips, 1);
        self::line($otherId, $roses, 3);
        $kept = self::storedLines($otherId);
        $operation = $this->openErasure($personId);
        $this->capture($operation, $personId);
        $this->drainSignals();

        $result = $this->runPart($operation, $personId);

        self::assertPayload($result, ['rows' => ['user_product_cart' => 2], 'files' => []]);
        self::assertSame([], self::storedLines($personId));
        self::assertSame($kept, self::storedLines($otherId));
    }

    /** A person without a cart gets a part that names no family and erases nothing. */
    public function testAPersonWithoutACartGetsAnEmptyResult(): void
    {
        [$roses] = self::seededProducts();
        $personId = $this->person('Cart never filled');
        $otherId = $this->person('Cart of a neighbour');
        self::line($otherId, $roses, 1);
        $kept = self::storedLines($otherId);
        $operation = $this->openErasure($personId);
        $this->capture($operation, $personId);
        $this->drainSignals();

        $result = $this->runPart($operation, $personId);

        self::assertPayload($result, ['rows' => [], 'files' => []]);
        self::assertSame($kept, self::storedLines($otherId));
    }

    /** The shop's installation answers for every collection it mounts, the cart included. */
    public function testTheErasureCoversEveryMountedCollection(): void
    {
        AccountErasureCoverageGuard::assertMountedCollectionsCovered();

        $this->addToAssertionCount(1);
    }

    /**
     * Creates a person through the person table's writer, removed after the case.
     *
     * @param string $name Name of the person
     * @return int Id of the new person
     */
    private function person(string $name): int
    {
        $userId = (int)Hilos::$db->users->actions->createWithName($name)->id;
        $this->userIds[] = $userId;

        return $userId;
    }

    /**
     * @param int $userId Root of the erasure circle
     * @return AccountOperation Open erasure with census assignments at its current stage
     */
    private function openErasure(int $userId): AccountOperation
    {
        $library = $this->usersLibrary();
        $effectiveAt = TimeHelper::nowMs() + TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND;
        $this->underAgent($library, static fn () => Hilos::$db->accountDeletions->actions->request($userId, $effectiveAt));
        $request = Hilos::$db->accountDeletions->liveOf($userId);
        self::assertNotNull($request);
        self::assertTrue($this->underAgent($this->personAgent($userId), static fn (): bool => $request->actions->start()));
        $catalog = AccountErasureParticipantCatalog::installation();
        $operation = $this->underAgent(
            $this->sessionsLibrary(), static fn (): ?AccountOperation => AccountErasureOpening::open($request, $catalog)->operation,
        );
        self::assertNotNull($operation);

        return $operation;
    }

    /**
     * @param AccountOperation $operation Erasure whose personal capture is needed
     * @param int $userId Account of its circle
     */
    private function capture(AccountOperation $operation, int $userId): void
    {
        $catalog = AccountErasureParticipantCatalog::installation();
        $this->underAgent($this->sessionsLibrary(), static fn (): array => AccountErasureCapture::recordAfterStart($operation, $catalog));
        $assignment = $this->assignmentByKey($operation, AccountErasureCapture::personalKey($userId));
        $agent = $this->personAgent($userId);
        $this->underAgent($agent, static fn (): AccountOperationResult => $agent->captureForErasure($assignment->id));
    }

    /**
     * Runs the person's part under the person's agent, with the expected part the census wrote.
     *
     * @param AccountOperation $operation Erasure that expects the person's part
     * @param int $userId Account whose part runs
     * @return AccountOperationResult Committed result
     */
    private function runPart(AccountOperation $operation, int $userId): AccountOperationResult
    {
        $assignment = $this->assignmentByKey($operation, "expect:personal:hilos_user:{$userId}:{$userId}");
        $agent = $this->personAgent($userId);

        return $this->underAgent($agent, static fn (): AccountOperationResult => $agent->eraseAccountPersonalPart($assignment->id));
    }

    /**
     * @param AccountOperation $operation Operation to search
     * @param string $key Exact assignment key
     * @return AccountOperationAssignment The expected assignment
     */
    private function assignmentByKey(AccountOperation $operation, string $key): AccountOperationAssignment
    {
        $assignments = [];
        foreach (Hilos::$db->accountOperationAssignments->forOperation($operation->id) as $assignment) {
            if ($assignment->assignmentKey === $key) {
                $assignments[] = $assignment;
            }
        }
        self::assertCount(1, $assignments, $key);

        return $assignments[0];
    }

    /**
     * @param AccountOperationResult $result Result returned by the step
     * @param array{rows: array<string, int>, files: list<int>} $expected Exact stored payload
     */
    private static function assertPayload(AccountOperationResult $result, array $expected): void
    {
        $rows = Database::sql('SELECT `payload_json` FROM `hilos_account_operation_result` WHERE `id` = ?', [$result->id])->rows();
        self::assertCount(1, $rows);
        self::assertSame($expected, json_decode($rows[0]['payload_json'], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame($rows[0]['payload_json'], $result->payloadJson);
    }

    /**
     * @return list<int> Ids of two products of the seeded catalog
     */
    private static function seededProducts(): array
    {
        $products = array_map(
            static fn (array $row): int => (int)$row['id'],
            Database::sql('SELECT `id` FROM `product` ORDER BY `id` LIMIT 2')->rows(),
        );
        self::assertCount(2, $products, 'The seeded catalog is reset with the test database');

        return $products;
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
        Database::sqlRun(
            'INSERT INTO `user_product_cart` (`user_id`, `product_id`, `quantity`) VALUES (?, ?, ?)',
            [$userId, $productId, $quantity],
        );
    }

    /**
     * @param int $userId Person whose cart it is
     * @return list<array{productId: int, quantity: int}> The lines of the person's cart read past the ORM, in the order
     *     they were added; empty when it has none
     */
    private static function storedLines(int $userId): array
    {
        $lines = [];
        foreach (Database::sql(
            'SELECT `product_id`, `quantity` FROM `user_product_cart` WHERE `user_id` = ? ORDER BY `id`',
            [$userId],
        )->rows() as $row) {
            $lines[] = ['productId' => (int)$row['product_id'], 'quantity' => (int)$row['quantity']];
        }

        return $lines;
    }
}
