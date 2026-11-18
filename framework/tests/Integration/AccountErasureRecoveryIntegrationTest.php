<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Generator;
use Hilos\Auth\AccountOperation\AccountDataOwner;
use Hilos\Auth\AccountOperation\AccountDataOwnerDirectory;
use Hilos\Auth\AccountOperation\AccountErasureCapture;
use Hilos\Auth\AccountOperation\AccountErasureCoordinator;
use Hilos\Auth\AccountOperation\AccountErasureFinal;
use Hilos\Auth\AccountOperation\AccountErasureOpening;
use Hilos\Auth\AccountOperation\AccountErasurePart;
use Hilos\Auth\AccountOperation\AccountErasureParticipantCatalog;
use Hilos\Auth\AccountOperation\AccountErasureProjectOwner;
use Hilos\Auth\AccountOperation\AccountErasureProjectStep;
use Hilos\Auth\AccountOperation\AccountErasureStart;
use Hilos\Auth\AccountOperation\AccountErasureStartPayload;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountOperationAsks;
use Hilos\Auth\AccountOperation\AccountOperationExpectation;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
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
use Hilos\Core\Daemon\Cron\CronScheduleTestOperation;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\ChangeLog\JournalTriggerRenderer;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\PhpType;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Verification\VerificationType;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\DataExport\AbstractDataExportAgent;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use Hilos\Legal\Export\LegalAcceptancesExports;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Notification\Delivery\ChannelSettingsCatalog;
use Hilos\Notification\Delivery\DeliveryChannelRegistry;
use Hilos\Notification\Delivery\DeliveryChannelSettings;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\AccountErasure;
use Hilos\Users\AccountErasureProgressChangeSubscriber;
use Hilos\Users\AccountErasureProgressResolver;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\Utils\Logger;

/**
 * The recovery side of the erasure (HIL-1951): a session holder that starts - after a death, a
 * move or a restart - walks the open erasures on its first tick and enters each from the saved
 * facts, then once an hour. Every holder here is a new agent object started through its real
 * onStart() on the database its predecessor left, and every step runs through its real handler
 * over a controlled signal queue; a lost frame is one the case takes off the queue unanswered.
 * The coordinator's owner reading, owedTo(), is checked against the same facts, and so is an
 * owner raised the same way: it runs what it is owed through its own assignment handler.
 */
