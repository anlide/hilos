<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountMergeCoordinator;
use Hilos\Auth\AccountOperation\AccountMergeRefusal;
use Hilos\Auth\AccountOperation\AccountOperationCircle;
use Hilos\Auth\AccountOperation\DTO\AccountMergeHandoverSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAnswerSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAskSignalData;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\StepUp\StepUpMessages;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Auth\StepUp\StepUpSettingsCatalog;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Database;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Hilos;
use Hilos\Pages\Users\AbstractHilosUserPage;
use Hilos\Runtime\State\Item\HilosSessionRotation;
use Hilos\Runtime\State\Item\HilosSessionToastStack;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\DTO\AccountMergeSignalData;
use Hilos\Users\SecondFactorFate;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

require_once __DIR__ . '/AccountMergeCommandRouteIntegrationTest.php';

/** Real prepared opening and owner commits, with the existing page's handover answer on the browser wire. */
final class AccountMergeBrowserStartIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    private const int SURVIVOR = 11;
    private const int LOSER = 12;
    private const int THIRD = 13;
    private const int CONFIRMATION_TTL_SECONDS = 900;
    private const array EXTRA_TABLES = ['hilos_passkey_credential', 'hilos_account_merge_copy'];
    private BrowserMergeHolder $holder;
    private BrowserMergePage $page;
    private string $previousApp;
    private ?SignalRouter $previousRouter;
    private ?RtContext $previousRt;
    private ?SettingsAccessor $previousSetting;
    /** @var list<SignalDTO> Owner frames retained while the page's immediate answer is consumed */
    private array $pending = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApp = Hilos::appClass();
        $this->previousRouter = Hilos::$sr;
        $this->previousRt = Hilos::$rt;
        $this->previousSetting = Hilos::$setting;
        $this->stubs(true);
        $this->stubs(false);
        Schema::reset();
        Schema::initialize();
        Hilos::$sr = new SignalRouter();
        Hilos::$setting = new SettingsAccessor(StepUpSettingsCatalog::class);
        BrowserMergeHilos::initBrowser();
        AccountMergeRouteTestAdminAudience::$ids = [AccountMergeCommandRouteIntegrationTest::ADMIN_USER_ID];
        $rt = new AccountMergeRouteTestRtContext();
        $rt->mountFeatureRuntime([new AuthFeature()]);
        $rt->configure();
        RtTruthSourceRegistry::registerDaemon(HilosSessionRotation::RT_COLLECTION);
        RtTruthSourceRegistry::registerDaemon(HilosSessionToastStack::RT_COLLECTION);
        $rt->bindStateCollectionNames();
        Hilos::$rt = $rt;
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        $this->holder = new BrowserMergeHolder();
        OwnershipDeclaration::claimAll($this->holder);
        $this->page = new BrowserMergePage($this->holder);
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (11, 'Survivor'), (12, 'Loser'), (13, 'Third'), (14, 'Admin')");
        self::seedSession(AccountMergeCommandRouteIntegrationTest::ADMIN_SESSION_TOKEN,
            AccountMergeCommandRouteIntegrationTest::ADMIN_USER_ID, TimeHelper::nowMs(), null);
        $this->confirmMerge();
    }

    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            $this->releasePersonAgents();
            TruthSourceRegistry::unregisterAgent($this->holder->getId());
            RtTruthSourceRegistry::unregisterAgent($this->holder->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->holder->getId()));
            RtTruthSourceRegistry::unregisterDaemon(HilosSessionRotation::RT_COLLECTION);
            RtTruthSourceRegistry::unregisterDaemon(HilosSessionToastStack::RT_COLLECTION);
            $this->stubs(true);
            Schema::reset();
        } finally {
            $this->previousApp::initBrowser();
            Hilos::resetBrowser();
            Hilos::$sr = $this->previousRouter;
            Hilos::$rt = $this->previousRt;
            Hilos::$setting = $this->previousSetting;
            SourceChangeBus::reset();
            parent::tearDown();
        }
    }

    /**
     * @param string $userId Person addressed by the registered route
     * @return AbstractUserAgent Real personal owner
     */
    protected function newPersonAgent(string $userId): AbstractUserAgent
    {
        return new BrowserMergeUser($userId);
    }

    public function testAcceptanceNamesThePreparingOperationWithoutFoldingOrReportingAnOutcome(): void
    {
        $ack = $this->start($this->request(password: PasswordFate::NONE->value,
            secondFactor: SecondFactorFate::BOTH->value, survivorProtected: true, loserProtected: false));
        self::assertInstanceOf(PageActionSuccessSignalData::class, $ack);
        $operationId = $ack->reply['operationId'];
        self::assertGreaterThan(0, $operationId);
        self::assertSame(['action' => HilosSignalConstants::HILOS_USER_MERGE, 'requestId' => 'browser-merge-1',
            'reply' => ['operationId' => $operationId]], $ack->toArray());
        $row = $this->row($operationId);
        self::assertSame('preparing', $row['stage']);
        self::assertSame(self::SURVIVOR, (int)$row['root_user_id']);
        self::assertSame(self::LOSER, (int)$row['loser_user_id']);
        self::assertSame(['passwordFate' => 'none', 'secondFactorFate' => 'both',
            'expectedSurvivorHasSecondFactor' => true, 'expectedLoserHasSecondFactor' => false], json_decode($row['merge_choice'], true));
        self::assertNull($row['outcome']);
        self::assertNull($row['finished_at']);
        self::assertSame([], Database::sql('SELECT * FROM `hilos_user_merge`')->rows());
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_operation_result`')->rows());
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_merge_copy`')->rows());
        self::assertCount(2, $this->pending);
    }

    public function testAnExactRepeatNamesTheSameOpeningAndAChangedChoiceRefuses(): void
    {
        $first = $this->start($this->request())->reply;
        $duties = Database::sql('SELECT * FROM `hilos_account_operation_assignment` ORDER BY `id`')->rows();
        $this->pending = [];
        self::assertSame($first, $this->start($this->request())->reply);
        self::assertSame($duties, Database::sql('SELECT * FROM `hilos_account_operation_assignment` ORDER BY `id`')->rows());
        $refusal = $this->start($this->request(password: PasswordFate::NONE->value));
        self::assertInstanceOf(PageActionErrorSignalData::class, $refusal);
        self::assertSame('Loser (#12) is already being merged', $refusal->reason);
        self::assertArrayNotHasKey('reply', $refusal->toArray());
        self::assertCount(1, Database::sql('SELECT `id` FROM `hilos_account_operation`')->rows());
        self::assertSame($duties, Database::sql('SELECT * FROM `hilos_account_operation_assignment` ORDER BY `id`')->rows());
    }

    /** @return iterable<string, array{string, string}> Refusals before an opening */
    public static function preflights(): iterable
    {
        yield 'no-step-up' => ['no-step-up', StepUpMessages::EXPIRED];
        yield 'self' => ['self', 'The merge requires two different positive account ids.'];
        yield 'missing-survivor' => ['missing-survivor', 'No such user: 99'];
        yield 'missing-loser' => ['missing-loser', 'No such user: 99'];
        yield 'folded-survivor' => ['folded-survivor', 'Survivor (#11) was itself merged into Third (#13)'];
        yield 'folded-loser' => ['folded-loser', 'Loser (#12) was already merged into Third (#13)'];
        yield 'erase-survivor' => ['erase-survivor', 'Survivor (#11) is being deleted'];
        yield 'erase-loser' => ['erase-loser', 'Loser (#12) is being deleted'];
        yield 'password' => ['password', 'Both accounts have a password: choose which one stays'];
        yield 'password-fate' => ['password-fate', 'Unknown account-merge password fate'];
        yield 'second-factor-fate' => ['second-factor-fate', 'Unknown account-merge second-factor fate'];
    }

    /**
     * @param string $case Preflight to invalidate
     * @param string $reason Exact browser refusal
     */
    #[DataProvider('preflights')]
    public function testPreflightRefusalsKeepTheirWordsAndOpenNothing(string $case, string $reason): void
    {
        if ($case === 'no-step-up') {
            Database::sqlRun('DELETE FROM `hilos_step_up`');
        }
        if (str_starts_with($case, 'folded-')) {
            Database::sqlRun('INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (?, ?, 1)',
                [$case === 'folded-survivor' ? self::SURVIVOR : self::LOSER, self::THIRD]);
        }
        if (str_starts_with($case, 'erase-')) {
            $id = $case === 'erase-survivor' ? self::SURVIVOR : self::LOSER;
            $this->asHolder(static fn () => Hilos::$db->accountOperations->actions->recordErasure(1, $id,
                AccountOperationCircle::fromArray([$id], $id)));
        }
        if ($case === 'password') {
            self::seedIdentity(self::SURVIVOR, IdentityType::PASSWORD, 'survivor@example.test');
            self::seedIdentity(self::LOSER, IdentityType::PASSWORD, 'loser@example.test');
        }
        $ack = $this->start($this->request(
            survivor: match ($case) { 'self' => self::LOSER, 'missing-survivor' => 99, default => self::SURVIVOR },
            loser: $case === 'missing-loser' ? 99 : self::LOSER,
            password: $case === 'password-fate' ? 'unknown' : null,
            secondFactor: $case === 'second-factor-fate' ? 'unknown' : null,
        ));
        self::assertInstanceOf(PageActionErrorSignalData::class, $ack);
        self::assertSame($reason, $ack->reason);
        self::assertArrayNotHasKey('reply', $ack->toArray());
        self::assertSame([], Database::sql("SELECT * FROM `hilos_account_operation` WHERE `kind` = 'merge'")->rows());
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_operation_assignment`')->rows());
        self::assertSame([], $this->pending);
    }

    public function testAChangedProtectionSnapshotIsAcceptedAndThenRefusedByTheOwners(): void
    {
        $ack = $this->start($this->request(survivorProtected: true));
        self::assertInstanceOf(PageActionSuccessSignalData::class, $ack);
        $operationId = $ack->reply['operationId'];
        $this->settle();
        $operation = Hilos::$db->accountOperations[$operationId];
        self::assertSame(AccountMergeRefusal::ProtectionChanged, AccountMergeCoordinator::refusalOf($operation));
        self::assertSame('Second-factor protection changed. Review the accounts and choose again.',
            AccountMergeCoordinator::refusalOf($operation)->template());
        self::assertSame('not_started', $this->row($operationId)['outcome']);
        self::assertSame([], Database::sql('SELECT * FROM `hilos_user_merge`')->rows());
    }

    public function testADriveFailureAfterOpeningStillAcceptsTheSavedOperationAndLogsTheCause(): void
    {
        $this->holder->failDrive = true;
        $ack = $this->start($this->request());
        self::assertInstanceOf(PageActionSuccessSignalData::class, $ack);
        $operationId = $ack->reply['operationId'];
        self::assertSame('preparing', $this->row($operationId)['stage']);
        self::assertNull($this->row($operationId)['finished_at']);
        self::assertSame(["Accepted account merge #{$operationId} could not be driven: Private drive fault."], $this->holder->errors);
        self::assertSame($ack->reply, $this->start($this->request())->reply);
        self::assertCount(1, Database::sql('SELECT `id` FROM `hilos_account_operation`')->rows());
    }

    public function testTheSameFaultWithoutAnOpeningIsARefusal(): void
    {
        $this->holder->failOpening = true;
        $ack = $this->start($this->request());
        self::assertInstanceOf(PageActionErrorSignalData::class, $ack);
        self::assertSame(SignalConstants::ACTION_FAILED_REASON, $ack->reason);
        self::assertArrayNotHasKey('reply', $ack->toArray());
        self::assertSame([], Database::sql('SELECT `id` FROM `hilos_account_operation`')->rows());
        self::assertCount(1, $this->holder->errors);
        self::assertStringContainsString('Private drive fault.', $this->holder->errors[0]);
    }

    public function testANewStartAfterNotStartedOpensANewOperation(): void
    {
        $first = $this->start($this->request(survivorProtected: true))->reply['operationId'];
        $this->settle();
        self::assertSame('not_started', $this->row($first)['outcome']);
        $ack = $this->start($this->request());
        self::assertInstanceOf(PageActionSuccessSignalData::class, $ack);
        self::assertNotSame($first, $ack->reply['operationId']);
        self::assertSame('preparing', $this->row($ack->reply['operationId'])['stage']);
        self::assertCount(2, Database::sql('SELECT `id` FROM `hilos_account_operation`')->rows());
    }

    public function testAStartAfterCompletedKeepsTheMergedRefusalAndCreatesNothing(): void
    {
        $operationId = $this->start($this->request())->reply['operationId'];
        $this->settle();
        self::assertSame('completed', $this->row($operationId)['outcome']);
        $ack = $this->start($this->request());
        self::assertInstanceOf(PageActionErrorSignalData::class, $ack);
        self::assertSame('Loser (#12) was already merged into Survivor (#11)', $ack->reason);
        self::assertCount(1, Database::sql('SELECT `id` FROM `hilos_account_operation`')->rows());
    }

    public function testThePreparedHandlerHasNoProductionCallerAndTheOldRouteIsUnchanged(): void
    {
        self::assertSame(AccountMergeSignalData::class, AbstractSessionsLibraryAgent::AGENT_SIGNALS[HilosSignalConstants::HILOS_ACCOUNT_MERGE]);
        $source = file_get_contents(dirname(__DIR__, 2) . '/backend/Auth/Library/AbstractSessionsLibraryAgent.php');
        $route = explode('case HilosSignalConstants::HILOS_ACCOUNT_MERGE:', $source, 2)[1];
        $route = explode('case ', $route, 2)[0];
        self::assertStringContainsString('$this->handleAccountMergeRequest($merge);', $route);
        self::assertStringNotContainsString('handlePreparedAccountMergeRequest', $route);
        self::assertSame(1, substr_count($source, 'handlePreparedAccountMergeRequest('));
        self::assertNotContains('handlePreparedAccountMergeRequest', AbstractSessionsLibraryAgent::AGENT_SIGNALS);
    }

    /**
     * @param int $survivor Survivor id
     * @param int $loser Loser id
     * @param ?string $password Password fate on the wire
     * @param ?string $secondFactor Second-factor fate on the wire
     * @param bool $survivorProtected Survivor's browser snapshot
     * @param bool $loserProtected Loser's browser snapshot
     * @return AccountMergeSignalData Existing handover request
     */
    private function request(
        int $survivor = self::SURVIVOR,
        int $loser = self::LOSER,
        ?string $password = null,
        ?string $secondFactor = null,
        bool $survivorProtected = false,
        bool $loserProtected = false,
    ): AccountMergeSignalData {
        return new AccountMergeSignalData($survivor, $loser, $password, $secondFactor, $survivorProtected, $loserProtected,
            HilosSignalConstants::HILOS_ACCOUNT_MERGE_DONE, AccountMergeCommandRouteIntegrationTest::ACCEPT_KEY, 'browser-merge-1',
            HilosSignalConstants::HILOS_USER_MERGE, null);
    }

    /**
     * @param AccountMergeSignalData $request Existing browser request, delivered only through the fixture's prepared entry
     * @return PageActionSuccessSignalData|PageActionErrorSignalData Real page ack, after serializing the writer's answer
     */
    private function start(AccountMergeSignalData $request): PageActionSuccessSignalData|PageActionErrorSignalData
    {
        $this->asHolder(fn () => $this->holder->startBrowserMerge($request));
        $answer = null;
        foreach ($this->frames() as $frame) {
            if ($frame->data->data instanceof HandoverAnswerSignalData) {
                self::assertNull($answer, 'Exactly one immediate browser answer is owed.');
                $answer = HandoverAnswerSignalData::fromArray($frame->data->data->toArray());
            } else {
                $this->pending[] = $frame;
            }
        }
        self::assertNotNull($answer);
        $this->page->onSignalAgent(new AgentSignalData($answer), $this->holder->getId(), HilosSignalConstants::HILOS_ACCOUNT_MERGE_DONE);
        $ack = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($ack);
        self::assertInstanceOf(WebSocketSignalData::class, $ack->data);
        self::assertSame(AccountMergeCommandRouteIntegrationTest::ACCEPT_KEY, $ack->data->targetAcceptKey);
        self::assertContains($ack->signalName->getName(), [SignalConstants::ACTION_SUCCESS, SignalConstants::ACTION_ERROR]);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());

        return $ack->data->data;
    }

    /** Drives only queued real handlers, with no elapsed-clock wait or manually forged participant result. */
    private function settle(): void
    {
        for ($round = 0; $round < 20; $round++) {
            $frames = $this->pending;
            $this->pending = [];
            array_push($frames, ...$this->frames());
            if ($frames === []) {
                return;
            }
            foreach ($frames as $queued) {
                $frame = SignalDTO::fromArray(json_decode(json_encode($queued->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
                $name = $frame->signalName->getName();
                $data = Hilos::$sr->createAgentSignalPayloadDTO($name, $frame->data);
                $sender = SignalSource::describe($frame->signalSource);
                if ($data->data instanceof AccountOperationAnswerSignalData) {
                    $this->asHolder(fn () => $this->holder->onSignalAgent($data, $sender, $name));
                } else {
                    self::assertTrue($data->data instanceof AccountOperationAskSignalData || $data->data instanceof AccountMergeHandoverSignalData);
                    $this->asPerson((int)$data->data->ownerIndex,
                        static fn (AbstractUserAgent $agent) => $agent->onSignalAgent($data, $sender, $name));
                }
            }
        }
        self::fail('The finite owner exchange must settle.');
    }

    /** @return list<SignalDTO> Account-operation transport and the immediate handover answer, without unrelated sync */
    private function frames(): array
    {
        $frames = [];
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($frame->data instanceof AgentSignalData && ($frame->data->data instanceof HandoverAnswerSignalData
                || $frame->data->data instanceof AccountOperationAskSignalData || $frame->data->data instanceof AccountOperationAnswerSignalData
                || $frame->data->data instanceof AccountMergeHandoverSignalData)) {
                $frames[] = $frame;
            }
        }

        return $frames;
    }

    /** Seeds the real session-bound confirmation the prepared browser entry asks for. */
    private function confirmMerge(): void
    {
        Database::sqlRun('INSERT INTO `hilos_step_up` (`session_token_hash`, `user_id`, `operation`, `confirmed_until`, `created_at`) '
            . 'VALUES (?, ?, ?, ?, ?)', [ProtectedModeRuntime::hashSessionToken(AccountMergeCommandRouteIntegrationTest::ADMIN_SESSION_TOKEN),
                AccountMergeCommandRouteIntegrationTest::ADMIN_USER_ID, StepUpOperationKey::MERGE_ACCOUNTS,
                TimeHelper::nowMs() + self::CONFIRMATION_TTL_SECONDS * TimeConstants::MS_PER_SECOND, TimeHelper::nowMs()]);
    }

    /**
     * @template T
     * @param callable(): T $work Work at the canonical session holder
     * @return T Work result
     */
    private function asHolder(callable $work): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: $this->holder->getId()), $work);
    }

    /**
     * @param int $operationId Operation to observe past the ORM cache
     * @return array<string, mixed> Persisted row
     */
    private function row(int $operationId): array
    {
        return Database::sql('SELECT * FROM `hilos_account_operation` WHERE `id` = ?', [$operationId])->row();
    }

    /** @param bool $down Whether to drop the extra tables before or after the case */
    private function stubs(bool $down): void
    {
        foreach ($down ? array_reverse(self::EXTRA_TABLES) : self::EXTRA_TABLES as $table) {
            // external-boundary: migration stub filenames carry the direction suffix
            $suffix = $down ? '_down' : '';
            Database::sqlRun((string)file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . $suffix . '.sql'));
        }
    }
}

/** Exposes the prepared browser entry; faults stay entirely within this test subclass. */
final class BrowserMergeHolder extends AbstractSessionsLibraryAgent
{
    public bool $failDrive = false;
    public bool $failOpening = false;
    /** @var list<string> Agent errors at the drive or refusal boundary */
    public array $errors = [];

    /** @param AccountMergeSignalData $request Prepared browser request */
    public function startBrowserMerge(AccountMergeSignalData $request): void
    {
        $this->handlePreparedAccountMergeRequest($request);
    }

    /**
     * @param int $survivorUserId Survivor account
     * @param int $loserUserId Loser account
     * @param AccountMergeChoice $choice Immutable browser choices
     * @return AccountOperation Real opening unless the pre-opening fault is injected
     */
    protected function openAccountMerge(int $survivorUserId, int $loserUserId, AccountMergeChoice $choice): AccountOperation
    {
        if ($this->failOpening) {
            throw new RuntimeException('Private drive fault.');
        }

        return parent::openAccountMerge($survivorUserId, $loserUserId, $choice);
    }

    /** @param AccountOperation $operation Real committed opening */
    protected function driveAccountMerge(AccountOperation $operation): void
    {
        if ($this->failDrive) {
            throw new RuntimeException('Private drive fault.');
        }
        parent::driveAccountMerge($operation);
    }

    /** @param string $message Error recorded by the production handler */
    protected function logAgentError(string $message): void
    {
        $this->errors[] = $message;
    }
}

/** The production page owns the gatekeeper and the existing answer route. */
final class BrowserMergePage extends AbstractHilosUserPage
{
}

/** Real owner handlers with no project extension. */
final class BrowserMergeUser extends AbstractUserAgent
{
}

/** Existing browser authority with real indexed personal and canonical session-holder routes. */
abstract class BrowserMergeHilos extends AccountMergeRouteTestHilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_USER => [AgentRegistryKey::WORKER => BrowserMergeUser::class, AgentRegistryKey::INDEXED => true],
        HilosAgentType::HILOS_SESSIONS_LIBRARY => [AgentRegistryKey::WORKER => BrowserMergeHolder::class],
    ];
}
