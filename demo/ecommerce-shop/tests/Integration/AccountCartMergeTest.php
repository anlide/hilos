<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Closure;
use Demo\EcommerceShop\Agents\GuestCartsLibraryAgent;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Core\Router\DTO\GuestCartTransferOfferSignalData;
use Demo\EcommerceShop\Core\Router\EcommerceShopSignalRouter;
use Demo\EcommerceShop\Database\Actions\CartLineRules;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\ShopGuestCartTransfer;
use Demo\EcommerceShop\Database\Entity\Item\UserProductCart;
use Demo\EcommerceShop\Database\Entity\Item\UserVoucherCart;
use Demo\EcommerceShop\Groups\CartGroup;
use Demo\EcommerceShop\Hilos;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountMergeCoordinator;
use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergeOpening;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountOperationAnswerKind;
use Hilos\Auth\AccountOperation\AccountOperationOutcome;
use Hilos\Auth\AccountOperation\DTO\AccountMergeHandoverSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountMergePersonalHandoverSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAnswerSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAskSignalData;
use Hilos\Auth\Session\SessionToken;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalNameInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\SignalTypeInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Sync\DTO\DbSyncSignalDataInterface;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\Exception\SqlRuntime\LockWaitTimeoutException;
use Hilos\Database\View\Item\AccountOperationAssignment;
use mysqli;
use mysqli_sql_exception;
use PHPUnit\Framework\Attributes\DataProvider;

/** Real shop participants, persisted merge duties and commits on the integration database. */
final class AccountCartMergeTest extends IntegrationTestCase
{
    /** @var list<int> People created for the current case */
    private array $userIds = [];

