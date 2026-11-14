<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Users\AccountErasure;
use Hilos\Auth\AccountOperation\AccountDataOwner;
use Hilos\Auth\AccountOperation\AccountDataOwnerDirectory;
use Hilos\Auth\AccountOperation\AccountErasurePart;
use Hilos\Auth\AccountOperation\AccountErasureProjectOwner;
use Hilos\Auth\AccountOperation\AccountErasureProjectStep;
use Hilos\Auth\AccountOperation\AccountOperationAsks;
use Hilos\Auth\AccountOperation\AccountErasureCapture;
use Hilos\Auth\AccountOperation\AccountErasureCoordinator;
use Hilos\Auth\AccountOperation\AccountErasureDeliveryCapture;
use Hilos\Auth\AccountOperation\AccountErasureFinal;
use Hilos\Auth\AccountOperation\AccountErasureOpening;
use Hilos\Auth\AccountOperation\AccountErasureParticipantCatalog;
use Hilos\Auth\AccountOperation\AccountErasureStart;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountOperationAnswerKind;
use Hilos\Auth\AccountOperation\AccountOperationExpectation;
use Hilos\Auth\AccountOperation\AccountOperationOutcome;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAnswerSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAskSignalData;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Agent\Hilos\AbstractHilosLegalAgent;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Objects;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\Schema\Schema;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\DataExport\AbstractDataExportAgent;
use Hilos\Hilos;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\Fs\FsPath;
use Hilos\Legal\Export\LegalAcceptancesExports;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Notification\Delivery\DeliveryChannelRegistry;
use Hilos\Users\AccountErasureProgress;
use Hilos\Users\AccountErasureProgressChangeSubscriber;
use Hilos\Users\AccountErasureProgressResolver;
use Hilos\Notification\Delivery\DeliveryStatus;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\SecondFactorFate;
use Hilos\Database\PhpType;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Coordinator transitions and the real holder/participant exchange over a controlled signal queue.
 * Direct transition cases keep their supporting result fixtures; exchange cases run every step
 * through its agent handler and observe progress between frames without clock waits.
 */
final class AccountErasureCoordinatorIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    private const array TABLES = [
        'hilos_passkey_credential',
        'hilos_user_verification',
        'hilos_legal_acceptance_export',
        'hilos_notification',
        'hilos_notification_delivery',
        'hilos_account_operation',
        'hilos_account_operation_assignment',
        'hilos_account_operation_result',
    ];

    /** @var list<AbstractAgent> Supporting owners whose result sets are claimed for each case */
    private array $owners;
    private string $previousApp;
    private ?SignalRouter $previousRouter;
    private ?FsContext $previousFs;
    private ?string $exportDirectory = null;

    protected function setUp(): void
    {
        parent::setUp();
        self::stubs(down: true);
        self::stubs(down: false);
        Database::sql('DROP TABLE IF EXISTS `hilos_fw_test_coordinator_note`');
        Database::sql(
            'CREATE TABLE `hilos_fw_test_coordinator_note` (`user_id` INT UNSIGNED NOT NULL PRIMARY KEY,'
                . ' FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT) ENGINE=InnoDB',
        );
        Schema::reset();
        Schema::initialize();
        $this->previousApp = Hilos::appClass();
        $this->previousRouter = Hilos::$sr;
        $this->previousFs = Hilos::$fs;
        LegalAcceptancesExports::reset();
        Hilos::$sr = new SignalRouter();
        ErasureCoordinatorTestHilos::initBrowser();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        SourceChangeBus::subscribe(new AccountErasureProgressChangeSubscriber());
        AccountErasureProgressResolver::forgetAll();
        $this->owners = [
            new ErasureCoordinatorTestUsersLibrary(),
            new ErasureCoordinatorTestHolder(),
            new ErasureCoordinatorTestDataExport(),
            new ErasureCoordinatorTestLegal(),
        ];
        foreach ($this->owners as $agent) {
            OwnershipDeclaration::claimDb($agent::class, $agent->getId());
            OwnershipDeclaration::claimDbSet($agent);
        }
    }

    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            Database::sql('DROP TABLE IF EXISTS `hilos_fw_test_coordinator_note`');
            self::stubs(down: true);
            Schema::reset();
        } finally {
            $this->releasePersonAgents();
            foreach ($this->owners as $agent) {
                TruthSourceRegistry::unregisterAgent($agent->getId());
                SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agent->getId()));
            }
            $this->previousApp::initBrowser();
            Hilos::resetBrowser();
            Hilos::$sr = $this->previousRouter;
            Hilos::$fs = $this->previousFs;
            LegalAcceptancesExports::reset();
            if ($this->exportDirectory !== null) {
                FsPath::removeDirectory($this->exportDirectory);
            }
            SourceChangeBus::reset();
            AccountErasureProgressResolver::forgetAll();
            parent::tearDown();
        }
    }

    /** The entry creates just one root start, then resends only resultless ready duties. */
    public function testBeginRecordsTheStartOnceAndResendsTheFirstWaveAtPreparing(): void
    {
        $root = self::seedPerson();
        $operation = $this->openErasure($root);
        $first = $this->begin($operation);
        self::assertSame([AccountErasureStart::personalKey($root)], self::keys($first));
        self::assertSame($root, $first[0]->userId);
        self::assertSame(HilosAgentType::HILOS_USER . ':' . $root, $first[0]->owner->agentId());
        self::assertSame('{"participant":"personal"}', $first[0]->payload->toJson());
        self::assertSame(self::keys($first), self::keys($this->begin($operation)));
        self::assertNull($this->asHolder(static fn () => AccountErasureStart::recordMissing($operation)));
        self::assertSame(1, (int)Database::sql(
            'SELECT COUNT(*) AS `count` FROM `hilos_account_operation_assignment` WHERE `operation_id` = ? AND `assignment_key` = ?',
            [$operation->id, AccountErasureStart::personalKey($root)],
        )->row()['count']);
        self::assertSame([], $this->answer($operation, $first[0]));

        $this->asPerson($root, static fn () => AccountErasureStart::run($first[0]->id));
        $wave = $this->answer($operation, $first[0]);
        self::assertSame([
            $this->part($operation, AccountErasureParticipantCatalog::SESSIONS_HOLDER, $root)->assignmentKey,
            $this->part($operation, AccountErasureParticipantCatalog::DATA_EXPORT, $root)->assignmentKey,
            AccountErasureCapture::personalKey($root),
        ], self::keys($wave));
        self::assertSame(self::keys($wave), self::keys($this->begin($operation)));
        self::assertNotNull(self::assignment($operation, AccountErasureFinal::personalKey($root)));
        $this->saveResult($wave[0]);
        self::assertSame(array_slice(self::keys($wave), 1), self::keys($this->begin($operation)));
        self::assertSame('preparing', Database::sql(
            'SELECT `stage` FROM `hilos_account_operation` WHERE `id` = ?',
            [$operation->id],
        )->row()['stage']);
    }

    /** A cancellation wins before the participant start; the saved lost result ends the erasure. */
    public function testALostStartEndsWithoutRecordingCapturesOrFinals(): void
    {
        $root = self::seedPerson();
        $operation = $this->openErasure($root);
        [$start] = $this->begin($operation);
        $this->asPerson($root, static fn () => Hilos::$db->accountDeletions[$operation->requestId]->actions->cancel());
        $this->asPerson($root, static fn () => AccountErasureStart::run($start->id));

        self::assertSame([], $this->answer($operation, $start));
        $row = self::operationRow($operation);
        self::assertSame('not_started', $row['outcome']);
        self::assertNotNull($row['finished_at']);
        self::assertSame('preparing', $row['stage']);
        self::assertSame([], Database::sql(
            'SELECT `id` FROM `hilos_account_operation_assignment` WHERE `operation_id` = ?'
                . ' AND (`assignment_key` LIKE \'capture:%\' OR `assignment_key` LIKE \'final:%\')',
            [$operation->id],
        )->rows());
    }

    /** Capture and personal answers release only their own account's direct successors, including on a duplicate. */
    public function testAnswersNeedAResultAndDuplicatesDoNotResendUnrelatedOpenParts(): void
    {
        $root = self::seedPerson();
        $folded = self::seedPerson();
        self::seedMerge($folded, $root);
        $operation = $this->openErasure($root);
        $project = $this->expect($operation, 'project:notes', $root);
        $delivery = $this->expect($operation, 'delivery:letter', $root);
        $this->start($operation);
        $capture = self::assignment($operation, AccountErasureCapture::personalKey($root));
        self::assertSame([], $this->answer($operation, $capture));
        $this->asPerson($root, static fn (AbstractUserAgent $agent) => $agent->captureForErasure($capture->id));

        $personal = $this->part($operation, AccountErasureParticipantCatalog::PERSONAL, $root);
        $library = $this->part($operation, AccountErasureParticipantCatalog::USERS_LIBRARY, $root);
        self::assertSame([$personal->assignmentKey, $library->assignmentKey, $project->assignmentKey], self::keys(
            $this->answer($operation, $capture),
        ));
        self::assertSame([$personal->assignmentKey, $library->assignmentKey, $project->assignmentKey], self::keys(
            $this->answer($operation, $capture),
        ));
        $this->saveResult($library);
        self::assertSame([], $this->answer($operation, $library));
        $this->asPerson($root, static fn (AbstractUserAgent $agent) => $agent->eraseAccountPersonalPart($personal->id));
        $legal = $this->part($operation, AccountErasureParticipantCatalog::LEGAL_EXPORT, $root);
        self::assertSame([$legal->assignmentKey, $delivery->assignmentKey], self::keys($this->answer($operation, $personal)));

        $this->saveResult($delivery);
        self::assertSame([$legal->assignmentKey], self::keys($this->answer($operation, $personal)));
        self::assertSame([
            $this->part($operation, AccountErasureParticipantCatalog::SESSIONS_HOLDER, $folded)->assignmentKey,
            $this->part($operation, AccountErasureParticipantCatalog::DATA_EXPORT, $folded)->assignmentKey,
            $this->part($operation, AccountErasureParticipantCatalog::SESSIONS_HOLDER, $root)->assignmentKey,
            $this->part($operation, AccountErasureParticipantCatalog::DATA_EXPORT, $root)->assignmentKey,
            $legal->assignmentKey,
            $project->assignmentKey,
            AccountErasureCapture::personalKey($folded),
        ], self::keys($this->begin($operation)));
    }

    /** A final waits for missing results and an ownerless part, even after every done answer. */
    public function testTheLastPartReleasesTheFinalButAWaitAndDeliveryCaptureAreNeverSent(): void
    {
        $root = self::seedPerson();
        $operation = $this->openErasure($root);
        $this->start($operation);
        $this->prepareParts($operation);
        $wait = new AccountOperationExpectation('delivery:unregistered', $root, null);
        $this->asHolder(static fn () => Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id,
            $operation->stage,
            $wait->assignmentKey(),
            $wait->assignmentOwner(),
            $root,
            $wait->payload(),
        ));
        $this->asHolder(static fn () => Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id,
            $operation->stage,
            'capture:delivery:letter:hilos_user:' . $root . ':' . $root . ':1',
            new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$root),
            $root,
            AccountErasureDeliveryCapture::payloadOf('letter', [1]),
        ));
        $legal = $this->part($operation, AccountErasureParticipantCatalog::LEGAL_EXPORT, $root);
        self::assertSame([], $this->answer($operation, $legal));
        self::assertSame([], $this->begin($operation));

        $resolved = $this->expect($operation, 'delivery:unregistered', $root);
        self::assertSame([$resolved->assignmentKey], self::keys($this->begin($operation)));
        self::assertSame([], $this->answer($operation, $resolved));
        $this->saveResult($resolved);
        self::assertSame([AccountErasureFinal::personalKey($root)], self::keys($this->answer($operation, $resolved)));
        self::assertSame([AccountErasureFinal::personalKey($root)], self::keys($this->begin($operation)));
    }

    /** The folded account's committed final releases the survivor; only all finals end the operation. */
    public function testFinalsFollowMergeDependenciesAndEndOnlyAfterTheWholeCircle(): void
    {
        $root = self::seedPerson();
        $folded = self::seedPerson();
        self::seedMerge($folded, $root);
        $operation = $this->openErasure($root);
        $this->start($operation);
        $this->prepareParts($operation);
        self::assertSame([AccountErasureFinal::personalKey($folded)], self::keys($this->begin($operation)));
        $foldedFinal = self::assignment($operation, AccountErasureFinal::personalKey($folded));
        self::assertSame([], $this->answer($operation, $foldedFinal));
        $this->asPerson($folded, static fn (AbstractUserAgent $agent) => $agent->finishAccountErasure($foldedFinal->id));

        self::assertSame([AccountErasureFinal::personalKey($root)], self::keys($this->answer($operation, $foldedFinal)));
        self::assertSame([AccountErasureFinal::personalKey($root)], self::keys($this->answer($operation, $foldedFinal)));
        self::assertNull(self::operationRow($operation)['finished_at']);
        self::assertSame([], Database::sql('SELECT `user_id` FROM `hilos_user_merge` WHERE `user_id` = ?', [$folded])->rows());
        $rootFinal = self::assignment($operation, AccountErasureFinal::personalKey($root));
        $this->asPerson($root, static fn (AbstractUserAgent $agent) => $agent->finishAccountErasure($rootFinal->id));
        self::assertSame([], $this->answer($operation, $rootFinal));
        $row = self::operationRow($operation);
        self::assertSame('completed', $row['outcome']);
        self::assertNotNull($row['finished_at']);
        self::assertSame('preparing', $row['stage']);
        self::assertSame([], Database::sql('SELECT `id` FROM `hilos_user` WHERE `id` IN (?, ?)', [$root, $folded])->rows());
    }

    /** An uncaptured answer retries once for a newly recorded ownerless delivery, then waits without a loop. */
    public function testUncapturedRetriesOnlyWhenReconciliationRecordsANewDuty(): void
    {
        $root = self::seedPerson();
        $operation = $this->openErasure($root);
        $this->start($operation);
        $personal = $this->part($operation, AccountErasureParticipantCatalog::PERSONAL, $root);
        self::assertSame([], $this->answer($operation, $personal, AccountOperationAnswerKind::Uncaptured));
        Database::sql(
            'INSERT INTO `hilos_notification` (`user_id`, `type`, `title`, `created_at`) VALUES (?, ?, ?, ?)',
            [$root, 'account.erasure', 'Late delivery', TimeHelper::nowMs()],
        );
        $notificationId = Database::lastInsertId();
        Database::sql(
            'INSERT INTO `hilos_notification_delivery` (`notification_id`, `channel`, `routing_key`, `status`, `created_at`, `updated_at`)'
                . ' VALUES (?, ?, ?, ?, ?, ?)',
            [$notificationId, 'unregistered', $root, DeliveryStatus::PENDING, TimeHelper::nowMs(), TimeHelper::nowMs()],
        );

        self::assertSame([$personal->assignmentKey], self::keys($this->answer($operation, $personal, AccountOperationAnswerKind::Uncaptured)));
        self::assertSame([], $this->answer($operation, $personal, AccountOperationAnswerKind::Uncaptured));
        self::assertContains('delivery:unregistered', AccountErasureFinal::missingPartsOf($operation->id, $root));
        foreach ($this->begin($operation) as $assignment) {
            self::assertFalse(AccountOperationExpectation::isWaitKey($assignment->assignmentKey));
        }
    }

    /** The safe refusal is stored and only newly reconciled ready duties are returned, even on a duplicate. */
    public function testFinalRefusedReconcilesOnlyNewPartsAndKeepsOnlyItsSafeReason(): void
    {
        ErasureCoordinatorWithoutDataExportHilos::initBrowser();
        $root = self::seedPerson();
        $operation = $this->openErasure($root);
        $this->start($operation);
        $this->prepareParts($operation);
        $final = self::assignment($operation, AccountErasureFinal::personalKey($root));
        self::assertSame([$final->assignmentKey], self::keys($this->begin($operation)));
        ErasureCoordinatorTestHilos::initBrowser();

        $new = $this->answer($operation, $final, AccountOperationAnswerKind::FinalRefused, $root, 'hilos_data_export');
        self::assertSame([$this->part($operation, AccountErasureParticipantCatalog::DATA_EXPORT, $root)->assignmentKey], self::keys($new));
        self::assertSame([], $this->answer($operation, $final, AccountOperationAnswerKind::FinalRefused, $root, 'hilos_data_export'));
        self::assertSame(
            [$root => 'hilos_data_export'],
            json_decode(self::operationRow($operation)['final_refusals'], true, flags: JSON_THROW_ON_ERROR),
        );
        $this->saveResult($new[0]);
        self::assertSame([$final->assignmentKey], self::keys($this->answer($operation, $new[0])));
        self::assertNull(self::operationRow($operation)['finished_at']);
    }

    /** Waits, ordinary refusals and failures do not write or immediately resend the unanswered duty. */
    public function testWaitsRefusedAndFailedLeaveTheOperationForAnotherPass(): void
    {
        $root = self::seedPerson();
        $operation = $this->openErasure($root);
        [$start] = $this->begin($operation);
        $before = Database::sql('SELECT * FROM `hilos_account_operation_assignment` ORDER BY `id`')->rows();
        foreach ([AccountOperationAnswerKind::Waits, AccountOperationAnswerKind::Refused, AccountOperationAnswerKind::Failed] as $kind) {
            self::assertSame([], $this->answer($operation, $start, $kind));
        }
        self::assertSame($before, Database::sql('SELECT * FROM `hilos_account_operation_assignment` ORDER BY `id`')->rows());
        self::assertSame([], Database::sql('SELECT `id` FROM `hilos_account_operation_result`')->rows());
        self::assertNull(self::operationRow($operation)['finished_at']);
        self::assertSame([$start->assignmentKey], self::keys($this->begin($operation)));
    }

    /** The entry does not open an operation of its own and refuses a merge or an already ended erasure. */
    public function testBeginRefusesOtherKindsAndClosedErasures(): void
    {
        $root = self::seedPerson();
        $other = self::seedPerson();
        $operation = $this->openErasure($root);
        $this->asHolder(static fn () => $operation->actions->finish($operation->stage, AccountOperationOutcome::NotStarted));
        $merge = $this->asHolder(static fn () => Hilos::$db->accountOperations->actions->recordMerge(
            $root,
            $other,
            new AccountMergeChoice(PasswordFate::SURVIVOR, SecondFactorFate::SURVIVOR, true, false),
        ));
        foreach ([$operation, $merge] as $refused) {
            try {
                $this->begin($refused);
                self::fail('The coordinator accepted an operation other than an open erasure');
            } catch (ValidationException $e) {
                self::assertSame('Only an open erasure has coordinator transitions.', $e->getMessage());
            }
        }
    }

    /** Every owner answers through its handler; the folded final releases the root's final. */
    public function testTheHolderExchangesTheWholeCircleAndPublishesCommittedProgress(): void
    {
        $this->activateExchange();
        $root = self::seedPerson();
        $folded = self::seedPerson();
        self::seedMerge($folded, $root);
        self::seedDelivery($root, MailDeliveryChannel::NAME);
        self::seedDelivery($folded, MailDeliveryChannel::NAME);
        self::seedAccessLog($root);
        $operation = $this->openErasure($root);
        $this->drive($operation);
        $start = $this->operationFrames();
        self::assertCount(1, $start);
        $this->deliverOperationFrame($start[0]);
        $this->deliverOperationFrame($this->operationFrames()[0]);
        self::assertSame([], Database::sql('SELECT `id` FROM `hilos_access_log` WHERE `user_id` = ?', [$root])->rows());
        foreach ([$root, $folded] as $userId) {
            self::assertNotNull(Hilos::$db->accountOperationResults->byAssignment(
                $this->part($operation, AccountErasureParticipantCatalog::SESSIONS_HOLDER, $userId)->id,
            ));
            self::assertNull(Hilos::$db->accountOperationResults->byAssignment(
                self::assignment($operation, AccountErasureCapture::personalKey($userId))->id,
            ));
        }
        $initial = AccountErasureProgressResolver::of($root);
        self::assertNotNull($initial);
        self::assertContains(false, array_column($initial->parts, AccountErasureProgress::done));
        self::assertContains(true, array_column($initial->parts, AccountErasureProgress::done));
        $finals = $this->exchangeUntilFinals();
        self::assertCount(1, $finals);
        self::assertSame(AccountErasureFinal::personalKey($folded), $this->frameAssignment($finals[0])->assignmentKey);
        $ready = AccountErasureProgressResolver::of($root);
        self::assertNotSame($initial, $ready);
        self::assertNotContains(false, array_column($ready->parts, AccountErasureProgress::done));
        self::assertSame($ready->toArray(), AccountErasureProgressResolver::of($folded)->toArray());
        self::assertNull(self::operationRow($operation)['finished_at']);
        $this->deliverOperationFrame($finals[0]);
        self::assertNull(self::operationRow($operation)['finished_at']);
        self::assertTrue(isset(Hilos::$db->users[$root]));
        self::assertFalse(isset(Hilos::$db->users[$folded]));
        $this->deliverOperationFrame($this->operationFrames()[0]);
        $rootFinal = $this->operationFrames();
        self::assertCount(1, $rootFinal);
        self::assertSame(AccountErasureFinal::personalKey($root), $this->frameAssignment($rootFinal[0])->assignmentKey);
        $this->deliverOperationFrame($rootFinal[0]);
        self::assertNull(self::operationRow($operation)['finished_at']);
        $this->deliverOperationFrame($this->operationFrames()[0]);
        self::assertSame('completed', self::operationRow($operation)['outcome']);
        self::assertSame('preparing', self::operationRow($operation)['stage']);
        self::assertSame([], Database::sql('SELECT `id` FROM `hilos_user`')->rows());
        self::assertSame([], Database::sql('SELECT `id` FROM `hilos_notification_delivery`')->rows());
        self::assertSame([], $this->operationFrames());
        self::assertSame([$folded, $root], $this->projectOwner()->erasedAccounts);
        self::assertSame([], Database::sql('SELECT `user_id` FROM `hilos_fw_test_coordinator_note`')->rows());
    }

    /** The real start handler loses to cancellation and leaves the person's data untouched. */
    public function testTheHolderStopsWhenKeepMyAccountWon(): void
    {
        $this->activateExchange();
        $root = self::seedPerson();
        self::seedAccessLog($root);
        $operation = $this->openErasure($root);
        $this->drive($operation);
        $this->asPerson($root, static fn () => Hilos::$db->accountDeletions[$operation->requestId]->actions->cancel());
        $this->deliverOperationFrame($this->operationFrames()[0]);
        $this->deliverOperationFrame($this->operationFrames()[0]);
        self::assertSame('not_started', self::operationRow($operation)['outcome']);
        self::assertSame([], $this->operationFrames());
        self::assertSame([AccountErasureStart::personalKey($root)], array_values(array_filter(
            self::keys(iterator_to_array(Hilos::$db->accountOperationAssignments->forOperation($operation->id), false)),
            static fn (string $key): bool => !AccountOperationExpectation::isExpectKey($key),
        )));
        self::assertTrue(isset(Hilos::$db->users[$root]));
        self::assertCount(1, Database::sql('SELECT `id` FROM `hilos_access_log` WHERE `user_id` = ?', [$root])->rows());
        self::assertSame([], $this->projectOwner()->erasedAccounts);
    }

    /** A duplicate capture answer resends only direct successors; participant work stays idempotent. */
    public function testTheHolderDoesNotMultiplyUnrelatedWorkOnADuplicateAnswer(): void
    {
        $this->activateExchange();
        $root = self::seedPerson();
        $operation = $this->openErasure($root);
        $this->driveThroughStart($operation);
        $wave = $this->operationFrames();
        $capture = array_values(array_filter($wave, fn (SignalDTO $frame): bool =>
            $this->frameAssignment($frame)->assignmentKey === AccountErasureCapture::personalKey($root)))[0];
        $this->deliverOperationFrame($capture);
        $answer = $this->operationFrames()[0];
        $this->deliverOperationFrame($answer);
        $first = $this->operationFrames();
        $this->deliverOperationFrame($answer);
        $duplicate = $this->operationFrames();
        $expected = [
            $this->part($operation, AccountErasureParticipantCatalog::PERSONAL, $root)->assignmentKey,
            $this->part($operation, AccountErasureParticipantCatalog::USERS_LIBRARY, $root)->assignmentKey,
            $this->part($operation, ErasureCoordinatorProjectDirectory::KEY, $root)->assignmentKey,
        ];
        self::assertSame($expected, array_map(fn (SignalDTO $f): string => $this->frameAssignment($f)->assignmentKey, $first));
        self::assertSame($expected, array_map(fn (SignalDTO $f): string => $this->frameAssignment($f)->assignmentKey, $duplicate));
        foreach ([...$first, ...$duplicate] as $frame) {
            $this->deliverOperationFrame($frame);
        }
        $answers = $this->operationFrames();
        self::assertCount(6, $answers);
        foreach ($answers as $frame) {
            self::assertSame(AccountOperationAnswerKind::Done, $frame->data->data->answer);
        }
        self::assertSame([$root], $this->projectOwner()->erasedAccounts);
        self::assertCount(3, Database::sql(
            'SELECT `id` FROM `hilos_account_operation_result` WHERE `assignment_id` IN (?, ?, ?)',
            array_map(fn (SignalDTO $f): int => $this->frameAssignment($f)->id, $first),
        )->rows());
    }

    /** Lost answers of both the start and the last final recover from durable facts on reentry. */
    public function testTheHolderReentersAfterALostAnswerWithoutRepeatingTheStart(): void
    {
        $this->activateExchange();
        $root = self::seedPerson();
        $operation = $this->openErasure($root);
        $this->drive($operation);
        $this->deliverOperationFrame($this->operationFrames()[0]);
        $lost = $this->operationFrames();
        self::assertCount(1, $lost);
        self::assertNull(self::operationRow($operation)['finished_at']);
        self::assertNull(Hilos::$db->accountOperationAssignments->byIdentity(
            $operation->id, $operation->stage, AccountErasureCapture::personalKey($root),
        ));
        $this->drive($operation);
        $finals = $this->exchangeUntilFinals();
        self::assertCount(1, $finals);
        $this->deliverOperationFrame($finals[0]);
        $lostFinal = $this->operationFrames();
        self::assertCount(1, $lostFinal);
        self::assertNull(self::operationRow($operation)['finished_at']);
        $this->drive($operation);
        self::assertSame('completed', self::operationRow($operation)['outcome']);
        self::assertSame([], $this->operationFrames());
    }

    /** Reverse delivery changes no gate: all durable parts precede the final. */
    public function testTheHolderAcceptsAnswersInReverseOrderOnlyAfterTheirFacts(): void
    {
        $this->activateExchange();
        $root = self::seedPerson();
        self::seedDelivery($root, MailDeliveryChannel::NAME);
        $operation = $this->openErasure($root);
        $this->driveThroughStart($operation);
        for ($wave = 0; $wave < 3; $wave++) {
            foreach ($this->operationFrames() as $ask) {
                $this->deliverOperationFrame($ask);
            }
            $answers = $this->operationFrames();
            self::assertNotEmpty($answers);
            foreach (array_reverse($answers) as $answer) {
                self::assertInstanceOf(AccountOperationAnswerSignalData::class, $answer->data->data);
                $this->deliverOperationFrame($answer);
                self::assertNull(self::operationRow($operation)['finished_at']);
            }
        }
        $finals = $this->operationFrames();
        self::assertCount(2, $finals);
        self::assertSame([
            AccountErasureFinal::personalKey($root), AccountErasureFinal::personalKey($root),
        ], array_map(fn (SignalDTO $frame): string => $this->frameAssignment($frame)->assignmentKey, $finals));
        self::assertSame([], AccountErasureFinal::missingPartsOf($operation->id, $root));
        self::assertNull(self::operationRow($operation)['finished_at']);
        foreach ($finals as $final) {
            $this->deliverOperationFrame($final);
            self::assertNull(self::operationRow($operation)['finished_at']);
        }
        foreach ($this->operationFrames() as $answer) {
            $this->deliverOperationFrame($answer);
        }
        self::assertSame('completed', self::operationRow($operation)['outcome']);
        self::assertSame([], $this->operationFrames());
    }

    /** Foreign, unknown, closed, merge and wrong-stage answers cannot change or enqueue anything. */
    public function testTheHolderJudgesTheWholeAnswerAddressAndRequiresADurableResult(): void
    {
        $this->activateExchange();
        $root = self::seedPerson();
        $operation = $this->openErasure($root);
        $this->drive($operation);
        $ask = $this->operationFrames()[0];
        $start = $this->frameAssignment($ask);
        $otherRoot = self::seedPerson();
        $other = $this->openErasure($otherRoot);
        $closed = $this->openErasure(self::seedPerson());
        [$closedStart] = $this->begin($closed);
        $this->asHolder(static fn () => $closed->actions->finish($closed->stage, AccountOperationOutcome::NotStarted));
        $merge = $this->asHolder(static fn () => Hilos::$db->accountOperations->actions->recordMerge(
            $root, $otherRoot, new AccountMergeChoice(PasswordFate::SURVIVOR, SecondFactorFate::SURVIVOR, true, false),
        ));
        $before = Database::sql('SELECT * FROM `hilos_account_operation` ORDER BY `id`')->rows();
        $duties = Database::sql('SELECT * FROM `hilos_account_operation_assignment` ORDER BY `id`')->rows();
        foreach ([
            [$other->id, $operation->stage, $start->id],
            [$operation->id, 'other-stage', $start->id],
            [$operation->id, $operation->stage, PHP_INT_MAX],
            [PHP_INT_MAX, $operation->stage, $start->id],
            [$closed->id, $closed->stage, $closedStart->id],
            [$merge->id, $merge->stage, $start->id],
            [$operation->id, $operation->stage, $start->id],
        ] as [$id, $stage, $assignmentId]) {
            $this->receiveAnswer(new AccountOperationAnswerSignalData($id, $stage, $assignmentId, AccountOperationAnswerKind::Done));
            self::assertSame([], $this->operationFrames());
        }
        self::assertSame($before, Database::sql('SELECT * FROM `hilos_account_operation` ORDER BY `id`')->rows());
        self::assertSame($duties, Database::sql('SELECT * FROM `hilos_account_operation_assignment` ORDER BY `id`')->rows());
        self::assertSame([], Database::sql('SELECT `id` FROM `hilos_account_operation_result`')->rows());
        foreach ([AccountOperationAnswerKind::Waits, AccountOperationAnswerKind::Refused, AccountOperationAnswerKind::Failed] as $kind) {
            $this->receiveAnswer(new AccountOperationAnswerSignalData($operation->id, $operation->stage, $start->id, $kind));
            self::assertSame([], $this->operationFrames());
            self::assertSame($before, Database::sql('SELECT * FROM `hilos_account_operation` ORDER BY `id`')->rows());
        }
        $this->asHolder(static fn () => $operation->actions->advance($operation->stage, 'next-stage'));
        $advanced = self::operationRow($operation);
        $this->receiveAnswer(new AccountOperationAnswerSignalData($operation->id, $start->stage, $start->id, AccountOperationAnswerKind::Done));
        self::assertSame($advanced, self::operationRow($operation));
        self::assertSame([], $this->operationFrames());
        $this->expectException(InvalidAgentSignalPayloadException::class);
        $this->asHolder(fn () => $this->holder()->onSignalAgent($ask->data, '', HilosSignalConstants::HILOS_ACCOUNT_OPERATION_ANSWER));
    }

    /** A channel without an owner remains a census wait and never receives a final. */
    public function testTheHolderLeavesACensusWaitOpenWithoutAFrameLoop(): void
    {
        $this->activateExchange();
        $root = self::seedPerson();
        self::seedDelivery($root, 'unregistered');
        $operation = $this->openErasure($root);
        $this->drive($operation);
        self::assertSame([], $this->exchangeUntilFinals());
        self::assertNull(self::operationRow($operation)['finished_at']);
        self::assertContains('delivery:unregistered', AccountErasureFinal::missingPartsOf($operation->id, $root));
        self::assertTrue(isset(Hilos::$db->users[$root]));
        self::assertSame([], $this->operationFrames());
    }

    /** A real FK refusal keeps only its safe table, and the same final succeeds after the reference goes. */
    public function testTheHolderKeepsARestrictRefusalOnTheOpenOperation(): void
    {
        $this->activateExchange();
        $root = self::seedPerson();
        $operation = $this->openErasure($root);
        $this->drive($operation);
        [$final] = $this->exchangeUntilFinals();
        self::seedIdentity($root, 'password', 'late@example.test');
        $this->deliverOperationFrame($final);
        $answer = $this->operationFrames()[0];
        self::assertSame(AccountOperationAnswerKind::FinalRefused, $answer->data->data->answer);
        self::assertSame($root, $answer->data->data->refusedUserId);
        self::assertSame('hilos_identity', $answer->data->data->refusedTable);
        $this->deliverOperationFrame($answer);
        self::assertSame([], $this->operationFrames());
        self::assertSame(
            [$root => 'hilos_identity'],
            json_decode(self::operationRow($operation)['final_refusals'], true, flags: JSON_THROW_ON_ERROR),
        );
        self::assertNull(self::operationRow($operation)['finished_at']);
        self::assertTrue(isset(Hilos::$db->users[$root]));
        $this->asPerson($root, static fn () => Hilos::$db->identities->deleteForUser($root));
        $this->drive($operation);
        $retry = $this->operationFrames();
        self::assertCount(1, $retry);
        self::assertSame($this->frameAssignment($final)->id, $this->frameAssignment($retry[0])->id);
        $this->deliverOperationFrame($retry[0]);
        $this->deliverOperationFrame($this->operationFrames()[0]);
        self::assertSame('completed', self::operationRow($operation)['outcome']);
    }

    /** @return iterable<string, array{string}> A late delivery with an owner and one without */
    public static function lateChannels(): iterable
    {
        yield 'mail owner' => [MailDeliveryChannel::NAME];
        yield 'ownerless channel' => ['unregistered'];
    }

    /** @param string $channel Channel of the delivery arriving after the personal capture */
    #[DataProvider('lateChannels')]
    public function testTheHolderRecapturesLateDeliveryAndRetriesOnlyForANewDuty(string $channel): void
    {
        $this->activateExchange();
        $root = self::seedPerson();
        $operation = $this->openErasure($root);
        $this->driveThroughStart($operation);
        $wave = $this->operationFrames();
        foreach ($wave as $frame) {
            $this->deliverOperationFrame($frame);
        }
        foreach ($this->operationFrames() as $frame) {
            $this->deliverOperationFrame($frame);
        }
        $ready = $this->operationFrames();
        self::seedDelivery($root, $channel);
        foreach ($ready as $frame) {
            $this->deliverOperationFrame($frame);
        }
        $answers = $this->operationFrames();
        $uncaptured = array_values(array_filter($answers, static fn (SignalDTO $frame): bool =>
            $frame->data->data->answer === AccountOperationAnswerKind::Uncaptured));
        self::assertCount(1, $uncaptured);
        foreach ($answers as $frame) {
            $this->deliverOperationFrame($frame);
        }
        $retry = $this->operationFrames();
        self::assertCount(1, $retry);
        self::assertSame($uncaptured[0]->data->data->assignmentId, $retry[0]->data->data->assignmentId);
        $this->deliverOperationFrame($retry[0]);
        $this->deliverOperationFrame($this->operationFrames()[0]);
        $finals = $this->exchangeUntilFinals();
        if ($channel === MailDeliveryChannel::NAME) {
            self::assertCount(1, $finals);
            $this->deliverOperationFrame($finals[0]);
            $this->deliverOperationFrame($this->operationFrames()[0]);
            self::assertSame('completed', self::operationRow($operation)['outcome']);
            self::assertSame([], Database::sql('SELECT `id` FROM `hilos_notification_delivery`')->rows());
        } else {
            self::assertSame([], $finals);
            self::assertNull(self::operationRow($operation)['finished_at']);
            self::assertContains('delivery:unregistered', AccountErasureFinal::missingPartsOf($operation->id, $root));
            $this->receiveAnswer($uncaptured[0]->data->data);
            self::assertSame([], $this->operationFrames());
        }
    }

    /** Activates real participant workers only for the exchange cases. */
    private function activateExchange(): void
    {
        Hilos::$db = new ErasureCoordinatorContext();
        Hilos::$db->configure();
        ErasureCoordinatorExchangeHilos::initBrowser();
        $this->exportDirectory = sys_get_temp_dir() . '/hilos-erasure-coordinator-' . bin2hex(random_bytes(8));
        Hilos::$fs = new ErasureCoordinatorFs($this->exportDirectory);
        Hilos::$fs->configure();
        Hilos::$fs->getDirectory(FsContext::LEGAL_EXPORT)->ensureDirectory();
        foreach ([new MailDeliveryChannelAgent('1'), new ErasureCoordinatorProjectOwner()] as $agent) {
            OwnershipDeclaration::claimAll($agent);
            $this->owners[] = $agent;
        }
    }

    /** @return ErasureCoordinatorTestHolder The real holder exposed by the fixture */
    private function holder(): ErasureCoordinatorTestHolder
    {
        return $this->owners[1];
    }

    /** @return ErasureCoordinatorProjectOwner The project participant for the exchange */
    private function projectOwner(): ErasureCoordinatorProjectOwner
    {
        return $this->owners[5];
    }

    /** @param AccountOperation $operation Already opened operation */
    private function drive(AccountOperation $operation): void
    {
        $this->asHolder(fn () => $this->holder()->drive($operation));
    }

    /** @param AccountOperation $operation Operation whose start ask and answer are delivered */
    private function driveThroughStart(AccountOperation $operation): void
    {
        $this->drive($operation);
        $this->deliverOperationFrame($this->operationFrames()[0]);
        $this->deliverOperationFrame($this->operationFrames()[0]);
    }

    /** @param AccountOperationAnswerSignalData $answer Frame under the real holder's address judge */
    private function receiveAnswer(AccountOperationAnswerSignalData $answer): void
    {
        $this->asHolder(fn () => $this->holder()->onSignalAgent(
            new AgentSignalData($answer), '', HilosSignalConstants::HILOS_ACCOUNT_OPERATION_ANSWER,
        ));
    }

    /** @return list<SignalDTO> Operation frames, excluding unrelated DB and browser announcements */
    private function operationFrames(): array
    {
        $frames = [];
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($frame->data instanceof AgentSignalData
                && ($frame->data->data instanceof AccountOperationAskSignalData || $frame->data->data instanceof AccountOperationAnswerSignalData)) {
                $frames[] = $frame;
            }
        }

        return $frames;
    }

    /**
     * @param SignalDTO $frame Queued assignment frame
     * @return AccountOperationAssignment Stored address for assertion or participant dispatch
     */
    private function frameAssignment(SignalDTO $frame): AccountOperationAssignment
    {
        return Hilos::$db->accountOperationAssignments[$frame->data->data->assignmentId];
    }

    /** @param SignalDTO $frame Queued ask or answer delivered in its real owner's execution frame */
    private function deliverOperationFrame(SignalDTO $frame): void
    {
        self::assertInstanceOf(AgentSignalData::class, $frame->data);
        $payload = $frame->data->data;
        if ($payload instanceof AccountOperationAnswerSignalData) {
            self::assertSame(HilosSignalConstants::HILOS_ACCOUNT_OPERATION_ANSWER, $frame->signalName->getName());
            $this->receiveAnswer($payload);

            return;
        }
        self::assertInstanceOf(AccountOperationAskSignalData::class, $payload);
        $assignment = $this->frameAssignment($frame);
        self::assertSame($assignment->ownerIndex, $payload->ownerIndex);
        self::assertNotSame(HilosAgentType::HILOS_SESSIONS_LIBRARY, $assignment->ownerType);
        $agent = $assignment->ownerType === HilosAgentType::HILOS_USER
            ? $this->personAgent((int)$payload->ownerIndex)
            : array_values(array_filter(
                $this->owners,
                static fn (AbstractAgent $owner): bool => $owner->getId() === $assignment->owner->agentId(),
            ))[0];
        self::assertSame($agent::ACCOUNT_OPERATION_SIGNAL, $frame->signalName->getName());
        ExecutionContext::run(new ExecutionFrame(agentId: $agent->getId()), static fn () => $agent->onSignalAgent(
            $frame->data, '', $frame->signalName->getName(),
        ));
    }

    /**
     * @return list<SignalDTO> Finals held back for explicit terminal assertions
     */
    private function exchangeUntilFinals(): array
    {
        $finals = [];
        $delivered = 0;
        $frames = $this->operationFrames();
        while (($frame = array_shift($frames)) !== null) {
            self::assertLessThan(100, ++$delivered, 'Operation frames must settle without a retry loop.');
            if ($frame->data->data instanceof AccountOperationAskSignalData
                && str_starts_with($this->frameAssignment($frame)->assignmentKey, AccountErasureFinal::FINAL_PREFIX . ':')) {
                $finals[] = $frame;
            } else {
                $this->deliverOperationFrame($frame);
            }
            $new = $this->operationFrames();
            $frames = [...$new, ...$frames];
        }

        return $finals;
    }

    /** @param int $userId Account whose sessions part must erase this log row */
    private static function seedAccessLog(int $userId): void
    {
        Database::sql(
            'INSERT INTO `hilos_access_log` (`user_id`, `event`, `occurred_at`) VALUES (?, ?, ?)',
            [$userId, 'sign_in', TimeHelper::nowMs()],
        );
    }

    /**
     * @param int $userId Recipient account
     * @param string $channel Channel with or without a registered owner
     */
    private static function seedDelivery(int $userId, string $channel): void
    {
        Database::sql(
            'INSERT INTO `hilos_notification` (`user_id`, `type`, `title`, `created_at`) VALUES (?, ?, ?, ?)',
            [$userId, 'account.erasure', 'Coordinator delivery', TimeHelper::nowMs()],
        );
        $notificationId = Database::lastInsertId();
        Database::sql(
            'INSERT INTO `hilos_notification_delivery` (`notification_id`, `channel`, `routing_key`, `status`, `created_at`, `updated_at`)'
                . ' VALUES (?, ?, ?, ?, ?, ?)',
            [$notificationId, $channel, $userId, DeliveryStatus::PENDING, TimeHelper::nowMs(), TimeHelper::nowMs()],
        );
    }

    /**
     * @param string $userId Person id as the agent address carries it
     * @return AbstractUserAgent Agent with the final's rights confined to this fixture
     */
    protected function newPersonAgent(string $userId): AbstractUserAgent
    {
        return new ErasureCoordinatorTestUserAgent($userId);
    }

    /**
     * @param int $root Root whose standing request the operation answers
     * @return AccountOperation Newly opened erasure, not yet started
     */
    private function openErasure(int $root): AccountOperation
    {
        ExecutionContext::run(
            new ExecutionFrame(agentId: HilosAgentType::HILOS_USERS_LIBRARY),
            static fn () => Hilos::$db->accountDeletions->actions->request(
                $root,
                TimeHelper::nowMs() + TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND,
            ),
        );
        $request = Hilos::$db->accountDeletions->liveOf($root);
        self::assertNotNull($request);
        $operation = $this->asHolder(static fn () => AccountErasureOpening::open($request, AccountErasureParticipantCatalog::framework())->operation);
        self::assertNotNull($operation);

        return $operation;
    }

    /**
     * @param AccountOperation $operation Erasure whose root starts its request
     */
    private function start(AccountOperation $operation): void
    {
        [$start] = $this->begin($operation);
        $this->asPerson($operation->rootUserId, static fn () => AccountErasureStart::run($start->id));
        $this->answer($operation, $start);
    }

    /**
     * @param AccountOperation $operation Erasure whose accounts finish their parts before the finals
     */
    private function prepareParts(AccountOperation $operation): void
    {
        foreach ($operation->circle->toArray() as $userId) {
            $capture = self::assignment($operation, AccountErasureCapture::personalKey($userId));
            $this->asPerson($userId, static fn (AbstractUserAgent $agent) => $agent->captureForErasure($capture->id));
            $personal = $this->part($operation, AccountErasureParticipantCatalog::PERSONAL, $userId);
            $this->asPerson($userId, static fn (AbstractUserAgent $agent) => $agent->eraseAccountPersonalPart($personal->id));
        }
        foreach (Hilos::$db->accountOperationAssignments->forOperation($operation->id) as $assignment) {
            if (AccountOperationExpectation::isExpectKey($assignment->assignmentKey)
                && Hilos::$db->accountOperationResults->byAssignment($assignment->id) === null) {
                $this->saveResult($assignment);
            }
        }
    }

    /**
     * @param AccountOperationAssignment $assignment Supporting part, recorded by its actual owner without running its work
     */
    private function saveResult(AccountOperationAssignment $assignment): void
    {
        if ($assignment->ownerType === HilosAgentType::HILOS_USER) {
            $this->personAgent($assignment->userId);
        }
        ExecutionContext::run(new ExecutionFrame(agentId: $assignment->owner->agentId()), static fn () => Database::transaction(
            DatabaseNames::PRIMARY,
            static fn (): AccountOperationResult => Hilos::$db->accountOperationResults->actions->record(
                $assignment,
                AccountOperationResultPayload::fromJson('{}'),
            ),
        ));
    }

    /**
     * @param AccountOperation $operation Erasure to enter
     * @return list<AccountOperationAssignment> Assignments the holder would send
     */
    private function begin(AccountOperation $operation): array
    {
        return $this->asHolder(static fn () => AccountErasureCoordinator::begin($operation, AccountErasureParticipantCatalog::framework()));
    }

    /**
     * @param AccountOperation $operation Erasure to advance
     * @param AccountOperationAssignment $assignment Accepted assignment
     * @param AccountOperationAnswerKind $kind Participant's transport outcome
     * @param ?int $refusedUserId Refused account for a final_refused answer
     * @param ?string $refusedTable Safe table name for a final_refused answer
     * @return list<AccountOperationAssignment> Assignments the holder would send
     */
    private function answer(
        AccountOperation $operation,
        AccountOperationAssignment $assignment,
        AccountOperationAnswerKind $kind = AccountOperationAnswerKind::Done,
        ?int $refusedUserId = null,
        ?string $refusedTable = null,
    ): array {
        return $this->asHolder(static fn () => AccountErasureCoordinator::afterAnswer(
            $operation,
            $assignment,
            new AccountOperationAnswerSignalData($operation->id, $operation->stage, $assignment->id, $kind, $refusedUserId, $refusedTable),
            AccountErasureParticipantCatalog::framework(),
        ));
    }

    /**
     * @template T
     * @param callable(): T $step Step to run in the session holder's frame
     * @return T Result of the step
     */
    private function asHolder(callable $step): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: HilosAgentType::HILOS_SESSIONS_LIBRARY), $step);
    }

    /**
     * @param AccountOperation $operation Erasure to add a supporting expectation to
     * @param string $participant Participant whose gate is under test
     * @param int $userId Account the expectation is about
     * @return AccountOperationAssignment Expectation written to the fixture's person agent
     */
    private function expect(AccountOperation $operation, string $participant, int $userId): AccountOperationAssignment
    {
        $expectation = new AccountOperationExpectation(
            $participant,
            $userId,
            new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$userId),
        );

        return $this->recordPersonalDuty($operation, $expectation->assignmentKey(), $participant, $userId);
    }

    /**
     * @param AccountOperation $operation Erasure to add a supporting duty to
     * @param string $key Exact duty key
     * @param string $participant Participant the payload names
     * @param int $userId Account the duty is about
     * @return AccountOperationAssignment Duty with no result
     */
    private function recordPersonalDuty(AccountOperation $operation, string $key, string $participant, int $userId): AccountOperationAssignment
    {
        $expectation = new AccountOperationExpectation(
            $participant,
            $userId,
            new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$userId),
        );

        return $this->asHolder(static fn () => Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id,
            $operation->stage,
            $key,
            $expectation->assignmentOwner(),
            $userId,
            $expectation->payload(),
        ));
    }

    /**
     * @param AccountOperation $operation Erasure whose expected part is read
     * @param string $participant Participant the payload names
     * @param int $userId Account the part is about
     * @return AccountOperationAssignment The one expected part
     */
    private function part(AccountOperation $operation, string $participant, int $userId): AccountOperationAssignment
    {
        foreach (Hilos::$db->accountOperationAssignments->forOperation($operation->id) as $assignment) {
            if ($assignment->userId === $userId && AccountOperationExpectation::isExpectKey($assignment->assignmentKey)
                && AccountOperationExpectation::participantOf($assignment->payload) === $participant) {
                return $assignment;
            }
        }
        self::fail("No expected part {$participant} for {$userId}");
    }

    /**
     * @param AccountOperation $operation Erasure whose duty is read
     * @param string $key Exact duty key
     * @return AccountOperationAssignment Required duty
     */
    private static function assignment(AccountOperation $operation, string $key): AccountOperationAssignment
    {
        $assignment = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, $operation->stage, $key);
        self::assertNotNull($assignment);

        return $assignment;
    }

    /**
     * @param list<AccountOperationAssignment> $assignments Assignments selected by the coordinator
     * @return list<string> Keys in their original order
     */
    private static function keys(array $assignments): array
    {
        return array_map(static fn (AccountOperationAssignment $assignment): string => $assignment->assignmentKey, $assignments);
    }

    /** @return int Id of a new person, before any ORM reader sees it */
    private static function seedPerson(): int
    {
        Database::sql("INSERT INTO `hilos_user` (`name`, `admin`) VALUES ('Coordinator person', 0)");
        $userId = Database::lastInsertId();
        if (Hilos::appClass() === ErasureCoordinatorExchangeHilos::class) {
            Database::sql('INSERT INTO `hilos_fw_test_coordinator_note` (`user_id`) VALUES (?)', [$userId]);
        }

        return $userId;
    }

    /**
     * @param int $folded Folded account
     * @param int $root Survivor of the merge
     */
    private static function seedMerge(int $folded, int $root): void
    {
        Database::sql(
            'INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (?, ?, ?)',
            [$folded, $root, TimeHelper::nowMs()],
        );
    }

    /**
     * @param AccountOperation $operation Erasure whose persisted terminal state is checked independently
     * @return array<string, mixed> Persisted stage, outcome, finish time and safe final refusals
     */
    private static function operationRow(AccountOperation $operation): array
    {
        return Database::sql(
            'SELECT `stage`, `outcome`, `finished_at`, `final_refusals` FROM `hilos_account_operation` WHERE `id` = ?',
            [$operation->id],
        )->row();
    }

    /**
     * @param bool $down Drop in reverse dependency order
     */
    private static function stubs(bool $down): void
    {
        foreach ($down ? array_reverse(self::TABLES) : self::TABLES as $table) {
            // external-boundary: up stub filenames carry no direction suffix
            $suffix = $down ? '_down' : '';
            Database::sqlRun((string)file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . $suffix . '.sql'));
        }
    }
}