final class AccountErasureRecoveryIntegrationTest extends HilosSessionIntegrationTestCase
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

    /** Journal tables, children first, as the receipt case raises and drops them. */
    private const array JOURNAL_TABLES = [
        'hilos_change_log_value', 'hilos_change_log_change', 'hilos_change_log',
        'hilos_change_log_receipt', 'hilos_change_log_field', 'hilos_change_log_table',
    ];

    /** @var list<AbstractAgent> Supporting owners whose result sets are claimed for each case */
    private array $owners = [];
    private ?ErasureRecoveryTestHolder $holder = null;
    private string $previousApp;
    private ?SignalRouter $previousRouter;
    private ?FsContext $previousFs;
    private ?RtContext $previousRt;
    private string $exportDirectory = '';
    private string $logDirectory = '';
    private bool $journalRaised = false;

    protected function setUp(): void
    {
        parent::setUp();
        self::stubs(down: true);
        self::stubs(down: false);
        Database::sql('DROP TABLE IF EXISTS `hilos_fw_test_recovery_note`');
        Database::sql(
            'CREATE TABLE `hilos_fw_test_recovery_note` (`user_id` INT UNSIGNED NOT NULL PRIMARY KEY,'
                . ' FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT) ENGINE=InnoDB',
        );
        Schema::reset();
        Schema::initialize();
        $this->previousApp = Hilos::appClass();
        $this->previousRouter = Hilos::$sr;
        $this->previousFs = Hilos::$fs;
        $this->previousRt = Hilos::$rt;
        LegalAcceptancesExports::reset();
        Hilos::$sr = new SignalRouter();
        ErasureRecoveryTestHilos::initBrowser();
        Hilos::$db = new ErasureRecoveryContext();
        Hilos::$db->configure();
        $rt = new ErasureRecoveryRtContext();
        $rt->mountFeatureRuntime([new AuthFeature()]);
        $rt->configure();
        $rt->bindStateCollectionNames();
        Hilos::$rt = $rt;
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        SourceChangeBus::subscribe(new AccountErasureProgressChangeSubscriber());
        AccountErasureProgressResolver::forgetAll();
        $this->exportDirectory = sys_get_temp_dir() . '/hilos-erasure-recovery-' . bin2hex(random_bytes(8));
        Hilos::$fs = new ErasureRecoveryFs($this->exportDirectory);
        Hilos::$fs->configure();
        Hilos::$fs->getDirectory(FsContext::LEGAL_EXPORT)->ensureDirectory();
        $this->logDirectory = sys_get_temp_dir() . '/hilos-erasure-recovery-log-' . bin2hex(random_bytes(8));
        mkdir($this->logDirectory);
        Logger::setLogFile($this->logDirectory . '/daemon.log');
        foreach ([new ErasureRecoveryTestUsersLibrary(), new ErasureRecoveryTestDataExport(), new ErasureRecoveryTestLegal()] as $agent) {
            OwnershipDeclaration::claimDb($agent::class, $agent->getId());
            OwnershipDeclaration::claimDbSet($agent);
            $this->owners[] = $agent;
        }
        foreach ([new MailDeliveryChannelAgent('1'), new ErasureRecoveryProjectOwner()] as $agent) {
            OwnershipDeclaration::claimAll($agent);
            $this->owners[] = $agent;
        }
        // The holder that opens the erasures of a case; it falls without a tick, and each case starts its heirs.
        $this->claimHolder();
    }

    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            Database::sql('DROP TABLE IF EXISTS `hilos_fw_test_recovery_note`');
            self::stubs(down: true);
            if ($this->journalRaised) {
                $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
                foreach (self::JOURNAL_TABLES as $table) {
                    Database::sqlRun("DROP TABLE IF EXISTS {$journal}.`{$table}`");
                }
            }
            Schema::reset();
        } finally {
            $this->releasePersonAgents();
            $this->releaseHolder();
            foreach ($this->owners as $agent) {
                TruthSourceRegistry::unregisterAgent($agent->getId());
                SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agent->getId()));
            }
            Logger::resetLogFile();
            foreach (array_diff((array)scandir($this->logDirectory), ['.', '..']) as $entry) {
                unlink($this->logDirectory . '/' . $entry);
            }
            rmdir($this->logDirectory);
            $this->previousApp::initBrowser();
            Hilos::resetBrowser();
            Hilos::$sr = $this->previousRouter;
            Hilos::$fs = $this->previousFs;
            Hilos::$rt = $this->previousRt;
            LegalAcceptancesExports::reset();
            FsPath::removeDirectory($this->exportDirectory);
            FsPath::removeDirectory($this->exportDirectory . '-data');
            SourceChangeBus::reset();
            AccountErasureProgressResolver::forgetAll();
            parent::tearDown();
        }
    }

    /** Case 1: a new holder resends the duties whose frames died, and the circle ends only with its final. */
    public function testANewHolderResendsTheDutiesWhoseFramesWereLost(): void
    {
        $root = self::seedPerson();
        $operation = $this->openErasure($root);
        $this->startHolder();
        $this->tick();
        $start = $this->operationFrames();
        self::assertSame([AccountErasureStart::personalKey($root)], $this->frameKeys($start));
        $this->deliverOperationFrame($start[0]);
        $this->deliverOperationFrame($this->operationFrames()[0]);
        $lost = $this->operationFrames();
        self::assertNotEmpty($lost);

        $this->startHolder();
        $this->tick();

        $resent = $this->operationFrames();
        self::assertSame(self::sorted($this->frameIds($lost)), self::sorted($this->frameIds($resent)));
        $this->tick();
        self::assertSame([], $this->operationFrames());
        foreach ($resent as $frame) {
            $this->deliverOperationFrame($frame);
        }
        $finals = $this->exchangeHolding($this->isFinalAsk(...));
        self::assertSame([AccountErasureFinal::personalKey($root)], array_values(array_unique($this->frameKeys($finals))));
        self::assertNull(self::operationRow($operation)['finished_at']);
        foreach ($finals as $final) {
            $this->deliverOperationFrame($final);
        }
        self::assertSame([], $this->exchangeHolding(static fn (SignalDTO $frame): bool => false));
        self::assertSame('completed', self::operationRow($operation)['outcome']);
        self::assertSame([$root], $this->projectOwner()->erasedAccounts);
    }

    /** A new holder resends a lost forced start with its saved true mode, which only the personal owner executes. */
    public function testANewHolderRecoversTheForcedModeAfterTheFirstStartFrameIsLost(): void
    {
        $root = self::seedPerson();
        $operation = $this->openErasure($root, true);
        $request = Hilos::$db->accountDeletions[$operation->requestId];
        $originalDate = $request->effectiveAt;
        $start = self::assignment($operation, AccountErasureStart::personalKey($root));
        self::assertTrue(AccountErasureStartPayload::expireGraceOf($start->payload));
        $this->startHolder();
        $this->tick();
        $lost = $this->operationFrames();
        self::assertSame([AccountErasureStart::personalKey($root)], $this->frameKeys($lost));

        $this->startHolder();
        $this->tick();

        $resent = $this->operationFrames();
        self::assertSame($this->frameIds($lost), $this->frameIds($resent));
        $row = Database::sql('SELECT `effective_at`, `started_at` FROM `hilos_account_deletion` WHERE `id` = ?', [$request->id])->row();
        self::assertSame($originalDate, (int)$row['effective_at']);
        self::assertNull($row['started_at']);
        $before = TimeHelper::nowMs();
        $this->deliverOperationFrame($resent[0]);
        $after = TimeHelper::nowMs();

        self::assertSame(['done'], self::answerKinds($this->operationFrames()));
        $row = Database::sql('SELECT `effective_at`, `started_at` FROM `hilos_account_deletion` WHERE `id` = ?', [$request->id])->row();
        self::assertGreaterThanOrEqual($before, (int)$row['effective_at']);
        self::assertLessThanOrEqual($after, (int)$row['effective_at']);
        self::assertNotNull($row['started_at']);
        self::assertSame(
            '{"start":"started"}',
            Database::sql(
                'SELECT `payload_json` FROM `hilos_account_operation_result` WHERE `assignment_id` = ?',
                [$start->id],
            )->row()['payload_json'],
        );
    }

    /** Case 2: the first tick of a new holder assigns the successors of a lost answer and does not repeat its part. */
    public function testANewHolderAssignsTheSuccessorsOfALostAnswerWithoutRepeatingThePart(): void
    {
        $root = self::seedPerson();
        $operation = $this->openErasure($root);
        $this->startHolder();
        $this->tick();
        $this->deliverOperationFrame($this->operationFrames()[0]);
        $this->deliverOperationFrame($this->operationFrames()[0]);
        $captureKey = AccountErasureCapture::personalKey($root);
        $lost = $this->exchangeHolding(fn (SignalDTO $frame): bool => $this->isFinalAsk($frame)
            || ($frame->data->data instanceof AccountOperationAnswerSignalData && $this->frameAssignment($frame)->assignmentKey === $captureKey));
        self::assertCount(1, $lost);
        self::assertInstanceOf(AccountOperationAnswerSignalData::class, $lost[0]->data->data);
        $capture = self::assignment($operation, $captureKey);
        $personal = $this->part($operation, AccountErasureParticipantCatalog::PERSONAL, $root);
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($personal->id));
        $captureResult = self::resultRowsOf($capture->id);
        self::assertCount(1, $captureResult);
        $assignments = self::rows('hilos_account_operation_assignment');
        $results = self::rows('hilos_account_operation_result');

        $this->startHolder();
        $this->tick();

        $successors = $this->operationFrames();
        self::assertContains($personal->id, $this->frameIds($successors));
        self::assertNotContains($capture->id, $this->frameIds($successors));
        self::assertSame($assignments, self::rows('hilos_account_operation_assignment'));
        self::assertSame($results, self::rows('hilos_account_operation_result'));
        foreach ($successors as $frame) {
            $this->deliverOperationFrame($frame);
        }
        $finals = $this->exchangeHolding($this->isFinalAsk(...));
        self::assertSame([AccountErasureFinal::personalKey($root)], array_values(array_unique($this->frameKeys($finals))));
        self::assertNull(self::operationRow($operation)['finished_at']);
        self::assertSame($captureResult, self::resultRowsOf($capture->id));
        foreach ($finals as $final) {
            $this->deliverOperationFrame($final);
        }
        $this->exchangeHolding(static fn (SignalDTO $frame): bool => false);
        self::assertSame('completed', self::operationRow($operation)['outcome']);
        self::assertSame([$root], $this->projectOwner()->erasedAccounts);
    }

    /** Case 3: the first tick of a new holder reads the end of an erasure whose last final answer died. */
    public function testANewHolderEndsAnErasureWhoseLastFinalAnswerWasLost(): void
    {
        $root = self::seedPerson();
        $operation = $this->openErasure($root);
        $this->startHolder();
        $this->tick();
        $finals = $this->exchangeHolding($this->isFinalAsk(...));
        self::assertCount(1, $finals);
        $this->deliverOperationFrame($finals[0]);
        $lost = $this->operationFrames();
        self::assertCount(1, $lost);
        self::assertInstanceOf(AccountOperationAnswerSignalData::class, $lost[0]->data->data);
        self::assertNull(self::operationRow($operation)['finished_at']);
        $assignments = self::rows('hilos_account_operation_assignment');

        $this->startHolder();
        $this->tick();

        self::assertSame('completed', self::operationRow($operation)['outcome']);
        self::assertSame([], $this->operationFrames());
        self::assertSame($assignments, self::rows('hilos_account_operation_assignment'));
    }

    /** Case 4: a quiet tick after a pass sends and opens nothing; the armed rule starts a new pass under its own receipt. */
    public function testAQuietTickSendsNothingAndTheArmedRuleStartsAPassUnderItsOwnReceipt(): void
    {
        $this->raiseJournal();
        $root = self::seedPerson();
        $this->openErasure($root);
        $this->startHolder();
        $this->tick();
        $first = $this->operationFrames();
        self::assertSame([AccountErasureStart::personalKey($root)], $this->frameKeys($first));
        $receipts = self::recoveryReceipts();
        self::assertCount(1, $receipts, 'The first pass ran under one receipt');

        $this->tick();

        self::assertSame([], $this->operationFrames());
        self::assertSame($receipts, self::recoveryReceipts(), 'A quiet tick opens no receipt');

        self::assertNotNull($this->holder?->armCronRuleForTest('hilos_account_erasure_recovery'));
        $this->tick();

        self::assertSame($this->frameIds($first), $this->frameIds($this->operationFrames()));
        self::assertCount(2, self::recoveryReceipts(), 'The pass of the rule opens a receipt of its own');
    }

    /** The erasure-only first and hourly passes keep their owner-visible debts and historical cron attribution. */
    public function testErasureFirstAndHourlyPassPreserveOwedDutiesAndReceiptAttribution(): void
    {
        $this->raiseJournal();
        $root = self::seedPerson();
        $operation = $this->openErasure($root);
        $owner = new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$root);
        $this->startHolder();
        $this->tick();
        $first = $this->operationFrames();
        self::assertSame([AccountErasureStart::personalKey($root)], $this->frameKeys($first));
        self::assertSame($this->frameIds($first), $this->owedIds($root, $owner));
        self::assertSame([$operation->id], self::operationIds($first));
        $receipt = self::recoveryReceipts()[0];
        self::assertSame(HilosAgentType::HILOS_SESSIONS_LIBRARY, $receipt['agent']);
        self::assertSame('hilos_account_erasure_recovery (0 * * * *)', $receipt['source']);

        self::assertNotNull($this->holder?->armCronRuleForTest('hilos_account_erasure_recovery'));
        $this->tick();

        self::assertSame($this->frameIds($first), $this->frameIds($this->operationFrames()));
        self::assertSame($this->frameIds($first), $this->owedIds($root, $owner));
        self::assertCount(2, self::recoveryReceipts());
        self::assertNull($operation->finishedAt);
    }

    /** A shared pass keeps erasure events alongside merge preparations under one receipt and one ten-operation portion. */
    public function testSharedPassKeepsErasureDutiesAndOneReceiptAcrossTheMixedPortion(): void
    {
        $this->raiseJournal();
        $operations = [];
        for ($index = 0; $index < 11; $index++) {
            if ($index % 2 === 0) {
                $operations[] = $this->openErasure(self::seedPerson())->id;
            } else {
                Database::sql("INSERT INTO `hilos_user` (`name`) VALUES ('Merge survivor')");
                $survivor = Database::lastInsertId();
                Database::sql("INSERT INTO `hilos_user` (`name`) VALUES ('Merge loser')");
                $loser = Database::lastInsertId();
                $operations[] = $this->asHolder(fn () => $this->holder->openMerge($survivor, $loser))->id;
            }
        }
        $this->operationFrames();
        $holder = $this->startHolder();
        $this->tick();
        $first = $this->operationFrames();
        self::assertSame(array_slice($operations, 0, 10), $holder->entered);
        self::assertSame(array_slice($operations, 0, 10), array_values(array_unique(self::operationIds($first))));
        self::assertCount(15, $first, 'Five erasures owe one start each; five merges owe two preparations each.');
        $receipts = self::recoveryReceipts();
        self::assertCount(1, $receipts);

        $this->tick();

        self::assertSame($operations, $holder->entered);
        self::assertSame([$operations[10]], self::operationIds($this->operationFrames()));
        self::assertSame($receipts, self::recoveryReceipts());
        $this->tick();
        self::assertSame([], $this->operationFrames());
        self::assertSame($operations, $holder->entered);
    }

    /** Case 5: eleven open erasures take two ticks, and an entry that fails is logged while the rest are entered. */
    public function testAPassEntersOnePortionATickAndLogsAFailingEntry(): void
    {
        $operations = [];
        for ($i = 0; $i < 11; $i++) {
            $operations[] = $this->openErasure(self::seedPerson());
        }
        $failing = $operations[3];
        $this->startHolder()->failingOperationId = $failing->id;

        $this->tick();

        $expected = array_map(
            static fn (AccountOperation $operation): int => $operation->id,
            array_values(array_filter(array_slice($operations, 0, 10), static fn (AccountOperation $operation): bool => $operation !== $failing)),
        );
        self::assertSame($expected, self::operationIds($this->operationFrames()));
        self::assertStringContainsString(
            "Account erasure recovery of operation {$failing->id} failed, retried by the next pass: kind=erasure; entry failed",
            $this->agentLog(),
        );
        self::assertStringNotContainsString('Private fixture payload', $this->agentLog());

        $this->tick();

        self::assertSame([$operations[10]->id], self::operationIds($this->operationFrames()));

        $this->tick();

        self::assertSame([], $this->operationFrames());
    }

    /** Case 10: owedTo() gives only what the coordinator would send the owner now, and writes nothing. */
    public function testOwedToGivesOnlyTheReadyDutiesWithoutAResultAndWritesNothing(): void
    {
        $root = self::seedPerson();
        $operation = $this->openErasure($root);
        $owner = new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$root);
        $this->startHolder();
        $this->tick();
        $start = $this->operationFrames();
        self::assertSame([AccountErasureStart::personalKey($root)], $this->frameKeys($start));

        self::assertSame($this->frameIds($start), $this->owedIds($root, $owner));

        $this->deliverOperationFrame($start[0]);
        $lost = $this->operationFrames();
        self::assertCount(1, $lost);
        self::assertInstanceOf(AccountOperationAnswerSignalData::class, $lost[0]->data->data);

        self::assertSame([], $this->owedIds($root, $owner));

        $this->startHolder();
        $this->tick();
        $wave = array_values(array_filter(
            $this->operationFrames(),
            fn (SignalDTO $frame): bool => $this->frameAssignment($frame)->owner->equals($owner),
        ));
        self::assertSame([AccountErasureCapture::personalKey($root)], $this->frameKeys($wave));
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment(
            $this->part($operation, AccountErasureParticipantCatalog::PERSONAL, $root)->id,
        ));

        self::assertSame($this->frameIds($wave), $this->owedIds($root, $owner));
    }

    /** Case 6: new library and export owners run their lost duties at start, and the coordinator then assigns the final. */
    public function testNewOwnersRunTheirReadyDutiesAtStartAndTheFinalFollows(): void
    {
        $root = self::seedPerson();
        self::seedVerification($root);
        self::seedDataExport($root);
        $this->openErasure($root);
        $this->startHolder();
        $this->tick();
        $library = new AccountOperationOwnerAddress(HilosAgentType::HILOS_USERS_LIBRARY);
        $export = new AccountOperationOwnerAddress(HilosAgentType::HILOS_DATA_EXPORT);
        $lost = $this->exchangeHolding(fn (SignalDTO $frame): bool => $this->isAskTo($frame, $library) || $this->isAskTo($frame, $export));
        self::assertCount(2, $lost);

        $this->raiseOwner(new ErasureRecoveryTestUsersLibrary());
        $this->raiseOwner(new ErasureRecoveryTestDataExport());

        $answers = $this->operationFrames();
        self::assertSame(self::sorted($this->frameIds($lost)), self::sorted($this->frameIds($answers)));
        self::assertSame(['done', 'done'], self::answerKinds($answers));
        self::assertStringContainsString('Resumed 1 account operation assignment(s) at start', $this->agentLog());
        foreach ($answers as $answer) {
            $this->deliverOperationFrame($answer);
        }
        $finals = $this->exchangeHolding($this->isFinalAsk(...));
        self::assertSame([AccountErasureFinal::personalKey($root)], array_values(array_unique($this->frameKeys($finals))));
    }

    /** Case 7: a new shard of a switched-off channel erases the captured deliveries at start and answers done. */
    public function testANewShardOfAnOfflineChannelErasesTheCapturedDeliveriesAtStart(): void
    {
        $previousSetting = Hilos::$setting;
        Hilos::$setting = new SettingsAccessor(ChannelSettingsCatalog::class);
        try {
            self::assertFalse(Hilos::$setting[DeliveryChannelSettings::enabledKey(MailDeliveryChannel::NAME)]->bool());
            $root = self::seedPerson();
            $delivery = self::seedDelivery($root);
            $this->openErasure($root);
            $this->startHolder();
            $this->tick();
            $shard = new AccountOperationOwnerAddress(HilosAgentType::HILOS_MAIL, '1');
            $lost = $this->exchangeHolding(fn (SignalDTO $frame): bool => $this->isAskTo($frame, $shard));
            self::assertCount(1, $lost);
            self::assertSame([$delivery], self::deliveryIds());

            $this->raiseOwner(new MailDeliveryChannelAgent('1'));

            $answers = $this->operationFrames();
            self::assertSame($this->frameIds($lost), $this->frameIds($answers));
            self::assertSame(['done'], self::answerKinds($answers));
            self::assertSame([], self::deliveryIds());
        } finally {
            Hilos::$setting = $previousSetting;
        }
    }

    /** Case 8: a new personal agent applies its saved trust restriction and then runs its ready part under a receipt of its own. */
    public function testANewPersonalAgentAppliesItsTrustRestrictionAndRunsItsReadyPart(): void
    {
        $this->raiseJournal();
        $root = self::seedPerson();
        $now = TimeHelper::nowMs();
        $day = TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND;
        self::seedTrust($root, $now - 2 * $day, $now + 30 * $day);
        $operation = $this->openErasure($root);
        $this->startHolder();
        $this->tick();
        $person = new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$root);
        $lost = $this->exchangeHolding(fn (SignalDTO $frame): bool => $this->isAskTo($frame, $person)
            && AccountOperationExpectation::isExpectKey($this->frameAssignment($frame)->assignmentKey));
        self::assertSame([$this->part($operation, AccountErasureParticipantCatalog::PERSONAL, $root)->id], $this->frameIds($lost));
        Database::sql('INSERT INTO `hilos_second_factor_trust_restriction` (`saved_at`, `term_days`) VALUES (?, 1)', [$now]);
        $restrictions = self::rows('hilos_second_factor_trust_restriction');
        $agent = $this->restartPerson($root);

        $this->inFrameOf($agent, static fn () => $agent->onStart());

        self::assertSame([$now + $day], self::trustLimits($root));
        $writes = new ErasureRecoveryReceiptRecorder();
        SourceChangeBus::subscribe($writes);

        $this->inFrameOf($agent, static fn () => AccountOperationAsks::resume($agent, HilosAgentType::HILOS_USER, (string)$root));

        $answers = $this->operationFrames();
        self::assertSame($this->frameIds($lost), $this->frameIds($answers));
        self::assertSame(['done'], self::answerKinds($answers));
        self::assertCount(1, self::resultRowsOf($lost[0]->data->data->assignmentId));
        self::assertSame([], self::trustLimits($root));
        self::assertSame($restrictions, self::rows('hilos_second_factor_trust_restriction'));
        $receiptId = $answers[0]->data->receiptId;
        self::assertNotNull($receiptId, 'The answer carries the receipt of the duty');
        self::assertSame(
            ['channel' => 'agent', 'action' => $agent::ACCOUNT_OPERATION_SIGNAL, 'agent' => $agent->getId(), 'source' => $agent->getId()],
            self::receipt($receiptId),
        );
        self::assertNotSame([], $writes->receiptIds);
        foreach ($writes->receiptIds as $source => $receiptIds) {
            self::assertSame([$receiptId], array_values(array_unique($receiptIds)), "Every write of {$source} in the duty runs under its receipt");
        }
    }

    /** Case 9: a folded account's new agent runs its one ready capture, asks to stop and leaves the rest to the coordinator. */
    public function testAFoldedAccountsNewAgentRunsOneDutyStopsAndSendsItselfNothing(): void
    {
        $root = self::seedPerson();
        $folded = self::seedPerson();
        Database::sql(
            'INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (?, ?, ?)',
            [$folded, $root, TimeHelper::nowMs()],
        );
        $operation = $this->openErasure($root);
        $this->startHolder();
        $this->tick();
        $owner = new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$folded);
        $lost = $this->exchangeHolding(fn (SignalDTO $frame): bool => $this->isAskTo($frame, $owner));
        self::assertSame([AccountErasureCapture::personalKey($folded)], $this->frameKeys($lost));
        $agent = $this->restartPerson($folded);

        $this->startOwner($agent);

        self::assertTrue($agent->shouldStop());
        $answers = $this->operationFrames();
        self::assertSame($this->frameIds($lost), $this->frameIds($answers));
        self::assertSame(['done'], self::answerKinds($answers));
        $part = $this->part($operation, AccountErasureParticipantCatalog::PERSONAL, $folded);
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($part->id));
        self::assertSame([$part->id], $this->owedIds($folded, $owner));
        $this->deliverOperationFrame($answers[0]);
        self::assertSame([$part->id], $this->frameIds($this->exchangeHolding(fn (SignalDTO $frame): bool => $this->isAskTo($frame, $owner))));
    }

    /** Case 10, the start's side: an erasure with no won start gives the personal agent its root's start alone, and once. */
    public function testANewPersonalAgentResumesOnlyTheStartOfAnUnstartedErasureAndNothingWithAResult(): void
    {
        $root = self::seedPerson();
        $operation = $this->openErasure($root);
        $this->startHolder();
        $this->tick();
        $lost = $this->operationFrames();
        self::assertSame([AccountErasureStart::personalKey($root)], $this->frameKeys($lost));

        $this->startOwner($this->restartPerson($root));

        $answers = $this->operationFrames();
        self::assertSame($this->frameIds($lost), $this->frameIds($answers));
        self::assertSame(['done'], self::answerKinds($answers));
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment(
            $this->part($operation, AccountErasureParticipantCatalog::PERSONAL, $root)->id,
        ));

        $this->startOwner($this->restartPerson($root));

        self::assertSame([], $this->operationFrames());
    }

    /**
     * Starts a new holder on what the database holds, as a worker raising it after a death, a
     * move or a restart does: the one before is dropped without its stop, and its claims with it.
     * Its hourly recovery rule is paused, so no tick of a case strikes the hour by the wall clock;
     * a case that wants the rule's pass arms it.
     *
     * @return ErasureRecoveryTestHolder The started holder
     */
    private function startHolder(): ErasureRecoveryTestHolder
    {
        $holder = $this->claimHolder();
        $this->asHolder(static fn () => $holder->onStart());
        $holder->changeCronScheduleForTest('hilos_account_erasure_recovery', CronScheduleTestOperation::Pause, 'quiet-ticks');

        return $holder;
    }

    /**
     * Drops the current holder without its stop and lays the claims of a new one.
     *
     * @return ErasureRecoveryTestHolder The new holder, not started
     */
    private function claimHolder(): ErasureRecoveryTestHolder
    {
        $this->releaseHolder();
        $holder = new ErasureRecoveryTestHolder();
        OwnershipDeclaration::claimDb($holder::class, $holder->getId());
        OwnershipDeclaration::claimRt($holder::class, $holder->getId());
        OwnershipDeclaration::claimDbSet($holder);
        $this->holder = $holder;

        return $holder;
    }

    /** Drops the current holder's claims, if one was started. */
    private function releaseHolder(): void
    {
        if ($this->holder === null) {
            return;
        }
        TruthSourceRegistry::unregisterAgent($this->holder->getId());
        RtTruthSourceRegistry::unregisterAgent($this->holder->getId());
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->holder->getId()));
        $this->holder = null;
    }

    /** Runs one tick of the current holder in its own frame. */
    private function tick(): void
    {
        $holder = $this->holder;
        self::assertNotNull($holder);
        $this->asHolder(static fn () => $holder->onTick());
    }

    /**
     * @param int $root Person whose agent reads what it owes
     * @param AccountOperationOwnerAddress $owner Address of that agent
     * @return list<int> Assignment ids owedTo() gives, after checking that it wrote nothing
     */
    private function owedIds(int $root, AccountOperationOwnerAddress $owner): array
    {
        $before = self::operationTables();
        $owed = $this->asPerson($root, static fn (): array => AccountErasureCoordinator::owedTo($owner));
        self::assertSame($before, self::operationTables());
        self::assertSame([], $this->operationFrames());

        return array_map(static fn (AccountOperationAssignment $assignment): int => $assignment->id, $owed);
    }

    /**
     * Raises a new instance of a supporting owner on what the database holds, as its worker does
     * after a death, a move or a restart: the one before is dropped without its stop, the new one
     * is claimed as setUp() claims its kind, then started and resumed.
     *
     * @param AbstractAgent $agent New instance, not yet claimed
     */
    private function raiseOwner(AbstractAgent $agent): void
    {
        foreach ($this->owners as $owner) {
            if ($owner->getId() === $agent->getId()) {
                TruthSourceRegistry::unregisterAgent($owner->getId());
                SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($owner->getId()));
            }
        }
        $this->owners = array_values(array_filter(
            $this->owners,
            static fn (AbstractAgent $owner): bool => $owner->getId() !== $agent->getId(),
        ));
        if ($agent instanceof MailDeliveryChannelAgent) {
            OwnershipDeclaration::claimAll($agent);
        } else {
            OwnershipDeclaration::claimDb($agent::class, $agent->getId());
            OwnershipDeclaration::claimDbSet($agent);
        }
        $this->owners[] = $agent;
        $this->startOwner($agent);
    }

    /**
     * @param int $userId Person whose agent the worker raises again
     * @return AbstractUserAgent A new agent of the person under its claims, not started; the one before is dropped without its stop
     */
    private function restartPerson(int $userId): AbstractUserAgent
    {
        $previous = $this->personAgents[$userId] ?? null;
        if ($previous !== null) {
            TruthSourceRegistry::unregisterAgent($previous->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($previous->getId()));
            unset($this->personAgents[$userId]);
        }

        return $this->personAgent($userId);
    }

    /** @param AbstractAgent $agent Owner started and resumed in its own frame, as its worker does */
    private function startOwner(AbstractAgent $agent): void
    {
        $this->inFrameOf($agent, static function () use ($agent): void {
            $agent->onStart();
            AccountOperationAsks::resume($agent, $agent->getType(), $agent->getIndex());
        });
    }

    /**
     * @param AbstractAgent $agent Agent whose frame the step runs in
     * @param callable(): mixed $step Step to run
     */
    private function inFrameOf(AbstractAgent $agent, callable $step): void
    {
        ExecutionContext::run(new ExecutionFrame(agentId: $agent->getId()), $step);
    }

    /**
     * @param SignalDTO $frame Queued operation frame
     * @param AccountOperationOwnerAddress $owner Owner it may be addressed to
     * @return bool Whether it asks that owner for a duty
     */
    private function isAskTo(SignalDTO $frame, AccountOperationOwnerAddress $owner): bool
    {
        return $frame->data->data instanceof AccountOperationAskSignalData && $this->frameAssignment($frame)->owner->equals($owner);
    }

    /**
     * @param list<SignalDTO> $frames Queued operation frames, every one an answer
     * @return list<string> Their answer kinds as the wire carries them, in queue order
     */
    private static function answerKinds(array $frames): array
    {
        return array_map(static function (SignalDTO $frame): string {
            self::assertInstanceOf(AccountOperationAnswerSignalData::class, $frame->data->data);

            return $frame->data->data->answer->value;
        }, $frames);
    }

    /** @return ErasureRecoveryProjectOwner The project participant for the exchange */
    private function projectOwner(): ErasureRecoveryProjectOwner
    {
        foreach ($this->owners as $owner) {
            if ($owner instanceof ErasureRecoveryProjectOwner) {
                return $owner;
            }
        }
        self::fail('The project owner is not raised');
    }

    /**
     * @param AccountOperationAnswerSignalData $answer Frame under the current holder's address judge
     * @param string $sender Canonical source preserved by the queued frame
     */
    private function receiveAnswer(AccountOperationAnswerSignalData $answer, string $sender): void
    {
        $holder = $this->holder;
        self::assertNotNull($holder);
        $this->asHolder(static fn () => $holder->onSignalAgent(
            new AgentSignalData($answer), $sender, HilosSignalConstants::HILOS_ACCOUNT_OPERATION_ANSWER,
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

    /**
     * @param list<SignalDTO> $frames Queued assignment frames
     * @return list<int> Their assignment ids, in queue order
     */
    private function frameIds(array $frames): array
    {
        return array_map(fn (SignalDTO $frame): int => $this->frameAssignment($frame)->id, $frames);
    }

    /**
     * @param list<SignalDTO> $frames Queued assignment frames
     * @return list<string> Their assignment keys, in queue order
     */
    private function frameKeys(array $frames): array
    {
        return array_map(fn (SignalDTO $frame): string => $this->frameAssignment($frame)->assignmentKey, $frames);
    }

    /**
     * @param list<SignalDTO> $frames Queued assignment frames
     * @return list<int> The operations they belong to, in queue order
     */
    private static function operationIds(array $frames): array
    {
        return array_map(static fn (SignalDTO $frame): int => $frame->data->data->operationId, $frames);
    }

    /**
     * @param SignalDTO $frame Queued operation frame
     * @return bool Whether it asks an account's final
     */
    private function isFinalAsk(SignalDTO $frame): bool
    {
        return $frame->data->data instanceof AccountOperationAskSignalData
            && str_starts_with($this->frameAssignment($frame)->assignmentKey, AccountErasureFinal::FINAL_PREFIX . ':');
    }

    /** @param SignalDTO $frame Queued ask or answer delivered in its real owner's execution frame */
    private function deliverOperationFrame(SignalDTO $frame): void
    {
        self::assertInstanceOf(AgentSignalData::class, $frame->data);
        $payload = $frame->data->data;
        if ($payload instanceof AccountOperationAnswerSignalData) {
            self::assertSame(HilosSignalConstants::HILOS_ACCOUNT_OPERATION_ANSWER, $frame->signalName->getName());
            $this->receiveAnswer($payload, SignalSource::describe($frame->signalSource));

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
            $frame->data, SignalSource::describe($frame->signalSource), $frame->signalName->getName(),
        ));
    }

    /**
     * Delivers every operation frame until the queue settles, holding back the ones the case loses.
     *
     * @param callable(SignalDTO): bool $hold Whether a frame is held back instead of delivered
     * @return list<SignalDTO> Frames held back, in the order they were queued
     */
    private function exchangeHolding(callable $hold): array
    {
        $held = [];
        $delivered = 0;
        $frames = $this->operationFrames();
        while (($frame = array_shift($frames)) !== null) {
            self::assertLessThan(100, ++$delivered, 'Operation frames must settle without a retry loop.');
            if ($hold($frame)) {
                $held[] = $frame;
            } else {
                $this->deliverOperationFrame($frame);
            }
            $frames = [...$this->operationFrames(), ...$frames];
        }

        return $held;
    }

    /**
     * @param string $userId Person id as the agent address carries it
     * @return AbstractUserAgent Agent with the final's rights confined to this fixture
     */
    protected function newPersonAgent(string $userId): AbstractUserAgent
    {
        return new ErasureRecoveryTestUserAgent($userId);
    }

    /**
     * @param int $root Root whose standing request the operation answers
     * @param bool $expireGrace Whether the opening saves a forced start intention
     * @return AccountOperation Newly opened erasure, not yet entered
     */
    private function openErasure(int $root, bool $expireGrace = false): AccountOperation
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
        $operation = $this->asHolder(
            static fn () => AccountErasureOpening::open($request, AccountErasureParticipantCatalog::framework(), $expireGrace)->operation,
        );
        self::assertNotNull($operation);

        return $operation;
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
     * @param list<int> $ids Ids in any order
     * @return list<int> The same ids, ascending
     */
    private static function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    /** @return int Id of a new person with a project note, before any ORM reader sees it */
    private static function seedPerson(): int
    {
        Database::sql("INSERT INTO `hilos_user` (`name`, `admin`) VALUES ('Recovery person', 0)");
        $userId = Database::lastInsertId();
        Database::sql('INSERT INTO `hilos_fw_test_recovery_note` (`user_id`) VALUES (?)', [$userId]);

        return $userId;
    }

    /** @param int $userId Person whose standing code puts the users library into the erasure */
    private static function seedVerification(int $userId): void
    {
        Database::sql(
            'INSERT INTO `hilos_user_verification` (`user_id`, `type`, `identifier`, `expires_at`, `created_at`) '
                . 'VALUES (?, ?, ?, 2082758400000, 1790416800000)',
            [$userId, VerificationType::ACCOUNT_DELETION, "recovery-{$userId}@example.test"],
        );
    }

    /** @param int $userId Person whose data copy puts the data export into the erasure */
    private static function seedDataExport(int $userId): void
    {
        Database::sql(
            'INSERT INTO `hilos_data_export` (`user_id`, `state`, `requested_at`) VALUES (?, ?, 1790416800000)',
            [$userId, 'preparing'],
        );
    }

    /**
     * @param int $userId Recipient whose mail delivery puts the mail shard into the erasure
     * @return int Id of the pending delivery on the one mail shard
     */
    private static function seedDelivery(int $userId): int
    {
        Database::sql(
            'INSERT INTO `hilos_notification` (`user_id`, `type`, `title`, `created_at`) VALUES (?, ?, ?, 1790416800000)',
            [$userId, 'test.erasure-recovery', 'Erasure recovery notification'],
        );
        Database::sql(
            'INSERT INTO `hilos_notification_delivery` (`notification_id`, `channel`, `routing_key`, `created_at`, `updated_at`)'
                . ' VALUES (?, ?, 0, 1790416800000, 1790416800000)',
            [Database::lastInsertId(), MailDeliveryChannel::NAME],
        );

        return Database::lastInsertId();
    }

    /** @return list<int> Stored delivery ids, independently of the ORM */
    private static function deliveryIds(): array
    {
        return array_map(
            static fn (array $row): int => (int)$row['id'],
            Database::sql('SELECT `id` FROM `hilos_notification_delivery` ORDER BY `id`')->rows(),
        );
    }

    /**
     * @param int $userId Person a new browser is trusted for
     * @param int $grantedAt Moment of the grant (epoch milliseconds)
     * @param int $until Moment the trust runs out (epoch milliseconds)
     */
    private static function seedTrust(int $userId, int $grantedAt, int $until): void
    {
        $token = sprintf('%028d1951', $userId);
        self::seedSession($token, $userId, $grantedAt, null);
        Database::sql(
            'INSERT INTO `hilos_second_factor_trust` (`session_id`, `user_id`, `trusted_until`, `created_at`, `granted_at`)'
                . ' VALUES ((SELECT `id` FROM `hilos_session` WHERE `token` = ?), ?, ?, ?, ?)',
            [$token, $userId, $until, $grantedAt, $grantedAt],
        );
    }

    /**
     * @param int $userId Person whose trusts are read
     * @return list<int> End of every stored trust of the person, by id
     */
    private static function trustLimits(int $userId): array
    {
        return array_map(
            static fn (array $row): int => (int)$row['trusted_until'],
            Database::sql('SELECT `trusted_until` FROM `hilos_second_factor_trust` WHERE `user_id` = ? ORDER BY `id`', [$userId])->rows(),
        );
    }

    /**
     * @param AccountOperation $operation Erasure whose persisted terminal state is checked independently
     * @return array<string, mixed> Persisted stage, outcome and finish time
     */
    private static function operationRow(AccountOperation $operation): array
    {
        return Database::sql(
            'SELECT `stage`, `outcome`, `finished_at` FROM `hilos_account_operation` WHERE `id` = ?',
            [$operation->id],
        )->row();
    }

    /**
     * @param string $table Operation table read independently of the ORM
     * @return list<array<string, mixed>> Every stored row, in primary key order
     */
    private static function rows(string $table): array
    {
        return Database::sql("SELECT * FROM `{$table}` ORDER BY `id`")->rows();
    }

    /**
     * @param int $assignmentId Duty whose saved result is read
     * @return list<array<string, mixed>> Its stored result rows, independently of the ORM
     */
    private static function resultRowsOf(int $assignmentId): array
    {
        return Database::sql('SELECT * FROM `hilos_account_operation_result` WHERE `assignment_id` = ?', [$assignmentId])->rows();
    }

    /** @return list<list<array<string, mixed>>> Operations, assignments and results, each in key order */
    private static function operationTables(): array
    {
        return [
            self::rows('hilos_account_operation'),
            self::rows('hilos_account_operation_assignment'),
            self::rows('hilos_account_operation_result'),
        ];
    }

    /** @return string Every line the agents logged, whatever stream file it went to */
    private function agentLog(): string
    {
        $log = '';
        foreach (array_diff((array)scandir($this->logDirectory), ['.', '..']) as $entry) {
            $log .= (string)file_get_contents($this->logDirectory . '/' . $entry);
        }

        return $log;
    }

    /** Raises the journal and mounts its collections, so a pass opens a receipt that can be read. */
    private function raiseJournal(): void
    {
        $primary = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::configure(
            ChangeLogDatabase::CONNECTION_INDEX,
            $primary->host,
            $primary->user,
            $primary->password,
            ChangeLogDatabase::configuredName(),
            $primary->port,
            $primary->charset,
        );
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        $this->journalRaised = true;
        foreach (self::JOURNAL_TABLES as $table) {
            Database::sqlRun("DROP TABLE IF EXISTS {$journal}.`{$table}`");
        }
        Database::sqlRun(str_replace(
            JournalTriggerRenderer::DATABASE_TOKEN,
            $journal,
            (string)file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_hilos_change_log.sql'),
        ));
        Schema::reset();
        Schema::initialize();
        ErasureRecoveryJournalTestHilos::initBrowser();
        Hilos::$db = new ErasureRecoveryContext();
        Hilos::$db->configure();
    }

    /** @return list<array<string, mixed>> Stored receipts of the recovery rule, oldest first */
    private static function recoveryReceipts(): array
    {
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());

        return Database::sql(
            "SELECT `id`, `agent`, `source` FROM {$journal}.`hilos_change_log_receipt` WHERE `action` = ? ORDER BY `id`",
            ['hilos_account_erasure_recovery'],
        )->rows();
    }

    /**
     * @param int $id Stored receipt
     * @return array<string, mixed> Its attribution
     */
    private static function receipt(int $id): array
    {
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());

        return Database::sql(
            "SELECT `channel`, `action`, `agent`, `source` FROM {$journal}.`hilos_change_log_receipt` WHERE `id` = ?",
            [$id],
        )->rows()[0];
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
final class ErasureRecoveryTestUsersLibrary extends AbstractUsersLibraryAgent
{
}

/** The production holder through its real start and tick; a case can refuse the entry of one operation. */
final class ErasureRecoveryTestHolder extends AbstractSessionsLibraryAgent
{
    /** Operation whose entry fails, to show that the pass goes on past it. */
    public ?int $failingOperationId = null;
    /** @var list<int> Actual driver entries, independently of the number of queued duties */
    public array $entered = [];

    /** @param AccountOperation $operation Open erasure the recovery pass enters */
    protected function driveAccountErasure(AccountOperation $operation): void
    {
        $this->entered[] = $operation->id;
        if ($operation->id === $this->failingOperationId) {
            throw new ValidationException('Private fixture payload');
        }
        parent::driveAccountErasure($operation);
    }

    /** @inheritDoc */
    protected function driveAccountMerge(AccountOperation $operation): void
    {
        $this->entered[] = $operation->id;
        parent::driveAccountMerge($operation);
    }

    /**
     * @param int $survivor Survivor without project notes
     * @param int $loser Loser without project notes
     * @return AccountOperation Real prepared merge, kept at its personal preparations
     */
    public function openMerge(int $survivor, int $loser): AccountOperation
    {
        return $this->openAccountMerge($survivor, $loser, new AccountMergeChoice(null, null, null, null));
    }
}

/** The data-export owner of the real exchange. */
final class ErasureRecoveryTestDataExport extends AbstractDataExportAgent
{
}

/** The legal-export owner of the real exchange. */
final class ErasureRecoveryTestLegal extends AbstractHilosLegalAgent
{
}

/** The final uses the production personal writer's rights. */
final class ErasureRecoveryTestUserAgent extends AbstractUserAgent
{
}

/** A project with a sign-in surface, so the holder arms its recovery pass, and every erasure participant. */
abstract class ErasureRecoveryTestHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::AUTH];
    protected const string ACCOUNT_DATA_OWNER_DIRECTORY = ErasureRecoveryProjectDirectory::class;
    protected const string NOTIFICATION_CHANNEL_REGISTRY = ErasureRecoveryChannels::class;

    public const array AGENTS = [
        HilosAgentType::HILOS_USER => [AgentRegistryKey::WORKER => ErasureRecoveryTestUserAgent::class, AgentRegistryKey::INDEXED => true],
        HilosAgentType::HILOS_USERS_LIBRARY => [AgentRegistryKey::WORKER => ErasureRecoveryTestUsersLibrary::class],
        HilosAgentType::HILOS_SESSIONS_LIBRARY => [AgentRegistryKey::WORKER => ErasureRecoveryTestHolder::class],
        HilosAgentType::HILOS_DATA_EXPORT => [AgentRegistryKey::WORKER => ErasureRecoveryTestDataExport::class],
        HilosAgentType::HILOS_LEGAL => [AgentRegistryKey::WORKER => ErasureRecoveryTestLegal::class],
        HilosAgentType::HILOS_MAIL => [AgentRegistryKey::WORKER => MailDeliveryChannelAgent::class, AgentRegistryKey::INDEXED => true],
        ErasureRecoveryProjectOwner::AGENT_TYPE => [AgentRegistryKey::WORKER => ErasureRecoveryProjectOwner::class],
    ];
}

