<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountMergePreparation;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountOperationKind;
use Hilos\Auth\AccountOperation\DTO\AccountMergeHandoverSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAnswerSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAskSignalData;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Database;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Schema\Schema;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Hilos;
use Hilos\Pages\Users\AbstractHilosUserPage;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\State\Item\HilosSessionRotation;
use Hilos\Runtime\State\Item\HilosSessionToastStack;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\AccountMergeProgress;
use Hilos\Users\AccountMergeProgressResolver;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\DTO\AccountMergeCancelActionDTO;
use Hilos\Users\DTO\AccountMergeCancelSignalData;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/AccountMergeCoordinatorIntegrationTest.php';
require_once __DIR__ . '/AccountMergeCommandRouteIntegrationTest.php';

/** Real page handover, holder commits and personal release handlers, with explicitly delivered transport. */
final class AccountMergeCancelActionIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    private const int SURVIVOR = 7;
    private const int LOSER = 9;
    private const array EXTRA_TABLES = ['hilos_passkey_credential', 'hilos_account_merge_copy'];
    private MergeCoordinatorHolder $holder;
    private AccountMergeCancelTestPage $page;
    private string $previousApp;
    private ?SignalRouter $previousRouter;
    private ?RtContext $previousRt;

    /** Mounts the real owner topology and an administrator's existing connection fixture. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApp = Hilos::appClass();
        $this->previousRouter = Hilos::$sr;
        $this->previousRt = Hilos::$rt;
        $this->stubs(true);
        $this->stubs(false);
        Schema::reset();
        Schema::initialize();
        Hilos::$db = new HilosSessionTestDbContext();
        Hilos::$db->configure();
        AccountMergeCancelTestHilos::initBrowser();
        Hilos::$sr = new SignalRouter();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        AccountMergeProgressResolver::forgetAll();
        $this->holder = new MergeCoordinatorHolder();
        OwnershipDeclaration::claimAll($this->holder);
        $this->page = new AccountMergeCancelTestPage(new AccountMergeCancelPageAgent());
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (7, 'Survivor'), (9, 'Loser'), (14, 'Administrator')");
        self::seedIdentity(self::LOSER, IdentityType::MAGIC_LINK, 'loser@example.test');
        self::seedSession(AccountMergeCommandRouteIntegrationTest::ADMIN_SESSION_TOKEN,
            AccountMergeCommandRouteIntegrationTest::ADMIN_USER_ID, 1735689600000, null);
        AccountMergeRouteTestAdminAudience::$ids = [AccountMergeCommandRouteIntegrationTest::ADMIN_USER_ID];
        $rt = new AccountMergeCancelTestRtContext();
        $rt->mountFeatureRuntime([new AuthFeature()]);
        $rt->configure();
        RtTruthSourceRegistry::registerDaemon(HilosSessionRotation::RT_COLLECTION);
        RtTruthSourceRegistry::registerDaemon(HilosSessionToastStack::RT_COLLECTION);
        $rt->bindStateCollectionNames();
        Hilos::$rt = $rt;
    }

    /** Releases the test's claims and restores process globals after all real commits. */
    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            $this->stubs(true);
            Schema::reset();
        } finally {
            $this->releasePersonAgents();
            TruthSourceRegistry::unregisterAgent($this->holder->getId());
            RtTruthSourceRegistry::unregisterAgent($this->holder->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->holder->getId()));
            RtTruthSourceRegistry::unregisterDaemon(HilosSessionRotation::RT_COLLECTION);
            RtTruthSourceRegistry::unregisterDaemon(HilosSessionToastStack::RT_COLLECTION);
            AccountMergeRouteTestAdminAudience::$ids = [AccountMergeCommandRouteIntegrationTest::ADMIN_USER_ID];
            Hilos::$sr = $this->previousRouter;
            Hilos::$rt = $this->previousRt;
            $this->previousApp::initBrowser();
            Hilos::resetBrowser();
            SourceChangeBus::reset();
            AccountMergeProgressResolver::forgetAll();
            parent::tearDown();
        }
    }

    /**
     * @param string $userId Indexed personal owner
     * @return AbstractUserAgent Production personal handlers under the registered fixture class
     */
    protected function newPersonAgent(string $userId): AbstractUserAgent
    {
        return new MergeCoordinatorUser($userId);
    }

    /** @return iterable<string, array{bool}> Both supported pre-fact stages */
    public static function cancellableStages(): iterable
    {
        yield 'preparing' => [false];
        yield 'go without fact' => [true];
    }

    /** @param bool $go Whether both owners have prepared before cancellation */
    #[DataProvider('cancellableStages')]
    public function testAcceptancePrecedesReleaseAndLeavesBusinessRowsAlone(bool $go): void
    {
        $operation = $go ? $this->readyGo()[0] : $this->open();
        $this->frames();
        $business = $this->businessRows();
        [$ack, $releases] = $this->request($operation->id);
        $this->assertAccepted($ack, 'cancel-1');
        self::assertSame('cancelling', $this->row($operation)['stage']);
        self::assertNull($this->row($operation)['outcome']);
        self::assertNull($this->row($operation)['finished_at']);
        self::assertSame(['release_loser', 'release_survivor'], $this->keys($releases));
        self::assertSame(['release_loser', 'release_survivor'], array_column(Database::sql(
            'SELECT `assignment_key` FROM `hilos_account_operation_assignment` WHERE `operation_id` = ? AND `stage` = ? ORDER BY `id`',
            [$operation->id, AccountMergeStage::Cancelling->value],
        )->rows(), 'assignment_key'));
        self::assertSame($business, $this->businessRows());
        $this->finishCancellation($operation, $releases);
        self::assertSame(AccountMergeProgress::END, AccountMergeProgressResolver::forOperation($operation->id)?->state);
        self::assertSame('not_started', AccountMergeProgressResolver::forOperation($operation->id)?->outcome);
        foreach ([self::LOSER, self::SURVIVOR] as $userId) {
            self::assertNull($this->asPerson($userId, static fn () => AccountMergePreparation::heldBy($userId)));
        }
        self::assertSame($business, $this->businessRows());
        self::assertNotSame($operation->id, $this->open()->id);
    }

    public function testTwoQueuedRequestsAndLaterRepeatsHaveOneDurableEffect(): void
    {
        $operation = $this->open();
        $this->frames();
        $first = $this->pageRequest($operation->id, 'cancel-1');
        $second = $this->pageRequest($operation->id, 'cancel-2');
        $this->deliver($first);
        $this->deliver($second);
        $work = [];
        $acks = [];
        foreach ($this->frames() as $frame) {
            if ($frame->data->data instanceof HandoverAnswerSignalData) {
                $acks[] = $this->answer($frame);
            } else {
                $work[] = $frame;
            }
        }
        self::assertCount(2, $acks);
        $this->assertAccepted($acks[0], 'cancel-1');
        $this->assertAccepted($acks[1], 'cancel-2');
        $assignments = Database::sql('SELECT * FROM `hilos_account_operation_assignment` ORDER BY `id`')->rows();
        self::assertSame(['release_loser', 'release_survivor', 'release_loser', 'release_survivor'], $this->keys($work));
        $this->finishCancellation($operation, array_slice($work, 0, 2));
        $results = Database::sql('SELECT * FROM `hilos_account_operation_result` ORDER BY `id`')->rows();
        foreach (array_slice($work, 2) as $release) {
            $this->deliver($release);
            $this->deliver($this->frames()[0]);
        }
        [$ack, $work] = $this->request($operation->id, 'cancel-3');
        $this->assertAccepted($ack, 'cancel-3');
        self::assertSame([], $work);
        self::assertSame($assignments, Database::sql('SELECT * FROM `hilos_account_operation_assignment` ORDER BY `id`')->rows());
        self::assertSame($results, Database::sql('SELECT * FROM `hilos_account_operation_result` ORDER BY `id`')->rows());
    }

    public function testGiveFactRefusesCancellationAndTheMergeContinuesToCompletion(): void
    {
        [$operation, $give] = $this->readyGo();
        $this->deliver($give);
        $held = $this->frames();
        $business = $this->businessRows();
        self::assertNotNull($operation->mergeFact());
        [$ack, $forward] = $this->request($operation->id);
        $this->assertRefused($ack, AbstractSessionsLibraryAgent::ACCOUNT_MERGE_CANCEL_TOO_LATE_MESSAGE);
        self::assertSame($business, $this->businessRows());
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_operation_assignment` WHERE `operation_id` = ? AND `stage` = ?',
            [$operation->id, AccountMergeStage::Cancelling->value])->rows());
        self::assertSame('go', $this->row($operation)['stage']);
        $this->finishExchange($operation, array_merge($held, $forward));
        [$ack, $work] = $this->request($operation->id, 'cancel-completed');
        $this->assertRefused($ack, AbstractSessionsLibraryAgent::ACCOUNT_MERGE_CANCEL_TOO_LATE_MESSAGE);
        self::assertSame([], $work);
        self::assertSame('completed', $this->row($operation)['outcome']);
    }

    public function testMissingAndErasureOperationsAreRefusedWithoutObligations(): void
    {
        Database::sqlRun('INSERT INTO `hilos_account_operation`'
            . ' (`id`, `kind`, `request_id`, `root_user_id`, `circle_user_ids`, `stage`, `created_at`, `updated_at`)'
            . ' VALUES (903, ?, 903, 7, ?, ?, 1735689600000, 1735689600000)',
            [AccountOperationKind::Erasure->value, '[7]', 'preparing']);
        foreach ([902, 903] as $operationId) {
            [$ack, $work] = $this->request($operationId);
            $this->assertRefused($ack, AbstractSessionsLibraryAgent::ACCOUNT_MERGE_CANCEL_MISSING_MESSAGE);
            self::assertSame([], $work);
        }
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_operation_assignment`')->rows());
    }

    /** @return iterable<string, array{bool}> Invalid administrator identities at the writer boundary */
    public static function invalidAdministrators(): iterable
    {
        yield 'rights revoked after page gate' => [false];
        yield 'impersonation' => [true];
    }

    /** @param bool $impersonated Whether the effective administrator is impersonated */
    #[DataProvider('invalidAdministrators')]
    public function testTheHolderRechecksAdministratorWithoutCreatingReleaseDuties(bool $impersonated): void
    {
        $operation = $this->open();
        $this->frames();
        $ask = $this->pageRequest($operation->id, 'cancel-1');
        if ($impersonated) {
            $session = Hilos::$db->sessions->findByToken(AccountMergeCommandRouteIntegrationTest::ADMIN_SESSION_TOKEN);
            $this->asHolder(static fn () => $session->actions->setImpersonator(self::SURVIVOR));
        } else {
            AccountMergeRouteTestAdminAudience::$ids = [];
        }
        $this->deliver($ask);
        [$answer] = $this->frames();
        $this->assertRefused($this->answer($answer), 'Only an active administrator can do this');
        self::assertSame('preparing', $this->row($operation)['stage']);
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_operation_assignment` WHERE `stage` = ?',
            [AccountMergeStage::Cancelling->value])->rows());
    }

    /** @return iterable<string, array{array<string, mixed>}> Malformed browser payloads */
    public static function malformedPayloads(): iterable
    {
        yield 'zero' => [['operationId' => 0]];
        yield 'string' => [['operationId' => '1']];
        yield 'missing' => [[]];
    }

    /** @param array<string, mixed> $payload Browser data rejected before handover */
    #[DataProvider('malformedPayloads')]
    public function testMalformedPayloadCannotReachTheHolder(array $payload): void
    {
        try {
            AccountMergeCancelActionDTO::fromArray($payload);
            self::fail('Malformed operation must be refused before handover.');
        } catch (InvalidFormatException) {
            self::assertSame([], $this->frames());
            self::assertSame([], Database::sql('SELECT * FROM `hilos_account_operation_assignment`')->rows());
        }
    }

    /**
     * @param int $operationId Existing operation address
     * @param string $requestId Browser submit correlation
     * @return SignalDTO Serialized request from the real page
     */
    private function pageRequest(int $operationId, string $requestId): SignalDTO
    {
        $this->page->beginActionDispatch($requestId);
        self::assertNull($this->page->onAction(AccountMergeCommandRouteIntegrationTest::ACCEPT_KEY,
            HilosSignalConstants::HILOS_USER_MERGE_CANCEL, AccountMergeCancelActionDTO::fromArray(['operationId' => $operationId])));
        self::assertTrue($this->page->actionReplyDeferred());
        [$ask] = $this->frames();
        self::assertSame(HilosSignalConstants::HILOS_ACCOUNT_MERGE_CANCEL, $ask->signalName->getName());

        return $ask;
    }

    /**
     * @param int $operationId Existing operation address
     * @param string $requestId Browser submit correlation
     * @return array{PageActionSuccessSignalData|PageActionErrorSignalData, list<SignalDTO>} Acceptance ack and queued owner work
     */
    private function request(int $operationId, string $requestId = 'cancel-1'): array
    {
        $this->deliver($this->pageRequest($operationId, $requestId));
        $answers = [];
        $work = [];
        foreach ($this->frames() as $frame) {
            if ($frame->data->data instanceof HandoverAnswerSignalData) {
                $answers[] = $frame;
            } else {
                $work[] = $frame;
            }
        }
        self::assertCount(1, $answers);

        return [$this->answer($answers[0]), $work];
    }

    /**
     * @param SignalDTO $frame Holder's serialized handover answer
     * @return PageActionSuccessSignalData|PageActionErrorSignalData Actual browser ack
     */
    private function answer(SignalDTO $frame): PageActionSuccessSignalData|PageActionErrorSignalData
    {
        $data = $this->transport($frame);
        self::assertInstanceOf(HandoverAnswerSignalData::class, $data->data);
        self::assertNull($data->data->successMessage);
        $this->page->onSignalAgent($data, 'holder', $frame->signalName->getName());
        $acks = [];
        while (($queued = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($queued->data instanceof WebSocketSignalData) {
                $acks[] = $queued->data->data;
            }
        }
        self::assertCount(1, $acks);

        return $acks[0];
    }

    /**
     * @param PageActionSuccessSignalData|PageActionErrorSignalData $ack Browser outcome
     * @param string $requestId Waiting submit
     */
    private function assertAccepted(PageActionSuccessSignalData|PageActionErrorSignalData $ack, string $requestId): void
    {
        self::assertInstanceOf(PageActionSuccessSignalData::class, $ack);
        self::assertSame(HilosSignalConstants::HILOS_USER_MERGE_CANCEL, $ack->action);
        self::assertSame($requestId, $ack->requestId);
        self::assertSame(['action' => HilosSignalConstants::HILOS_USER_MERGE_CANCEL, 'requestId' => $requestId], $ack->toArray());
        self::assertNull($ack->message);
        self::assertNull($ack->reply);
    }

    /**
     * @param PageActionSuccessSignalData|PageActionErrorSignalData $ack Browser outcome
     * @param string $reason Exact holder refusal
     */
    private function assertRefused(PageActionSuccessSignalData|PageActionErrorSignalData $ack, string $reason): void
    {
        self::assertInstanceOf(PageActionErrorSignalData::class, $ack);
        self::assertSame(HilosSignalConstants::HILOS_USER_MERGE_CANCEL, $ack->action);
        self::assertSame($reason, $ack->reason);
    }

    /** @return array<string, list<array<string, mixed>>> Independent business snapshot around cancellation */
    private function businessRows(): array
    {
        $rows = [];
        foreach (['hilos_user', 'hilos_identity', 'hilos_session', 'hilos_user_merge', 'hilos_account_merge_copy'] as $table) {
            $rows[$table] = Database::sql('SELECT * FROM `' . $table . '` ORDER BY 1')->rows();
        }

        return $rows;
    }

    /**
     * @param SignalDTO $queued Transport frame before IPC
     * @return AgentSignalData Payload hydrated through the registered DTO route
     */
    private function transport(SignalDTO $queued): AgentSignalData
    {
        $frame = SignalDTO::fromArray(json_decode(json_encode($queued->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        self::assertInstanceOf(AgentSignalData::class, $frame->data);

        return Hilos::$sr->createAgentSignalPayloadDTO($frame->signalName->getName(), $frame->data);
    }

    /** @param SignalDTO $queued Real request or owner frame */
    private function deliver(SignalDTO $queued): void
    {
        $data = $this->transport($queued);
        $name = $queued->signalName->getName();
        $sender = SignalSource::describe($queued->signalSource);
        if ($data->data instanceof AccountMergeCancelSignalData || $data->data instanceof AccountOperationAnswerSignalData) {
            $this->asHolder(fn () => $this->holder->onSignalAgent($data, $sender, $name));
        } else {
            $this->asPerson((int)$data->data->ownerIndex,
                static fn (AbstractUserAgent $agent) => $agent->onSignalAgent($data, $sender, $name));
        }
    }

    /**
     * @param AccountOperation $operation Merge after its transfer fact
     * @param list<SignalDTO> $pending Work held before cancellation refused
     */
    private function finishExchange(AccountOperation $operation, array $pending): void
    {
        for ($round = 0; $round < 20; $round++) {
            foreach ($pending as $frame) {
                $this->deliver($frame);
            }
            $pending = $this->frames();
            if ($pending === []) {
                self::assertSame('completed', $this->row($operation)['outcome']);

                return;
            }
        }
        self::fail('Real owner exchange must settle.');
    }
    /**
     * @param ?AccountMergeChoice $choice Immutable choices
     * @param int $survivor Survivor account
     * @param int $loser Loser account
     * @return AccountOperation Prepared holder opening
     */
    private function open(?AccountMergeChoice $choice = null, int $survivor = self::SURVIVOR, int $loser = self::LOSER): AccountOperation
    {
        return $this->asHolder(fn () => $this->holder->openMerge($survivor, $loser, $choice ?? new AccountMergeChoice(null, null, null, null)));
    }

    /** @return array{AccountOperation, SignalDTO} Real preparation exchange, stopping before the queued give */
    private function readyGo(): array
    {
        $operation = $this->open();
        foreach ($this->frames() as $ask) {
            $this->deliver($ask);
            $this->deliver($this->frames()[0]);
        }
        [$give] = $this->frames();
        self::assertSame(['give'], $this->keys([$give]));

        return [$operation, $give];
    }

    /**
     * @param AccountOperation $operation Cancelling merge
     * @param list<SignalDTO> $releases The two independently addressed release asks
     */
    private function finishCancellation(AccountOperation $operation, array $releases): void
    {
        self::assertSame(['release_loser', 'release_survivor'], $this->keys($releases));
        self::assertSame('cancelling', $this->row($operation)['stage']);
        $this->deliver($releases[0]);
        $this->deliver($this->frames()[0]);
        self::assertNull($this->row($operation)['finished_at']);
        self::assertSame([], $this->frames());
        $this->deliver($releases[1]);
        $this->deliver($this->frames()[0]);
        self::assertSame('not_started', $this->row($operation)['outcome']);
        self::assertNotNull($this->row($operation)['finished_at']);
        self::assertSame([], $this->frames());
        $this->asHolder(fn () => $this->holder->driveMerge($operation));
        self::assertSame([], $this->frames());
    }

    /** @return list<SignalDTO> Real queued envelopes, excluding unrelated sync announcements */
    private function frames(): array
    {
        $frames = [];
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($frame->data instanceof AgentSignalData
                && ($frame->data->data instanceof AccountOperationAskSignalData || $frame->data->data instanceof AccountOperationAnswerSignalData
                    || $frame->data->data instanceof AccountMergeHandoverSignalData
                    || $frame->data->data instanceof AccountMergeCancelSignalData || $frame->data->data instanceof HandoverAnswerSignalData)) {
                $frames[] = $frame;
            }
        }

        return $frames;
    }

    /**
     * @param list<SignalDTO> $frames Asks from the queue
     * @return list<string> Stored assignment keys in transport order
     */
    private function keys(array $frames): array
    {
        return array_map(static fn (SignalDTO $frame): string =>
            Hilos::$db->accountOperationAssignments[$frame->data->data->assignmentId]->assignmentKey, $frames);
    }

    /**
     * @template T
     * @param callable(): T $work Work owned by the canonical session holder
     * @return T Work result
     */
    private function asHolder(callable $work): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: $this->holder->getId()), $work);
    }

    /**
     * @param AccountOperation $operation Merge to observe independently of its cached facade
     * @return array<string, mixed> Persisted operation row
     */
    private function row(AccountOperation $operation): array
    {
        return Database::sql('SELECT * FROM `hilos_account_operation` WHERE `id` = ?', [$operation->id])->row();
    }

    /** @param bool $down Whether to drop the supporting tables in reverse dependency order */
    private function stubs(bool $down): void
    {
        foreach ($down ? array_reverse(self::EXTRA_TABLES) : self::EXTRA_TABLES as $table) {
            // external-boundary: migration stub filenames carry the direction suffix
            $suffix = $down ? '_down' : '';
            Database::sqlRun((string)file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_'
                . $table . $suffix . '.sql'));
        }
    }

}

/** User card with its inherited gates, dispatch and handover. */
final class AccountMergeCancelTestPage extends AbstractHilosUserPage
{
}

/** Existing prepared owners plus the framework user-card route and administrator audience. */
abstract class AccountMergeCancelTestHilos extends MergeCoordinatorHilos
{
    protected const string ADMIN_AUDIENCE = AccountMergeRouteTestAdminAudience::class;
    public const array PAGES = [HilosPageConstants::HILOS_USER => AccountMergeCancelTestPage::class];
}

/** Minimal page host; the page itself sends every frame through the real router. */
final class AccountMergeCancelPageAgent implements PageAgentInterface
{
    /** @return string Host agent id */
    public function getId(): string
    {
        return 'merge-cancel-page';
    }

    /** @return SignalSourceInterface Page host transport identity */
    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, $this->getId());
    }
}

/** Existing session connection shape, bound to the actual browser session primary key. */
final class AccountMergeCancelTestRtContext extends RtContext
{
    /** Mounts the existing connection fixture with its session id for the impersonation gate. */
    public function configure(): void
    {
        $connections = AccountMergeRouteTestConnections::init();
        $connections->add(AccountMergeRouteTestConnection::create(
            AccountMergeCommandRouteIntegrationTest::ACCEPT_KEY,
            AccountMergeCommandRouteIntegrationTest::ADMIN_USER_ID,
            AccountMergeCommandRouteIntegrationTest::ADMIN_SESSION_TOKEN,
            Hilos::$db->sessions->findByToken(AccountMergeCommandRouteIntegrationTest::ADMIN_SESSION_TOKEN)?->id,
        ));
        $this->_stateCollections[AccountMergeRouteTestConnections::RT_COLLECTION] = $connections;
    }
}