/** The supporting owners retain their production ownership declarations. */
final class ErasureCoordinatorTestUsersLibrary extends AbstractUsersLibraryAgent
{
}

/** Exposes only the protected entry; transport and inline steps are the production implementation. */
final class ErasureCoordinatorTestHolder extends AbstractSessionsLibraryAgent
{
    /** @param AccountOperation $operation Open erasure to drive in the holder's frame */
    public function drive(AccountOperation $operation): void
    {
        $this->driveAccountErasure($operation);
    }
}

/** The data-export owner used both by direct fixtures and the real exchange. */
final class ErasureCoordinatorTestDataExport extends AbstractDataExportAgent
{
}

/** The legal-export owner used both by direct fixtures and the real exchange. */
final class ErasureCoordinatorTestLegal extends AbstractHilosLegalAgent
{
}

/** The final's future rights stay in the fixture until the production switch (HIL-1952). */
final class ErasureCoordinatorTestUserAgent extends AbstractUserAgent
{
    public const array OWNS_DB_ROWS = [
        HilosDbContext::users => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
    ];

    public const array OWNS_DB_SET = [
        ...AbstractUserAgent::OWNS_DB_SET,
        HilosDbContext::userRenames => [TruthSourceOperation::Add, TruthSourceOperation::Update, TruthSourceOperation::Remove],
        HilosDbContext::userMerges => [TruthSourceOperation::Remove],
    ];
}