/** The same project keeping a change log, so a pass's receipt is stored where a case can read it. */
abstract class ErasureRecoveryJournalTestHilos extends ErasureRecoveryTestHilos
{
    protected const array FEATURES = [HilosFeature::AUTH, HilosFeature::CHANGE_LOG];
}

/** Notes the receipt each announced write runs under; the receipt's own bookkeeping runs outside it. */
final class ErasureRecoveryReceiptRecorder implements SourceChangeSubscriberInterface
{
    /** @var array<string, list<?int>> Receipt open at each announced write, by source and in write order */
    public array $receiptIds = [];

    /**
     * @param SourceChange $change Announced write
     * @param SourceChangeProvenance $provenance Whether this process authored the write
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($change->sourceKey !== HilosDbContext::changeLogReceipts) {
            $this->receiptIds[$change->sourceKey][] = JournalReceiptScope::currentId();
        }
    }
}

/** The project participant owns the fixture's mounted note collection. */
abstract class ErasureRecoveryProjectDirectory extends AccountDataOwnerDirectory
{
    public const string KEY = 'recovery_project';

    /** @return list<AccountDataOwner> Project note owner */
    protected static function owners(): array
    {
        return [new AccountDataOwner(
            self::KEY, [ErasureRecoveryNotes::COLLECTION_KEY], ErasureRecoveryProjectOwner::AGENT_TYPE, false,
            static fn (int $userId): bool => Hilos::$db->getObjectCollection(ErasureRecoveryNotes::COLLECTION_KEY)
                ->countByColumn(ErasureRecoveryNote::user_id, $userId) > 0,
        )];
    }
}

/** The project uses the same participant step and answer translation as a real project owner. */
final class ErasureRecoveryProjectOwner extends AbstractAgent implements AccountErasureProjectOwner
{
    public const string AGENT_TYPE = 'recovery_project';
    public const string ACCOUNT_OPERATION_SIGNAL = 'recovery_project_account_operation_step';
    public const array AGENT_SIGNALS = [self::ACCOUNT_OPERATION_SIGNAL => AccountOperationAskSignalData::class];
    public const array OWNS_DB = [ErasureRecoveryNotes::COLLECTION_KEY => [TruthSourceOperation::Remove]];
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
        $notes = Hilos::$db->getObjectCollection(ErasureRecoveryNotes::COLLECTION_KEY);
        $ids = iterator_to_array($notes->loadByColumn(ErasureRecoveryNote::user_id, $part->userId), false);
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
final class ErasureRecoveryMailChannel extends MailDeliveryChannel
{
    /** @return int The fixture's one mail shard */
    public function poolSize(): int
    {
        return 1;
    }
}

/** The exchange activates mail without a sender, a transport or network I/O. */
abstract class ErasureRecoveryChannels extends DeliveryChannelRegistry
{
    /** @return array<string, MailDeliveryChannel> The real mail channel's deterministic shard descriptor */
    protected static function channels(): array
    {
        return [MailDeliveryChannel::NAME => new ErasureRecoveryMailChannel()];
    }
}

/** Framework collections plus the project's one account-bound note. */
final class ErasureRecoveryContext extends HilosDbContext
{
    /** Mounts the framework and the test project's note collection. */
    public function configure(): void
    {
        parent::configure();
        $this->mountObjectCollection(ErasureRecoveryNotes::class, Objects::LAZY_STRATEGY_KEY);
    }
}

/** The project fixture's only persisted field is also its primary account key. */
final class ErasureRecoveryNote extends Entity
{
    public const string user_id = 'user_id';
    public const string _table = 'hilos_fw_test_recovery_note';
    public const string _primary = self::user_id;
    public const array _columns = [self::user_id];
    public const array _types = [self::user_id => PhpType::INTEGER->value];