    /** @var list<int> Guest sessions bound by the current case */
    private array $sessionIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initSignalRouter(new EcommerceShopSignalRouter());
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->sessionIds as $sessionId) {
                Database::sqlRun('DELETE FROM session_voucher_cart WHERE session_id = ?', [$sessionId]);
                Database::sqlRun('DELETE FROM hilos_session WHERE id = ?', [$sessionId]);
            }
            foreach ($this->userIds as $userId) {
                Database::sqlRun('DELETE FROM hilos_account_operation WHERE loser_user_id = ?', [$userId]);
                Database::sqlRun('DELETE FROM hilos_user_merge WHERE user_id = ?', [$userId]);
                Database::sqlRun('DELETE FROM shop_guest_cart_transfer WHERE user_id = ?', [$userId]);
                Database::sqlRun('DELETE FROM user_product_cart WHERE user_id = ?', [$userId]);
                Database::sqlRun('DELETE FROM user_voucher_cart WHERE user_id = ?', [$userId]);
                Database::sqlRun('DELETE FROM hilos_user WHERE id = ?', [$userId]);
            }
            Hilos::$db->forgetLoadedDataForTest();
        } finally {
            Hilos::$sr = null;
            parent::tearDown();
        }
    }

    public function testCartJoinsAboveStockKeepsOneUniquePieceAndDeletesOnlyAfterReceipt(): void
    {
        $survivor = $this->person('Surviving cart');
        $loser = $this->person('Giving cart');
        $other = $this->person('Unrelated cart');
        $bulk = self::product('pine-bark-5-l');
        $unique = self::product('morning-meadow');
        $stock = Hilos::$db->productWarehouseStocks->storedUnitsOfProduct($bulk);
        $voucher = self::voucher('SPRING10');
        $pending = $this->bindGuestVoucher($survivor, self::voucher('WEDDING15'));
        self::putVoucher($survivor, $voucher);
        self::putVoucher($other, $voucher);
        self::putLine($survivor, $bulk, $stock);
        self::putLine($survivor, $unique, 1);
        self::putLine($loser, $bulk, 3);
        self::putLine($loser, $unique, 1);
        self::putLine($other, $bulk, 2);

        $handover = $this->readyPersonal($survivor, $loser);
        $data = $handover->data->data;
        $give = self::duty($data->operationId, AccountMergeDuty::PersonalGive);
        $receive = self::duty($data->operationId, AccountMergeDuty::PersonalReceive);
        $counts = json_encode(['rows' => [UserProductCart::_table => 2]], JSON_THROW_ON_ERROR);
        self::assertSame(['lines' => [
            ['productId' => $bulk, 'quantity' => 3],
            ['productId' => $unique, 'quantity' => 1],
        ], 'voucherId' => null], $data->rows);
        self::assertSame($survivor, (int)$data->ownerIndex);
        self::assertSame($receive->id, $data->assignmentId);
        self::assertSame([$bulk => 3, $unique => 1], self::lines($loser));
        self::assertNull(self::payloadOf($give));

        $this->deliverWithCommitProbe($handover, $receive);
        self::assertSame($counts, self::payloadOf($receive));
        self::assertNull(self::payloadOf($give));
        self::assertSame([$bulk => 3, $unique => 1], self::lines($loser));
        self::assertSame([$bulk => $stock + 3, $unique => 1], self::lines($survivor));
        self::assertSame($voucher, self::storedVoucher($survivor));
        self::assertFalse(ShopGuestCartTransfer::getById($pending)?->voucher_superseded);

        $cartStates = $this->finish($data->operationId);
        self::assertSame([
            [CartGroup::forUser($survivor), [
                'userId' => $survivor,
                'lines' => [['productId' => $bulk, 'quantity' => $stock + 3], ['productId' => $unique, 'quantity' => 1]],
                'voucherId' => $voucher,
            ]],
            [CartGroup::forUser($loser), ['userId' => $loser, 'lines' => [], 'voucherId' => null]],
        ], $cartStates);
        self::assertSame($counts, self::payloadOf($give));
        self::assertSame([], self::lines($loser));
        self::assertSame([$bulk => 2], self::lines($other));
        self::assertSame($voucher, self::storedVoucher($other));

        $this->dispatch($handover);
        $this->dispatch(self::ask($give));
        self::assertSame(AccountOperationAnswerKind::Done, $this->answerWithoutCartWrite($receive));
        self::assertSame(AccountOperationAnswerKind::Done, $this->answerWithoutCartWrite($give));
        self::assertSame($counts, self::payloadOf($receive));
        self::assertSame($counts, self::payloadOf($give));
        self::assertSame([$bulk => $stock + 3, $unique => 1], self::lines($survivor));
        self::assertFalse(Hilos::$sr->hasQueuedSignals());
    }

    /** @return iterable<string, array{bool}> Whether the guest bind precedes the personal receive */
    public static function voucherOrder(): iterable
    {
        yield 'guest bind before join' => [true];
        yield 'guest bind after join' => [false];
    }

    #[DataProvider('voucherOrder')]
    public function testVoucherOnlyJoinKeepsSavedInactiveIdAndSupersedesEarlierGuestChoices(bool $bindFirst): void
    {
        $survivor = $this->person('Voucher cart staying');
        $loser = $this->person('Voucher cart leaving');
        $saved = self::voucher('WELCOME5');
        $guest = self::voucher('WEDDING15');
        self::assertFalse(Hilos::$db->vouchers[$saved]->active);
        self::putVoucher($loser, $saved);
        $owner = $this->personAgent($survivor);
        $this->underAgent($owner, static fn () => $owner->applyVoucher('SPRING10'));
        $transfer = $bindFirst ? $this->bindGuestVoucher($survivor, $guest) : null;

        $handover = $this->readyPersonal($survivor, $loser);
        $data = $handover->data->data;
        self::assertSame(['lines' => [], 'voucherId' => $saved], $data->rows);
        $this->dispatch($handover);
        $this->finish($data->operationId);

        $counts = json_encode(['rows' => [UserVoucherCart::_table => 1]], JSON_THROW_ON_ERROR);
        self::assertSame($counts, self::payloadOf(self::duty($data->operationId, AccountMergeDuty::PersonalReceive)));
        self::assertSame($counts, self::payloadOf(self::duty($data->operationId, AccountMergeDuty::PersonalGive)));
        self::assertSame($saved, self::storedVoucher($survivor));
        self::assertNull(self::storedVoucher($loser));
        $transfer ??= $this->bindGuestVoucher($survivor, $guest);
        self::assertSame($bindFirst, ShopGuestCartTransfer::getById($transfer)?->voucher_superseded);
        self::assertFalse(Hilos::$db->shopGuestCartTransfers->storedReceipt($transfer, $survivor));

        $this->dispatch(self::frame(
            new SignalSource(SignalSource::AGENT, GuestCartsLibraryAgent::AGENT_TYPE),
            EcommerceShopSignalConstants::GUEST_CART_TRANSFER_OFFER,
            new GuestCartTransferOfferSignalData($transfer, $survivor),
        ));

        self::assertTrue(Hilos::$db->shopGuestCartTransfers->storedReceipt($transfer, $survivor));
        self::assertSame($bindFirst ? $saved : $guest, self::storedVoucher($survivor));

        $this->underAgent($owner, static fn () => $owner->applyVoucher('SPRING10'));
        self::assertSame(self::voucher('SPRING10'), self::storedVoucher($survivor));
    }

    public function testEmptyCartCarriesBothKeysAndRecordsEmptyResults(): void
    {
        $survivor = $this->person('Cart unchanged');
        $loser = $this->person('Empty cart leaving');
        $handover = $this->readyPersonal($survivor, $loser);
        $data = $handover->data->data;
        self::assertSame(['lines' => [], 'voucherId' => null], $data->rows);

        $this->dispatch($handover);
        self::assertSame([], $this->finish($data->operationId));

        self::assertSame('{"rows":{}}', self::payloadOf(self::duty($data->operationId, AccountMergeDuty::PersonalReceive)));
        self::assertSame('{"rows":{}}', self::payloadOf(self::duty($data->operationId, AccountMergeDuty::PersonalGive)));
    }

    /** @return iterable<string, array{string}> Defect in an otherwise correctly addressed snapshot */
    public static function refusedCarts(): iterable
    {
        foreach ([
            'overflow', 'missing voucher', 'missing lines', 'extra root field', 'non-list lines', 'extra line field',
            'missing line field', 'duplicate product', 'zero product', 'string product', 'string quantity', 'fractional quantity',
            'zero quantity', 'quantity above limit', 'string voucher', 'zero voucher',
        ] as $defect) {
            yield $defect => [$defect];
        }
    }

    #[DataProvider('refusedCarts')]
    public function testRefusedCartKeepsSourcesAndTheCommittedGiveWithoutPublishing(string $defect): void
    {
        $survivor = $this->person('Refused cart staying');
        $loser = $this->person('Refused cart leaving');
        $first = self::product('pine-bark-5-l');
        $last = self::product('universal-soil-mix-10-l');
        $incoming = self::voucher('SPRING10');
        $previous = self::voucher('WEDDING15');
        $limit = CartLineRules::STORED_ACCOUNT_QUANTITY_MAX;
        self::putLine($survivor, $first, 4);
        self::putLine($survivor, $last, $limit);
        self::putLine($loser, $first, 2);
        self::putLine($loser, $last, 1);
        self::putVoucher($loser, $incoming);
        self::putVoucher($survivor, $previous);
        $pending = $this->bindGuestVoucher($survivor, $previous);
        $handover = $this->readyPersonal($survivor, $loser);
        $data = $handover->data->data;
        $give = self::duty($data->operationId, AccountMergeDuty::Give);
        $before = self::payloadOf($give);
        self::assertNotNull($before);
        self::assertNotNull(self::payloadOf(self::duty($data->operationId, AccountMergeDuty::Cleanup)));

        $rows = self::refusedRows($data->rows, $defect);
        $frame = new SignalDTO($handover->signalSource, $handover->signalType, $handover->signalName, new AgentSignalData(
            new AccountMergePersonalHandoverSignalData($data->operationId, $data->stage, $data->assignmentId, $data->ownerIndex, $rows),
        ));
        $seen = self::observeFirstWrite(
            static function () use ($data, $survivor, $first): int {
                self::assertMergeAndAccountAreLocked($data->operationId, $survivor);

                return self::lines($survivor)[$first];
            },
            fn () => $this->dispatch($frame),
        );
        $receive = self::duty($data->operationId, AccountMergeDuty::PersonalReceive);
        $personalGive = self::duty($data->operationId, AccountMergeDuty::PersonalGive);

        self::assertSame($defect === 'overflow' ? 6 : null, $seen);
        $this->dispatch(self::ask($personalGive));
        self::assertSame(AccountOperationAnswerKind::Refused, $this->answerWithoutCartWrite($receive));
        self::assertSame(AccountOperationAnswerKind::Waits, $this->answerWithoutCartWrite($personalGive));
        self::assertSame([], $this->drainMerge());
        self::assertSame([$first => 4, $last => $limit], self::lines($survivor));
        self::assertSame([$first => 2, $last => 1], self::lines($loser));
        self::assertSame($previous, self::storedVoucher($survivor));
        self::assertSame($incoming, self::storedVoucher($loser));
        self::assertFalse(ShopGuestCartTransfer::getById($pending)?->voucher_superseded);
        self::assertNull(self::payloadOf($receive));
        self::assertNull(self::payloadOf($personalGive));
        self::assertSame($before, self::payloadOf($give));
        $operation = Hilos::$db->accountOperations->fresh($data->operationId);
        self::assertNotNull($operation);
        self::assertNull($operation->outcome);
        self::assertNull($operation->finishedAt);
    }

    /**
     * @param array<string, mixed> $rows Valid shop handover
     * @param string $defect Change for this case, or overflow reached by the original lines
     * @return array<string, mixed> Snapshot sent through the usual agent frame
     */
    private static function refusedRows(array $rows, string $defect): array
    {
        $lines = $rows['lines'];
        $voucher = $rows['voucherId'];
        $first = $lines[0];
        $last = $lines[1];

        return match ($defect) {
            'overflow' => $rows,
            'missing voucher' => ['lines' => $lines],
            'missing lines' => ['voucherId' => $voucher],
            'extra root field' => [...$rows, 'extra' => true],
            'non-list lines' => [...$rows, 'lines' => ['first' => $first, 'last' => $last]],
            'extra line field' => [...$rows, 'lines' => [$first, [...$last, 'extra' => true]]],
            'missing line field' => [...$rows, 'lines' => [$first, ['quantity' => 1]]],
            'duplicate product' => [...$rows, 'lines' => [$first, $first]],
            'zero product' => [...$rows, 'lines' => [$first, [...$last, 'productId' => 0]]],
            'string product' => [...$rows, 'lines' => [$first, [...$last, 'productId' => (string)$last['productId']]]],
            'string quantity' => [...$rows, 'lines' => [$first, [...$last, 'quantity' => '1']]],
            'fractional quantity' => [...$rows, 'lines' => [$first, [...$last, 'quantity' => 1.5]]],
            'zero quantity' => [...$rows, 'lines' => [$first, [...$last, 'quantity' => 0]]],
            'quantity above limit' => [...$rows, 'lines' => [$first, [...$last, 'quantity' => CartLineRules::STORED_ACCOUNT_QUANTITY_MAX + 1]]],
            'string voucher' => [...$rows, 'voucherId' => (string)$voucher],
            'zero voucher' => [...$rows, 'voucherId' => 0],
        };
    }

    private function readyPersonal(int $survivor, int $loser): SignalDTO
    {
        $this->personAgent($survivor);
        $this->personAgent($loser);
        $holder = $this->sessionsLibrary();
        $operation = $this->underAgent($holder, static fn () =>
            AccountMergeOpening::open($survivor, $loser, new AccountMergeChoice(null, null, null, null)),
        );
        $preparations = $this->underAgent($holder, static fn () => AccountMergeCoordinator::begin($operation));
        self::assertCount(2, $preparations);
        foreach ($preparations as $assignment) {
            $this->dispatch(self::ask($assignment));
        }

        $handover = null;
        for ($count = 0; ($frame = Hilos::$sr->getNextQueuedSignal()) !== null; $count++) {
            self::assertLessThan(100, $count, 'The merge must reach personal handover without replay loops.');
            if ($frame->data instanceof AgentSignalData && $frame->data->data instanceof AccountMergePersonalHandoverSignalData) {
                $handover ??= $frame;
                continue;
            }
            $this->dispatchMergeFrame($frame);
        }

        return $handover ?? self::fail('The shop must send a personal handover.');
    }

    /** @return list<array{string, array<string, mixed>}> Committed cart frames emitted by the remaining duties */
    private function finish(int $operationId): array
    {
        $cartStates = $this->drainMerge();
        $operation = Hilos::$db->accountOperations->fresh($operationId);
        self::assertSame(AccountOperationOutcome::Completed->value, $operation?->outcome);
        self::assertNotNull($operation->finishedAt);

        return $cartStates;
    }

    /** @return list<array{string, array<string, mixed>}> Cart states queued during the merge exchange */
    private function drainMerge(): array
    {
        $cartStates = [];
        for ($count = 0; ($frame = Hilos::$sr->getNextQueuedSignal()) !== null; $count++) {
            self::assertLessThan(100, $count, 'The merge exchange must settle without replay loops.');
            if ($frame->signalName->getName() === EcommerceShopSignalConstants::CART_STATE) {
                self::assertInstanceOf(WebSocketSignalData::class, $frame->data);
                self::assertNotNull($frame->data->targetGroup);
                $cartStates[] = [$frame->data->targetGroup, $frame->data->data->toArray()];
                continue;
            }
            $this->dispatchMergeFrame($frame);
        }

        return $cartStates;
    }

    private function dispatchMergeFrame(SignalDTO $frame): void
    {
        if ($frame->data instanceof AgentSignalData && ($frame->data->data instanceof AccountOperationAskSignalData
            || $frame->data->data instanceof AccountOperationAnswerSignalData
            || $frame->data->data instanceof AccountMergeHandoverSignalData
            || $frame->data->data instanceof AccountMergePersonalHandoverSignalData)) {
            $this->dispatch($frame);
        }
    }

    private function dispatch(SignalDTO $queued): void
    {
        $router = new EcommerceShopSignalRouter();
        $frame = SignalDTO::fromJson($queued->toJson());
        $destinations = $router->getDestinations($frame);
        self::assertCount(1, $destinations);
        $destination = $destinations[0];
        self::assertInstanceOf(AgentDestination::class, $destination);
        self::assertInstanceOf(AgentSignalData::class, $frame->data);
        if ($destination->agentType === HilosAgentType::HILOS_SESSIONS_LIBRARY) {
            self::assertNull($destination->agentIndex);
            $agent = $this->sessionsLibrary();
        } else {
            self::assertSame(HilosAgentType::HILOS_USER, $destination->agentType);
            $agent = $this->personAgent((int)$destination->agentIndex);
        }
        $name = $frame->signalName->getName();
        $data = $router->createAgentSignalPayloadDTO($name, $frame->data);
        $this->underAgent($agent, static fn () => $agent->onSignalAgent($data, SignalSource::describe($frame->signalSource), $name));
    }

    private function deliverWithCommitProbe(SignalDTO $frame, AccountOperationAssignment $receive): void
    {
        $router = Hilos::$sr;
        $probe = new class ($router, $receive->id) extends SignalRouter {
            public int $cartFrames = 0;

            public function __construct(private readonly SignalRouter $router, private readonly int $assignmentId)
            {
                parent::__construct();
            }

            public function queueSignal(
                SignalSourceInterface $signalSource,
                SignalTypeInterface $signalType,
                SignalNameInterface $signalName,
                SignalDataInterface $signalData,
            ): void {
                if ($signalName->getName() === EcommerceShopSignalConstants::CART_STATE) {
                    AccountCartMergeTest::assertNull(Database::runtimeTransactionLevel(DatabaseConnectionDefaults::PRIMARY_INDEX));
                    AccountCartMergeTest::assertNotNull(Hilos::$db->accountOperationResults->byAssignment($this->assignmentId));
                    $this->cartFrames++;
                }
                $this->router->queueSignal($signalSource, $signalType, $signalName, $signalData);
            }
        };
        Hilos::initSignalRouter($probe);
        try {
            $this->dispatch($frame);
            self::assertSame(1, $probe->cartFrames);
        } finally {
            Hilos::initSignalRouter($router);
        }
    }

    /**
     * @param Closure(): int $read Snapshot to read when the first line is written
     * @param Closure(): void $dispatch Attempt to receive the handover
     * @return ?int Changed quantity witnessed inside PRIMARY before rollback, if any
     */
    private static function observeFirstWrite(Closure $read, Closure $dispatch): ?int
    {
        $probe = new class implements SourceMirrorSubscriberInterface {
            public ?Closure $read = null;
            public ?int $quantity = null;

            public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
            {
                if ($change->sourceKey === EcommerceShopDbContext::userProductCarts && $this->read !== null) {
                    $read = $this->read;
                    $this->read = null;
                    $this->quantity = $read();
                }
            }
        };
        $probe->read = $read;
        SourceChangeBus::subscribe($probe);
        try {
            $dispatch();

            return $probe->quantity;
        } finally {
            $probe->read = null;
        }
    }

    private static function assertMergeAndAccountAreLocked(int $operationId, int $survivor): void
    {
        $config = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        $peer = new mysqli($config->host, $config->user, $config->password, $config->database, $config->port, $config->socket);
        try {
            $peer->begin_transaction();
            foreach ([
                'SELECT id FROM hilos_account_operation WHERE id = ? FOR UPDATE NOWAIT' => $operationId,
                'SELECT id FROM hilos_user WHERE id = ? LOCK IN SHARE MODE NOWAIT' => $survivor,
            ] as $sql => $id) {
                try {
                    $peer->execute_query($sql, [$id])->free();
                    self::fail('The participant wrote a line before taking both merge locks.');
                } catch (mysqli_sql_exception $failure) {
                    self::assertSame(LockWaitTimeoutException::MYSQL_ERROR_CODE, $failure->getCode());
                }
            }
        } finally {
            $peer->close();
        }
    }

    private function answerWithoutCartWrite(AccountOperationAssignment $duty): AccountOperationAnswerKind
    {
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertNotSame(EcommerceShopSignalConstants::CART_STATE, $frame->signalName->getName());
            if ($frame->data instanceof DbSyncSignalDataInterface) {
                self::assertNotContains($frame->data->collectionKey, [
                    EcommerceShopDbContext::userProductCarts,
                    EcommerceShopDbContext::userVoucherCarts,
                    EcommerceShopDbContext::shopGuestCartTransfers,
                ]);
            }
            if ($frame->data instanceof AgentSignalData && $frame->data->data instanceof AccountOperationAnswerSignalData) {
                if ($frame->data->data->assignmentId === $duty->id) {
                    $answer = $frame->data->data->answer;
                    $this->dispatch($frame);

                    return $answer;
                }
                $this->dispatch($frame);
            }
        }
        self::fail('The personal cart participant must answer its duty.');
    }

    private static function duty(int $operationId, AccountMergeDuty $duty): AccountOperationAssignment
    {
        return Hilos::$db->accountOperationAssignments->byIdentity($operationId, AccountMergeStage::Go->value, $duty->value)
            ?? self::fail('Missing merge duty ' . $duty->value);
    }

    private static function payloadOf(AccountOperationAssignment $assignment): ?string
    {
        return Hilos::$db->accountOperationResults->byAssignment($assignment->id)?->payloadJson;
    }

    private static function ask(AccountOperationAssignment $assignment): SignalDTO
    {
        return self::frame(
            new SignalSource(SignalSource::AGENT, HilosAgentType::HILOS_SESSIONS_LIBRARY),
            HilosSignalConstants::HILOS_USER_ACCOUNT_OPERATION_STEP,
            new AccountOperationAskSignalData($assignment->operationId, $assignment->stage, $assignment->id, $assignment->owner->ownerIndex),
        );
    }

    private static function frame(SignalSource $source, string $name, SignalDataInterface $data): SignalDTO
    {
        return new SignalDTO($source, new SignalType(SignalTypeConstants::AGENT_SIGNAL), new SignalName($name), new AgentSignalData($data));
    }

    private function person(string $name): int
    {
        $id = Hilos::$db->users->actions->createWithName($name)->id ?? self::fail('The cart owner was not persisted.');
        $this->userIds[] = $id;

        return $id;
    }

    private function bindGuestVoucher(int $userId, int $voucherId): int
    {
        $holder = $this->sessionsLibrary();

        return $this->underAgent($holder, function () use ($userId, $voucherId): int {
            $session = Hilos::$db->sessions->actions->createAnonymous(SessionToken::mint());
            $sessionId = $session->id ?? self::fail('The guest session was not persisted.');
            $this->sessionIds[] = $sessionId;
            Database::sqlRun('INSERT INTO session_voucher_cart (session_id, cart_epoch, voucher_id) VALUES (?, ?, ?)', [$sessionId, 0, $voucherId]);
            $session->actions->bindUser($userId);
            $id = Database::sql('SELECT id FROM shop_guest_cart_transfer WHERE session_id = ? AND cart_epoch = 0', [$sessionId])->field('id');
            self::assertNotNull($id);

            return (int)$id;
        });
    }

    private static function product(string $slug): int
    {
        $id = Database::sql('SELECT id FROM product WHERE slug = ?', [$slug])->field('id');
        self::assertNotNull($id);

        return (int)$id;
    }

    private static function voucher(string $code): int
    {
        $id = Database::sql('SELECT id FROM voucher WHERE code = ?', [$code])->field('id');
        self::assertNotNull($id);

        return (int)$id;
    }

    private static function putLine(int $userId, int $productId, int $quantity): void
    {
        Database::sqlRun('INSERT INTO user_product_cart (user_id, product_id, quantity) VALUES (?, ?, ?)', [$userId, $productId, $quantity]);
    }

    private static function putVoucher(int $userId, int $voucherId): void
    {
        Database::sqlRun('INSERT INTO user_voucher_cart (user_id, voucher_id) VALUES (?, ?)', [$userId, $voucherId]);
    }

    /** @return array<int, int> Persisted quantities by product ID in cart order */
    private static function lines(int $userId): array
    {
        $quantities = [];
        foreach (Database::sql('SELECT product_id, quantity FROM user_product_cart WHERE user_id = ? ORDER BY id', [$userId])->rows() as $row) {
            $quantities[(int)$row['product_id']] = (int)$row['quantity'];
        }

        return $quantities;
    }

    private static function storedVoucher(int $userId): ?int
    {
        $id = Database::sql('SELECT voucher_id FROM user_voucher_cart WHERE user_id = ?', [$userId])->field('voucher_id');

        return $id === null ? null : (int)$id;
    }
}