/** Registered owners make the census expect every activated framework part. */
abstract class ErasureCoordinatorTestHilos extends Hilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_USER => [AgentRegistryKey::WORKER => ErasureCoordinatorTestUserAgent::class, AgentRegistryKey::INDEXED => true],
        HilosAgentType::HILOS_USERS_LIBRARY => [AgentRegistryKey::WORKER => ErasureCoordinatorTestUsersLibrary::class],
        HilosAgentType::HILOS_SESSIONS_LIBRARY => [AgentRegistryKey::WORKER => ErasureCoordinatorTestHolder::class],
        HilosAgentType::HILOS_DATA_EXPORT => [AgentRegistryKey::WORKER => ErasureCoordinatorTestDataExport::class],
        HilosAgentType::HILOS_LEGAL => [AgentRegistryKey::WORKER => ErasureCoordinatorTestLegal::class],
    ];
}

/** An installation before its data-export owner is registered, with no data-export rows. */
abstract class ErasureCoordinatorWithoutDataExportHilos extends ErasureCoordinatorTestHilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_USER => [AgentRegistryKey::WORKER => ErasureCoordinatorTestUserAgent::class, AgentRegistryKey::INDEXED => true],
        HilosAgentType::HILOS_USERS_LIBRARY => [AgentRegistryKey::WORKER => ErasureCoordinatorTestUsersLibrary::class],
        HilosAgentType::HILOS_SESSIONS_LIBRARY => [AgentRegistryKey::WORKER => ErasureCoordinatorTestHolder::class],
        HilosAgentType::HILOS_LEGAL => [AgentRegistryKey::WORKER => ErasureCoordinatorTestLegal::class],
    ];
}