    public int $user_id;
}

/** @extends Object_<ErasureRecoveryNote> */
final class ErasureRecoveryNoteObject extends Object_
{
    public const string ENTITY_CLASS = ErasureRecoveryNote::class;
}

/** @extends Objects<ErasureRecoveryNoteObject> */
final class ErasureRecoveryNotes extends Objects
{
    public const string COLLECTION_KEY = 'recoveryProjectRows';
    public const string OBJECT_CLASS = ErasureRecoveryNoteObject::class;
}

/** The legal handler's after-commit sweep and a starting data export read isolated temporary directories. */
final class ErasureRecoveryFs extends FsContext
{
    /** @param string $path Isolated legal-export directory; the data-export one stands beside it */
    public function __construct(private readonly string $path)
    {
    }

    /** Registers the real legal-export and data-export directory contracts, one beside the other. */
    public function configure(): void
    {
        $this->registerDirectory(self::LEGAL_EXPORT, $this->path, DirectoryScope::CLUSTER);
        $this->registerDirectory(self::DATA_EXPORT, $this->path . '-data', DirectoryScope::CLUSTER);
    }
}

/** Runtime holding the fixture's session-stage connection roster, empty. */
final class ErasureRecoveryRtContext extends RtContext
{
    public function configure(): void
    {
        $this->_stateCollections[ErasureRecoveryConnections::RT_COLLECTION] = ErasureRecoveryConnections::init();
    }
}

/** Session-stage connection collection of the fixture. */
final class ErasureRecoveryConnections extends HilosSessionConnections
{
    public const string RT_COLLECTION = 'erasureRecoveryConnections';
    public const string STATE_CLASS = ErasureRecoveryConnection::class;
}

/** Session-stage connection row adding no project fields. */
final class ErasureRecoveryConnection extends HilosSessionConnection
{
    /**
     * @return Generator<int, RtWork, int, void> No project fields
     */
    protected function initOwn(): Generator
    {
        yield new RtWork();
    }

    /**
     * @param RtRowReader $row Serialized runtime row
     * @return Generator<int, RtWork, int, void> No project fields
     */
    protected function hydrateOwn(RtRowReader $row): Generator
    {
        yield new RtWork();
    }

    /**
     * @param RtRowWriter $out Segmented destination after both base stages
     * @return Generator<int, RtWork, int, void> No project fields
     */
    protected function exportOwn(RtRowWriter $out): Generator
    {
        yield new RtWork();
    }

    /**
     * @param RtRowReader $diff Incoming field changes
     * @return Generator<int, RtWork, int, void> No project fields
     */
    protected function applyOwnDiff(RtRowReader $diff): Generator
    {
        yield new RtWork();
    }
}