/** The project participant owns the fixture's mounted note collection. */
abstract class ErasureCoordinatorProjectDirectory extends AccountDataOwnerDirectory
{
    public const string KEY = 'coordinator_project';

    /** @return list<AccountDataOwner> Project note owner */
    protected static function owners(): array
    {
        return [new AccountDataOwner(
            self::KEY, [ErasureCoordinatorNotes::COLLECTION_KEY], ErasureCoordinatorProjectOwner::AGENT_TYPE, false,
            static fn (int $userId): bool => Hilos::$db->getObjectCollection(ErasureCoordinatorNotes::COLLECTION_KEY)
                ->countByColumn(ErasureCoordinatorNote::user_id, $userId) > 0,
        )];
    }
}

/** The project uses the same participant step and answer translation as a real project owner. */
final class ErasureCoordinatorProjectOwner extends AbstractAgent implements AccountErasureProjectOwner
{
    public const string AGENT_TYPE = 'coordinator_project';
    public const string ACCOUNT_OPERATION_SIGNAL = 'coordinator_project_account_operation_step';
    public const array AGENT_SIGNALS = [self::ACCOUNT_OPERATION_SIGNAL => AccountOperationAskSignalData::class];
    public const array OWNS_DB = [ErasureCoordinatorNotes::COLLECTION_KEY => [TruthSourceOperation::Remove]];
    public const array OWNS_DB_SET = [HilosDbContext::accountOperationResults => [TruthSourceOperation::Add]];
    public const array READS_DB = [HilosDbContext::accountOperationAssignments, HilosDbContext::accountOperations];

    /** @var list<int> Accounts whose project work actually ran */
    public array $erasedAccounts = [];

    /**
     * @param string $collection Result collection owned by this agent
     * @return string Author of this owner's results
     */
    public function ownedDbSetKey(string $collection): string
    {
        return $this->getId();
    }

    /**
     * @param AccountErasurePart $part Account whose note is erased
     * @return AccountErasure The actual note erasure outcome
     */
    public function eraseAccountPart(AccountErasurePart $part): AccountErasure
    {
        $this->erasedAccounts[] = $part->userId;
        $notes = Hilos::$db->getObjectCollection(ErasureCoordinatorNotes::COLLECTION_KEY);
        $ids = iterator_to_array($notes->loadByColumn(ErasureCoordinatorNote::user_id, $part->userId), false);
        foreach ($ids as $id) {
            $notes[$id]->delete();
        }

        return new AccountErasure(['notes' => count($ids)], []);
    }

    /**
     * @param AgentSignalData $data Assignment address sent by the holder
     * @param string $sender Sender, unused
     * @param string $name Declared project step name
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case self::ACCOUNT_OPERATION_SIGNAL:
                $ask = $data->data;
                if (!$ask instanceof AccountOperationAskSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, AccountOperationAskSignalData::class, $ask);
                }
                $this->sendToAgent(HilosSignalConstants::HILOS_ACCOUNT_OPERATION_ANSWER, AccountOperationAsks::answer(
                    $ask,
                    fn (AccountOperationAssignment $assignment): AccountOperationResult => AccountErasureProjectStep::run($this, $assignment->id),
                    $this->getId(),
                ));

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /** Nothing to release in the controlled queue. */
    public function onStop(): void
    {
    }
}

/** One real mail shard, declared without changing environment or production settings. */
final class ErasureCoordinatorMailChannel extends MailDeliveryChannel
{
    /** @return int The fixture's one mail shard */
    public function poolSize(): int
    {
        return 1;
    }
}

/** The exchange activates mail without a sender, a transport or network I/O. */
abstract class ErasureCoordinatorChannels extends DeliveryChannelRegistry
{
    /** @return array<string, MailDeliveryChannel> The real mail channel's deterministic shard descriptor */
    protected static function channels(): array
    {
        return [MailDeliveryChannel::NAME => new ErasureCoordinatorMailChannel()];
    }
}

/** All real framework owners, plus a project participant and the real mail handler. */
abstract class ErasureCoordinatorExchangeHilos extends ErasureCoordinatorTestHilos
{
    protected const string ACCOUNT_DATA_OWNER_DIRECTORY = ErasureCoordinatorProjectDirectory::class;
    protected const string NOTIFICATION_CHANNEL_REGISTRY = ErasureCoordinatorChannels::class;

    public const array AGENTS = [
        ...parent::AGENTS,
        HilosAgentType::HILOS_MAIL => [AgentRegistryKey::WORKER => MailDeliveryChannelAgent::class, AgentRegistryKey::INDEXED => true],
        ErasureCoordinatorProjectOwner::AGENT_TYPE => [AgentRegistryKey::WORKER => ErasureCoordinatorProjectOwner::class],
    ];
}

/** Framework collections plus the project's one account-bound note. */
final class ErasureCoordinatorContext extends HilosDbContext
{
    /** Mounts the framework and the test project's note collection. */
    public function configure(): void
    {
        parent::configure();
        $this->mountObjectCollection(ErasureCoordinatorNotes::class, Objects::LAZY_STRATEGY_KEY);
    }
}

/** The project fixture's only persisted field is also its primary account key. */
final class ErasureCoordinatorNote extends Entity
{
    public const string user_id = 'user_id';
    public const string _table = 'hilos_fw_test_coordinator_note';
    public const string _primary = self::user_id;
    public const array _columns = [self::user_id];
    public const array _types = [self::user_id => PhpType::INTEGER->value];

    public int $user_id;
}

/** @extends Object_<ErasureCoordinatorNote> */
final class ErasureCoordinatorNoteObject extends Object_
{
    public const string ENTITY_CLASS = ErasureCoordinatorNote::class;
}

/** @extends Objects<ErasureCoordinatorNoteObject> */
final class ErasureCoordinatorNotes extends Objects
{
    public const string COLLECTION_KEY = 'coordinatorProjectRows';
    public const string OBJECT_CLASS = ErasureCoordinatorNoteObject::class;
}

/** The legal handler's real after-commit sweep reads an isolated temporary directory. */
final class ErasureCoordinatorFs extends FsContext
{
    /** @param string $path Isolated legal-export directory */
    public function __construct(private readonly string $path)
    {
    }

    /** Registers the real legal-export directory contract. */
    public function configure(): void
    {
        $this->registerDirectory(self::LEGAL_EXPORT, $this->path, DirectoryScope::CLUSTER);
    }
}
