<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\AccessLog\AccessLogEvent;
use Hilos\Auth\AccountDeletion\AccountDeletionCommandConstants;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\SecondFactor\Base32;
use Hilos\Auth\SecondFactor\SecondFactorPendingMode;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncSignalDataInterface;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\UserVerification as EntityUserVerification;
use Hilos\Database\Exception\ObjectCollectionNotFoundException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Collection\UserVerifications as ObjectUserVerifications;
use Hilos\Database\Verification\VerificationType;
use Hilos\Files\DTO\FileRemoveSignalData;
use Hilos\Files\HilosFiles;
use Hilos\Files\Storage\LocalFilesStorage;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Notification\HilosNotifier;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt as StateHilosCodeSendAttempt;
use Hilos\Runtime\State\Item\HilosOAuthTrip as StateHilosOAuthTrip;
use Hilos\Runtime\State\Item\HilosProfileFlow as StateHilosProfileFlow;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\Runtime\State\Item\HilosSessionToastStack as StateHilosSessionToastStack;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\State\Item\RecoveryWaiter as StateRecoveryWaiter;
use Hilos\Runtime\State\Item\RegistrationWaiter as StateRegistrationWaiter;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\AccountErasure;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Utils\Helpers\TimeHelper;
use ReflectionProperty;

/**
 * The erasure of an account whose deletion fell due, run by the session holder (HIL-302).
 *
 * One transaction: the request is marked carried out, the framework's rows of the person go,
 * the project's seam deletes its own, and a failure anywhere rolls all of it back. After the
 * commit the export agents remove files of their deleted orders. A neighbour with the same
 * rows in every table proves that the erasure cut by the person and nothing wider.
 */
final class AccountErasureIntegrationTest extends HilosSessionIntegrationTestCase
{
    public const int USER_ID = 302;
    public const int NEIGHBOUR_ID = 303;
    private const int FOLDED_ID = 304;
    private const int DEEPEST_ID = 305;
    private const int PHOTO_FILE_ID = 9001;

    private const string CREATED_AT = '2026-09-26 10:00:00';
    private const string PAST = '2026-01-01 00:00:00';
    private const string FUTURE = '2036-01-01 00:00:00';

    /** Session signed in as the person. */
    private const string SIGNED_IN_TOKEN = 'aa0000000000000000000000000000302';

    /** Session where the person, an administrator, works in the neighbour's account. */
    private const string TAKEOVER_TOKEN = 'bb0000000000000000000000000000302';

    /** Session whose proven sign-in waits on the person's second factor. */
    private const string WAITING_TOKEN = 'cc0000000000000000000000000000302';

    /** Session signed in as the neighbour. */
    private const string NEIGHBOUR_TOKEN = 'dd0000000000000000000000000000302';
    private const string BLOCKED_TOKEN = 'dd0000000000000000000000000000303';
    private const string FOLDED_TOKEN = 'ee0000000000000000000000000000304';
    private const string DEEPEST_TOKEN = 'ff0000000000000000000000000000305';

    /** The tables the erasure cuts by the person, each counted by its user id column. */
    private const array ERASED_TABLES = [
        'hilos_identity',
        'hilos_passkey_credential',
        'hilos_user_verification',
        'hilos_second_factor',
        'hilos_second_factor_backup_code',
        'hilos_second_factor_reset',
        'hilos_second_factor_setting',
        'hilos_second_factor_trust',
        'hilos_step_up',
        'hilos_legal_acceptance',
        'hilos_access_log',
        'hilos_notification',
        'hilos_notification_preference',
        'hilos_push_subscription',
        'hilos_data_export',
        'hilos_legal_acceptance_export',
    ];

    private string $boundAppClass;

    private ?SignalRouter $previousSignalRouter = null;

    private ?RtContext $previousRt = null;

    private ?HilosNotifier $previousNotify = null;

    /**
     * @throws DatabaseException When a stub statement or the schema reset fails
     * @throws HilosException When the runtime context cannot be built
     */
    protected function setUp(): void
    {
        parent::setUp();
        self::runExtraStubs(down: true);
        self::runExtraStubs(down: false);
        Schema::reset();
        Schema::initialize();

        $this->boundAppClass = Hilos::appClass();
        $this->previousSignalRouter = Hilos::$sr;
        $this->previousRt = Hilos::$rt;
        $this->previousNotify = Hilos::$notify;
        self::bindAppClass(AccountErasureTestHilos::class);
        Hilos::$sr = new SignalRouter();
        Hilos::$notify = new HilosNotifier();
        $rt = new AccountErasureTestRtContext();
        $rt->mountFeatureRuntime([new AuthFeature()]);
        $rt->configure();
        $rt->bindStateCollectionNames();
        Hilos::$rt = $rt;
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        foreach (self::daemonCollections() as $collection) {
            RtTruthSourceRegistry::registerDaemon($collection);
        }
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        foreach (self::daemonCollections() as $collection) {
            RtTruthSourceRegistry::unregisterDaemon($collection);
        }
        SourceChangeBus::reset();
        Hilos::$notify = $this->previousNotify;
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousSignalRouter;
        self::bindAppClass($this->boundAppClass);
        self::runExtraStubs(down: true);
        Schema::reset();

        parent::tearDown();
    }

    /**
     * A due request erases the person and nobody else, and signs them out everywhere.
     *
     * @throws HilosException When seeding or the sweep fails
     */
    public function testADueRequestErasesThePersonAndLeavesTheNeighbour(): void
    {
        $this->seedPerson(self::USER_ID, self::SIGNED_IN_TOKEN);
        $this->seedPerson(self::NEIGHBOUR_ID, self::NEIGHBOUR_TOKEN);
        self::seedSession(self::TAKEOVER_TOKEN, self::NEIGHBOUR_ID, self::CREATED_AT, null, self::USER_ID);
        self::seedSession(self::WAITING_TOKEN, null, self::CREATED_AT, null);
        Database::sqlRun(
            'UPDATE `hilos_session` SET `pending_second_factor_user_id` = ?, `pending_second_factor_mode` = ?,'
            . ' `pending_second_factor_until` = ? WHERE `token` = ?',
            [self::USER_ID, SecondFactorPendingMode::VERIFY, self::FUTURE, self::WAITING_TOKEN],
        );
        self::seedSession(self::BLOCKED_TOKEN, null, self::CREATED_AT, null);
        Database::sqlRun(
            'UPDATE `hilos_session` SET `blocked_user_id` = ?, `blocked_signed_in` = 1 WHERE `token` = ?',
            [self::USER_ID, self::BLOCKED_TOKEN],
        );
        $signedInId = Hilos::$db->sessions->findByToken(self::SIGNED_IN_TOKEN)->id;
        $takeoverId = Hilos::$db->sessions->findByToken(self::TAKEOVER_TOKEN)->id;
        $request = Hilos::$db->accountDeletions->actions->request(self::USER_ID, self::PAST);
        $requestId = (int)$request->id;
        self::assertSame(1, self::deliveriesOf(self::USER_ID));
        self::assertSame(1, self::deliveriesOf(self::NEIGHBOUR_ID));
        self::assertSame(2, self::deliveryCount());

        $agent = $this->runSweep();

        self::assertSame([self::USER_ID], $agent->erased);
        foreach (self::ERASED_TABLES as $table) {
            self::assertSame(0, self::rowsOf($table, self::USER_ID), "{$table} keeps nothing of the person");
            self::assertSame(1, self::rowsOf($table, self::NEIGHBOUR_ID), "{$table} keeps the neighbour");
        }
        $row = self::requestRow($requestId);
        self::assertNotNull($row['completed_at'], 'The request stays behind, carried out');
        self::assertNull($row['canceled_at']);

        self::assertNull(self::sessionRow(self::SIGNED_IN_TOKEN));
        self::assertNull(self::sessionRow(self::TAKEOVER_TOKEN));
        self::assertNotNull(Hilos::$db->sessions[$signedInId], 'The session stays, as a guest');
        self::assertNull(Hilos::$db->sessions[$signedInId]->userId);
        self::assertNotNull(Hilos::$db->sessions[$takeoverId]);
        self::assertNull(Hilos::$db->sessions[$takeoverId]->userId, 'The takeover the person ran is ended');
        self::assertNull(Hilos::$db->sessions[$takeoverId]->impersonatorUserId);
        self::assertNull(self::pendingSecondFactorOf(self::WAITING_TOKEN), 'The sign-in waiting on their factor is let go');
        Database::sql('SELECT `blocked_user_id`, `blocked_signed_in` FROM `hilos_session` WHERE `token` = ?', [self::BLOCKED_TOKEN]);
        $blocked = Database::row();
        self::assertNull($blocked['blocked_user_id'] ?? null, 'The closed-access card no longer names the erased person');
        self::assertSame(0, (int)($blocked['blocked_signed_in'] ?? -1));
        self::assertSame(self::NEIGHBOUR_ID, self::userOf(self::NEIGHBOUR_TOKEN));
        self::assertSame(0, self::deliveriesOf(self::USER_ID));
        self::assertSame(1, self::deliveriesOf(self::NEIGHBOUR_ID));
        self::assertSame(1, self::deliveryCount(), 'The erased notification leaves no orphan delivery');
    }

    /**
     * A project may mount the collections without activating every notification/export table.
     *
     * @throws HilosException When seeding or the sweep fails
     */
    public function testErasureSkipsTablesTheProjectDidNotActivate(): void
    {
        Database::sqlRun('DROP TABLE `hilos_notification_delivery`');
        Database::sqlRun('DROP TABLE `hilos_push_subscription`');
        Database::sqlRun('DROP TABLE `hilos_legal_acceptance_export`');
        Schema::reset();
        Schema::initialize();
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, 'Person')", [self::USER_ID]);
        Hilos::$db->accountDeletions->actions->request(self::USER_ID, self::PAST);

        $agent = $this->runSweep();

        self::assertSame([self::USER_ID], $agent->erased);
        self::assertFalse(self::personExists(self::USER_ID));
    }

    /**
     * A new dependent row written after the cleanup makes the person key roll everything back.
     *
     * @throws HilosException When seeding or the sweep fails
     */
    public function testAReferenceInsertedAfterCleanupRollsTheErasureBack(): void
    {
        $this->seedPerson(self::USER_ID, self::SIGNED_IN_TOKEN);
        $request = Hilos::$db->accountDeletions->actions->request(self::USER_ID, self::PAST);
        $agent = new AccountErasureTestAgent();
        $agent->insertLateNotificationFor = self::USER_ID;
        $agent->onStart();
        $agent->onTick();

        self::assertTrue(self::personExists(self::USER_ID));
        self::assertNull(self::requestRow((int)$request->id)['completed_at']);
        self::assertSame(1, self::rowsOf('hilos_notification', self::USER_ID));
        self::assertSame(self::USER_ID, self::userOf(self::SIGNED_IN_TOKEN));
    }

    /**
     * Anonymous codes on the person's current email and phone leave with the account.
     * A neighbour's address keeps its own code, even when that code also has no user id.
     *
     * @throws HilosException When seeding or erasure fails
     */
    public function testErasureRemovesAnonymousCodesOnCurrentAddresses(): void
    {
        $this->seedPerson(self::USER_ID, self::SIGNED_IN_TOKEN);
        $this->seedPerson(self::NEIGHBOUR_ID, self::NEIGHBOUR_TOKEN);
        $email = 'erase-me@example.test';
        $phone = '+15550000302';
        $neighbourEmail = 'keep-me@example.test';
        self::seedIdentity(self::USER_ID, IdentityType::PASSWORD, $email);
        self::seedIdentity(self::USER_ID, IdentityType::SMS, $phone);
        self::seedIdentity(self::NEIGHBOUR_ID, IdentityType::PASSWORD, $neighbourEmail);
        $verifications = Hilos::$db?->getObjectCollection(HilosDbContext::verifications);
        self::assertInstanceOf(ObjectUserVerifications::class, $verifications);
        $verifications->createChallenge(VerificationType::REGISTER_CONFIRM, $email, null, '111111', 3600);
        $verifications->createChallenge(VerificationType::SMS_LOGIN, $phone, null, '222222', 3600);
        $verifications->createChallenge(VerificationType::REGISTER_CONFIRM, $neighbourEmail, null, '333333', 3600);
        Hilos::$db->accountDeletions->actions->request(self::USER_ID, self::PAST);

        $this->runSweep();

        self::assertSame(0, EntityUserVerification::count([EntityUserVerification::identifier => $email]));
        self::assertSame(0, EntityUserVerification::count([EntityUserVerification::identifier => $phone]));
        self::assertSame(1, EntityUserVerification::count([EntityUserVerification::identifier => $neighbourEmail]));
    }

    /**
     * An account folded into another one is erased too - its deletion was asked for before the
     * merge - and its merge row goes before the project deletes the person's row, which the row
     * would otherwise hold (HIL-1199).
     *
     * @throws HilosException When seeding or the sweep fails
     */
    public function testErasingAFoldedAccountTakesItsMergeRow(): void
    {
        self::seedPersonRows();
        $this->seedPerson(self::USER_ID, self::SIGNED_IN_TOKEN);
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, 'Deepest')", [self::DEEPEST_ID]);
        $this->seedPerson(self::DEEPEST_ID, self::DEEPEST_TOKEN);
        self::seedMerge(self::USER_ID, self::NEIGHBOUR_ID);
        self::seedMerge(self::DEEPEST_ID, self::USER_ID);
        Hilos::$db->accountDeletions->actions->request(self::USER_ID, self::PAST);

        $agent = $this->runSweep();

        self::assertSame([self::DEEPEST_ID, self::USER_ID], $agent->erased);
        self::assertSame(0, self::rowsOf('hilos_user_merge', self::USER_ID));
        self::assertSame(0, self::rowsOf('hilos_user_merge', self::DEEPEST_ID));
        self::assertFalse(self::personExists(self::USER_ID));
        self::assertFalse(self::personExists(self::DEEPEST_ID));
        self::assertTrue(self::personExists(self::NEIGHBOUR_ID));
    }

    /**
     * The project sees the rename journal before the framework removes it and the person row.
     *
     * @throws HilosException When seeding or the sweep fails
     */
    public function testTheHookSeesThePersonRowAndJournalThatTheFrameworkDeletesAfterIt(): void
    {
        self::seedPersonRows();
        Database::sqlRun(
            'INSERT INTO `hilos_user_rename` '
            . '(`user_id`, `renamed_by_user_id`, `old_name`, `new_name`, `renamed_at`) VALUES (?, ?, ?, ?, ?), (?, ?, ?, ?, ?)',
            [self::USER_ID, self::USER_ID, 'Before', 'After', self::CREATED_AT,
                self::NEIGHBOUR_ID, self::USER_ID, 'Old', 'New', self::CREATED_AT],
        );
        Hilos::$db->accountDeletions->actions->request(self::USER_ID, self::PAST);

        $agent = $this->runSweep();

        self::assertSame([[self::USER_ID, true, 1]], $agent->seenAtHook);
        self::assertFalse(self::personExists(self::USER_ID));
        self::assertSame(0, self::rowsOf('hilos_user_rename', self::USER_ID));
        self::assertSame(1, self::rowsOf('hilos_user_rename', self::NEIGHBOUR_ID));
        Database::sql('SELECT `renamed_by_user_id` FROM `hilos_user_rename` WHERE `user_id` = ?', [self::NEIGHBOUR_ID]);
        self::assertNull(Database::row()['renamed_by_user_id'] ?? null);
    }

    /**
     * Erasing a survivor takes the entire chain of accounts folded into it, leaves first.
     *
     * @throws HilosException When seeding or the sweep fails
     */
    public function testErasingTheSurvivorErasesTheAccountsFoldedIntoIt(): void
    {
        self::seedPersonRows();
        $this->seedPerson(self::USER_ID, self::SIGNED_IN_TOKEN);
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, 'Folded'), (?, 'Deepest')", [self::FOLDED_ID, self::DEEPEST_ID]);
        $this->seedPerson(self::FOLDED_ID, self::FOLDED_TOKEN);
        $this->seedPerson(self::DEEPEST_ID, self::DEEPEST_TOKEN);
        self::seedMerge(self::FOLDED_ID, self::USER_ID);
        self::seedMerge(self::DEEPEST_ID, self::FOLDED_ID);
        Hilos::$db->accountDeletions->actions->request(self::USER_ID, self::PAST);
        $foldedRequest = Hilos::$db->accountDeletions->actions->request(self::FOLDED_ID, self::FUTURE);

        $agent = $this->runSweep();

        self::assertSame([self::DEEPEST_ID, self::FOLDED_ID, self::USER_ID], $agent->erased);
        foreach ([self::DEEPEST_ID, self::FOLDED_ID, self::USER_ID] as $erasedId) {
            self::assertFalse(self::personExists($erasedId));
            self::assertSame(0, self::rowsOf('hilos_user_merge', $erasedId));
            foreach (self::ERASED_TABLES as $table) {
                self::assertSame(0, self::rowsOf($table, $erasedId), "{$table} keeps no row of {$erasedId}");
            }
        }
        self::assertNotNull(self::requestRow((int)$foldedRequest->id)['completed_at']);
        self::assertTrue(self::personExists(self::NEIGHBOUR_ID));
    }

    /**
     * After the erasure commits, the agent of every erased person asks to stop and a neighbour's does not.
     *
     * @throws HilosException When seeding or the sweep fails
     */
    public function testErasureStopsTheAgentsOfEveryoneItErases(): void
    {
        self::seedPersonRows();
        $this->seedPerson(self::USER_ID, self::SIGNED_IN_TOKEN);
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, 'Folded'), (?, 'Deepest')", [self::FOLDED_ID, self::DEEPEST_ID]);
        $this->seedPerson(self::FOLDED_ID, self::FOLDED_TOKEN);
        $this->seedPerson(self::DEEPEST_ID, self::DEEPEST_TOKEN);
        self::seedMerge(self::FOLDED_ID, self::USER_ID);
        self::seedMerge(self::DEEPEST_ID, self::FOLDED_ID);
        Hilos::$db->accountDeletions->actions->request(self::USER_ID, self::PAST);
        $agents = [];
        foreach ([self::DEEPEST_ID, self::FOLDED_ID, self::USER_ID, self::NEIGHBOUR_ID] as $userId) {
            $agents[$userId] = new AccountErasureStopTestAgent((string)$userId);
        }
        $this->drainQueue();

        $this->runSweep();
        $this->dispatchDbSyncTo(array_values($agents), $this->drainQueue());

        foreach ([self::DEEPEST_ID, self::FOLDED_ID, self::USER_ID] as $erasedId) {
            self::assertTrue($agents[$erasedId]->shouldStop(), "The agent of {$erasedId} did not stop");
        }
        self::assertFalse($agents[self::NEIGHBOUR_ID]->shouldStop());
    }

    /**
     * A rolled-back erasure announces nothing, so nobody's agent asks to stop.
     *
     * @throws HilosException When seeding or the sweep fails
     */
    public function testARolledBackErasureStopsNobody(): void
    {
        $this->seedPerson(self::USER_ID, self::SIGNED_IN_TOKEN);
        Hilos::$db->accountDeletions->actions->request(self::USER_ID, self::PAST);
        $person = new AccountErasureStopTestAgent((string)self::USER_ID);
        $neighbour = new AccountErasureStopTestAgent((string)self::NEIGHBOUR_ID);
        $this->drainQueue();

        $this->runSweep(failingFor: self::USER_ID);
        $this->dispatchDbSyncTo([$person, $neighbour], $this->drainQueue());

        self::assertFalse($person->shouldStop());
        self::assertFalse($neighbour->shouldStop());
    }

    /**
     * The registry files the project's rows pointed at - of the whole circle, in erasure order -
     * leave for the files library in one remove frame after the commit, rather than being deleted
     * from the files directory past the registry (HIL-144).
     *
     * @throws HilosException When seeding or the sweep fails
     */
    public function testTheErasedRowsFilesLeaveForTheFilesLibraryInOneFrame(): void
    {
        self::bindAppClass(AccountErasureFilesTestHilos::class);
        $previousFiles = Hilos::$files;
        Hilos::$files = new HilosFiles(new LocalFilesStorage());
        try {
            self::seedPersonRows();
            Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, 'Folded')", [self::FOLDED_ID]);
            self::seedMerge(self::FOLDED_ID, self::USER_ID);
            Hilos::$db->accountDeletions->actions->request(self::USER_ID, self::PAST);

            $agent = new AccountErasureTestAgent();
            $agent->fileIdsOf = [self::FOLDED_ID => [41], self::USER_ID => [42, 43]];
            $agent->onStart();
            $agent->onTick();

            self::assertSame([self::FOLDED_ID, self::USER_ID], $agent->erased);
            $removals = [];
            foreach ($this->drainQueue() as $signal) {
                if ($signal->signalName->getName() === HilosSignalConstants::HILOS_FILE_REMOVE) {
                    self::assertInstanceOf(AgentSignalData::class, $signal->data);
                    self::assertInstanceOf(FileRemoveSignalData::class, $signal->data->data);
                    $removals[] = $signal->data->data->fileIds;
                }
            }
            self::assertSame([[41, 42, 43]], $removals);
        } finally {
            Hilos::$files = $previousFiles;
        }
    }

    /** A person's photo row goes before their account; its file id leaves after commit. */
    public function testErasureRemovesThePhotoRowAndAnnouncesItsFile(): void
    {
        self::bindAppClass(AccountErasureFilesTestHilos::class);
        $previousFiles = Hilos::$files;
        Hilos::$files = new HilosFiles(new LocalFilesStorage());
        try {
            self::seedPersonRows();
            Database::sqlRun(
                'INSERT INTO `hilos_file` (`id`, `stored_name`, `filename`, `mime_type`, `size`, `content_hash`, '
                . '`owner_user_id`, `visibility`, `bound`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [self::PHOTO_FILE_ID, 'photo.jpg', 'photo.jpg', 'image/jpeg', 4, str_repeat('a', 64), self::USER_ID, 'public', 1],
            );
            $fileId = self::PHOTO_FILE_ID;
            Database::sqlRun(
                'INSERT INTO `hilos_user_photo` (`user_id`, `file_id`, `set_at`) VALUES (?, ?, ?)',
                [self::USER_ID, $fileId, self::CREATED_AT],
            );
            Hilos::$db->accountDeletions->actions->request(self::USER_ID, self::PAST);

            $agent = $this->runSweep();

            self::assertSame([self::USER_ID], $agent->erased);
            self::assertSame(0, self::rowsOf('hilos_user_photo', self::USER_ID));
            $removals = [];
            foreach ($this->drainQueue() as $signal) {
                if ($signal->signalName->getName() === HilosSignalConstants::HILOS_FILE_REMOVE) {
                    self::assertInstanceOf(AgentSignalData::class, $signal->data);
                    self::assertInstanceOf(FileRemoveSignalData::class, $signal->data->data);
                    $removals[] = $signal->data->data->fileIds;
                }
            }
            self::assertSame([[$fileId]], $removals);
        } finally {
            Hilos::$files = $previousFiles;
        }
    }

    /**
     * A failure of the project's seam rolls every row back, and the request stays due.
     *
     * The rows the framework deleted come back in memory too, by key and as the same instances,
     * with nothing read again (HIL-1165).
     *
     * @throws HilosException When seeding or the sweep fails
     */
    public function testAFailingSeamRollsEverythingBack(): void
    {
        $this->seedPerson(self::USER_ID, self::SIGNED_IN_TOKEN);
        $request = Hilos::$db->accountDeletions->actions->request(self::USER_ID, self::PAST);
        $requestId = (int)$request->id;
        $identities = self::heldIn(HilosDbContext::identities);
        $secondFactors = self::heldIn(HilosDbContext::secondFactors);
        self::assertNotSame([], $identities, 'The seeded way in is held, or the memory is not exercised');
        // The seeds announced their own rows; what is judged below is what the sweep announces.
        $this->drainQueue();

        $this->runSweep(failingFor: self::USER_ID);

        foreach (self::ERASED_TABLES as $table) {
            self::assertSame(1, self::rowsOf($table, self::USER_ID), "{$table} is rolled back");
        }
        self::assertSame($identities, self::heldIn(HilosDbContext::identities), 'The deleted ways in are held again');
        self::assertSame($secondFactors, self::heldIn(HilosDbContext::secondFactors), 'The deleted factor is held again');
        $row = self::requestRow($requestId);
        self::assertNull($row['completed_at'], 'The request is still standing and due');
        self::assertNull($row['canceled_at']);
        self::assertSame(self::USER_ID, self::userOf(self::SIGNED_IN_TOKEN));
        $queued = $this->drainQueue();
        self::assertSame(
            [],
            self::dbFrameTypesAmong($queued),
            'A rolled-back erasure announces none of its deletions to the other processes (HIL-1164)',
        );
    }

    /**
     * A refusal at a folded account undoes the named account's request and every account's rows.
     *
     * @throws HilosException When seeding or the sweep fails
     */
    public function testAFailingSeamOnAFoldedAccountRollsTheWholeErasureBack(): void
    {
        self::seedPersonRows();
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, 'Folded'), (?, 'Deepest')", [self::FOLDED_ID, self::DEEPEST_ID]);
        $this->seedPerson(self::USER_ID, self::SIGNED_IN_TOKEN);
        $this->seedPerson(self::FOLDED_ID, self::FOLDED_TOKEN);
        $this->seedPerson(self::DEEPEST_ID, self::DEEPEST_TOKEN);
        self::seedMerge(self::FOLDED_ID, self::USER_ID);
        self::seedMerge(self::DEEPEST_ID, self::FOLDED_ID);
        $request = Hilos::$db->accountDeletions->actions->request(self::USER_ID, self::PAST);
        $this->drainQueue();

        $agent = $this->runSweep(failingFor: self::FOLDED_ID);

        self::assertSame([self::DEEPEST_ID], $agent->erased);
        foreach ([self::DEEPEST_ID, self::FOLDED_ID, self::USER_ID] as $id) {
            self::assertTrue(self::personExists($id));
            foreach (self::ERASED_TABLES as $table) {
                self::assertSame(1, self::rowsOf($table, $id));
            }
        }
        self::assertNull(self::requestRow((int)$request->id)['completed_at']);
    }

    /**
     * A request called off and one not due yet touch nothing.
     *
     * @throws HilosException When seeding or the sweep fails
     */
    public function testACanceledOrNotYetDueRequestTouchesNothing(): void
    {
        $this->seedPerson(self::USER_ID, self::SIGNED_IN_TOKEN);
        $this->seedPerson(self::NEIGHBOUR_ID, self::NEIGHBOUR_TOKEN);
        Hilos::$db->accountDeletions->actions->request(self::USER_ID, self::PAST)->actions->cancel();
        Hilos::$db->accountDeletions->actions->request(self::NEIGHBOUR_ID, self::FUTURE);

        $agent = $this->runSweep();

        self::assertSame([], $agent->erased);
        foreach (self::ERASED_TABLES as $table) {
            self::assertSame(1, self::rowsOf($table, self::USER_ID), "{$table} keeps the person");
            self::assertSame(1, self::rowsOf($table, self::NEIGHBOUR_ID), "{$table} keeps the neighbour");
        }
    }

    /**
     * The test command ages a future request, erases the person, and reports the project's tally.
     *
     * @throws HilosException When seeding, routing, or erasure fails
     */
    public function testForcePurgeAgesAndErasesAStandingRequest(): void
    {
        $this->seedPerson(self::USER_ID, self::SIGNED_IN_TOKEN);
        $this->seedPerson(self::NEIGHBOUR_ID, self::NEIGHBOUR_TOKEN);
        self::seedPersonRows();
        self::seedMerge(self::NEIGHBOUR_ID, self::USER_ID);
        self::seedSession(self::TAKEOVER_TOKEN, self::NEIGHBOUR_ID, self::CREATED_AT, null, self::USER_ID);
        $signedInId = Hilos::$db->sessions->findByToken(self::SIGNED_IN_TOKEN)->id;
        $takeoverId = Hilos::$db->sessions->findByToken(self::TAKEOVER_TOKEN)->id;
        $request = Hilos::$db->accountDeletions->actions->request(self::USER_ID, self::FUTURE);
        $requestId = (int)$request->id;
        $agent = new AccountErasureTestAgent();
        $agent->onStart();

        $this->sendForcePurgeCommand($agent, self::USER_ID);

        $outcome = $this->consumeForcePurgeOutcome();
        self::assertTrue($outcome->reply->isOk());
        self::assertSame(
            self::USER_ID,
            $outcome->reply->payload[AccountDeletionCommandConstants::FIELD_USER_ID] ?? null,
        );
        self::assertSame(
            ['projectRows' => 2],
            $outcome->reply->payload[AccountDeletionCommandConstants::FIELD_ROWS_ERASED] ?? null,
        );
        self::assertSame([self::NEIGHBOUR_ID, self::USER_ID], $agent->erased);

        foreach (self::ERASED_TABLES as $table) {
            self::assertSame(0, self::rowsOf($table, self::USER_ID), "{$table} keeps nothing of the person");
            self::assertSame(0, self::rowsOf($table, self::NEIGHBOUR_ID), "{$table} erases the folded account");
        }

        $row = self::requestRow($requestId);
        self::assertNotNull($row['completed_at']);
        self::assertLessThanOrEqual($row['completed_at'], $row['effective_at']);
        self::assertNull($row['canceled_at']);
        self::assertNull(self::sessionRow(self::SIGNED_IN_TOKEN));
        self::assertNull(self::sessionRow(self::TAKEOVER_TOKEN));
        self::assertNotNull(Hilos::$db->sessions[$signedInId], 'The session stays, as a guest');
        self::assertNull(Hilos::$db->sessions[$signedInId]->userId);
        self::assertNotNull(Hilos::$db->sessions[$takeoverId]);
        self::assertNull(Hilos::$db->sessions[$takeoverId]->userId, 'The takeover the person ran is ended');
        self::assertNull(Hilos::$db->sessions[$takeoverId]->impersonatorUserId);
        self::assertNull(self::userOf(self::NEIGHBOUR_TOKEN));
    }

    /**
     * A user with no standing request is refused without changing their rows.
     *
     * @throws HilosException When seeding, routing, or a read-back fails
     */
    public function testForcePurgeRefusesAUserWithNoRequest(): void
    {
        $this->seedPerson(self::USER_ID, self::SIGNED_IN_TOKEN);
        $agent = new AccountErasureTestAgent();
        $agent->onStart();

        $this->sendForcePurgeCommand($agent, self::USER_ID);

        $outcome = $this->consumeForcePurgeOutcome();
        self::assertSame("No scheduled deletion for user " . self::USER_ID, self::refusalMessage($outcome->reply));
        self::assertSame(self::USER_ID, self::userOf(self::SIGNED_IN_TOKEN));
        foreach (self::ERASED_TABLES as $table) {
            self::assertSame(1, self::rowsOf($table, self::USER_ID), "{$table} keeps the person");
        }
    }

    /**
     * A canceled request is domain absence and gets the same refusal as no request.
     *
     * @throws HilosException When seeding, routing, or a read-back fails
     */
    public function testForcePurgeRefusesACanceledRequest(): void
    {
        $this->seedPerson(self::USER_ID, self::SIGNED_IN_TOKEN);
        $request = Hilos::$db->accountDeletions->actions->request(self::USER_ID, self::FUTURE);
        $requestId = (int)$request->id;
        $request->actions->cancel();
        $agent = new AccountErasureTestAgent();
        $agent->onStart();

        $this->sendForcePurgeCommand($agent, self::USER_ID);

        $outcome = $this->consumeForcePurgeOutcome();
        self::assertSame("No scheduled deletion for user " . self::USER_ID, self::refusalMessage($outcome->reply));
        self::assertNotNull(self::requestRow($requestId)['canceled_at']);
        self::assertSame(self::USER_ID, self::userOf(self::SIGNED_IN_TOKEN));
    }

    /**
     * A failed project seam leaves the request live and due for the next scheduled sweep.
     *
     * @throws HilosException When seeding, routing, erasure, or a read-back fails
     */
    public function testForcePurgeFailureLeavesTheRequestDueForTheSweep(): void
    {
        $this->seedPerson(self::USER_ID, self::SIGNED_IN_TOKEN);
        $request = Hilos::$db->accountDeletions->actions->request(self::USER_ID, self::FUTURE);
        $requestId = (int)$request->id;
        $agent = new AccountErasureTestAgent();
        $agent->failingFor = self::USER_ID;
        $agent->onStart();

        $this->sendForcePurgeCommand($agent, self::USER_ID);

        $outcome = $this->consumeForcePurgeOutcome();
        self::assertSame('The project refused the erasure', self::refusalMessage($outcome->reply));
        $row = self::requestRow($requestId);
        self::assertLessThanOrEqual(TimeHelper::getSqlDateTime(), $row['effective_at']);
        self::assertNull($row['completed_at']);
        self::assertNull($row['canceled_at']);
        foreach (self::ERASED_TABLES as $table) {
            self::assertSame(1, self::rowsOf($table, self::USER_ID), "{$table} is rolled back");
        }

        $sweepAgent = $this->runSweep();

        self::assertSame([self::USER_ID], $sweepAgent->erased);
        self::assertNotNull(self::requestRow($requestId)['completed_at']);
    }

    public function testSessionHolderDeclaresTheForcePurgeCommand(): void
    {
        self::assertContains(CliCommands::ACCOUNT_TEST_FORCE_PURGE, AccountErasureTestAgent::AGENT_COMMANDS);
    }

    /**
     * Seeds one row of the person in every table the erasure cuts, and a session signed in as them.
     *
     * @param int $userId Person
     * @param string $token Session signed in as the person
     * @throws HilosException When a row cannot be written
     */
    private function seedPerson(int $userId, string $token): void
    {
        Database::sqlRun(
            "INSERT IGNORE INTO `hilos_user` (`id`, `name`) VALUES (?, 'Fixture person')",
            [$userId],
        );
        self::seedSession($token, $userId, self::CREATED_AT, null);
        $sessionId = (int)Hilos::$db->sessions->findByToken($token)?->id;

        Database::sqlRun(
            'INSERT INTO `hilos_legal_acceptance` (`user_id`, `document`, `revision_id`, `accepted_at`) VALUES (?, ?, ?, ?)',
            [$userId, 'terms', '2026-09-17', self::CREATED_AT],
        );
        $identity = Hilos::$db->identities->createPasskeyIdentity($userId, "credential-{$userId}");
        Database::sqlRun(
            'INSERT INTO `hilos_passkey_credential` '
            . '(`identity_id`, `user_id`, `credential_id`, `public_key`, `algorithm`, `user_handle`) '
            . 'VALUES (?, ?, ?, ?, ?, ?)',
            [(int)$identity->id, $userId, "credential-{$userId}", 'unused-public-key', -7, "handle-{$userId}"],
        );
        $verifications = Hilos::$db?->getObjectCollection(HilosDbContext::verifications);
        self::assertInstanceOf(ObjectUserVerifications::class, $verifications);
        $verifications->createChallenge(VerificationType::ACCOUNT_DELETION, "person-{$userId}@example.test", $userId, '302302', 3600);
        Hilos::$db->secondFactors->actions
            ->startEnrolment($userId, 'Phone', Base32::encode('12345678901234567890'))
            ->actions->confirm('Phone');
        Hilos::$db->secondFactorBackupCodes->actions->issueSet($userId, ['abcdefghjk']);
        Hilos::$db->secondFactorResets->actions->request($userId, self::FUTURE, "cancel-{$userId}");
        Hilos::$db->secondFactorSettings->actions->setResetWait($userId, 10, null, null);
        Hilos::$db->secondFactorTrusts->actions->trust($sessionId, $userId, self::FUTURE);
        Hilos::$db->stepUps->actions->confirm(
            ProtectedModeRuntime::hashSessionToken($token),
            $userId,
            StepUpOperationKey::DELETE_ACCOUNT,
            self::FUTURE,
        );
        // Written now rather than at CREATED_AT: a row a year old is the hourly sweep's, not the erasure's.
        Hilos::$db->accessLogEntries->actions->record($userId, AccessLogEvent::SIGN_IN, '203.0.113.7', TimeHelper::getSqlDateTime());
        Database::sqlRun(
            'INSERT INTO `hilos_notification` (`user_id`, `type`, `title`) VALUES (?, ?, ?)',
            [$userId, 'account.test', 'Test notification'],
        );
        Database::sql('SELECT `id` FROM `hilos_notification` WHERE `user_id` = ?', [$userId]);
        $notificationId = (int)(Database::row()['id'] ?? 0);
        Database::sqlRun(
            'INSERT INTO `hilos_notification_delivery` (`notification_id`, `channel`) VALUES (?, ?)',
            [$notificationId, 'email'],
        );
        Database::sqlRun(
            'INSERT INTO `hilos_notification_preference` (`user_id`, `channel`) VALUES (?, ?)',
            [$userId, 'email'],
        );
        Database::sqlRun(
            'INSERT INTO `hilos_push_subscription` (`user_id`, `endpoint`, `p256dh`, `auth`, `endpoint_hash`) '
            . 'VALUES (?, ?, ?, ?, ?)',
            [$userId, "https://push.example.test/{$userId}", 'key', 'secret', hash('sha256', (string)$userId)],
        );
        Database::sqlRun(
            'INSERT INTO `hilos_data_export` (`user_id`, `state`, `requested_at`) VALUES (?, ?, ?)',
            [$userId, 'preparing', self::CREATED_AT],
        );
        Database::sqlRun(
            'INSERT INTO `hilos_legal_acceptance_export` (`user_id`, `state`, `requested_at`) VALUES (?, ?, ?)',
            [$userId, 'preparing', self::CREATED_AT],
        );
    }

    /**
     * Arms and runs the session holder's tick once.
     *
     * @param ?int $failingFor Account whose project seam refuses, or null for no refusal
     * @return AccountErasureTestAgent The holder that ran
     * @throws HilosException When the tick fails
     */
    private function runSweep(?int $failingFor = null): AccountErasureTestAgent
    {
        $agent = new AccountErasureTestAgent();
        $agent->failingFor = $failingFor;
        $agent->onStart();
        $agent->onTick();

        return $agent;
    }

    /**
     * @return list<SignalDTO> Every queued signal, in queue order; the queue is empty afterwards
     */
    private function drainQueue(): array
    {
        $signals = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $signals[] = $signal;
        }

        return $signals;
    }

    /**
     * Hands the commit's sync frames to the agents the way the worker hands them to everyone it hosts.
     *
     * @param list<AbstractUserAgent> $agents Agents hosted, as a worker would host them
     * @param list<SignalDTO> $signals Frames the commit queued
     */
    private function dispatchDbSyncTo(array $agents, array $signals): void
    {
        foreach ($signals as $signal) {
            $data = $signal->data;
            if (!$data instanceof DbSyncSignalDataInterface) {
                continue;
            }
            foreach ($agents as $agent) {
                if ($data instanceof DbSyncCreatedSignalData) {
                    $agent->onSignalDbSyncCreated($data, SignalSource::DB, SignalConstants::DB_SYNC_CREATED);
                } elseif ($data instanceof DbSyncUpdatedSignalData) {
                    $agent->onSignalDbSyncUpdated($data, SignalSource::DB, SignalConstants::DB_SYNC_UPDATED);
                } elseif ($data instanceof DbSyncDeletedSignalData) {
                    $agent->onSignalDbSyncDeleted($data, SignalSource::DB, SignalConstants::DB_SYNC_DELETED);
                }
            }
        }
    }

    /**
     * @param list<SignalDTO> $signals Drained queue
     * @return list<string> Signal type of each DB-sync frame - the announcements to the other processes - in order
     */
    private static function dbFrameTypesAmong(array $signals): array
    {
        $types = [];
        foreach ($signals as $signal) {
            if ($signal->signalSource->getSource() === SignalSource::DB) {
                $types[] = $signal->signalType->getType();
            }
        }

        return $types;
    }

    /**
     * Sends the force-purge command the way the daemon routes it.
     *
     * @param AccountErasureTestAgent $agent Session holder under test
     * @param int $userId User whose account should be erased
     * @throws HilosException When the command handler fails
     */
    private function sendForcePurgeCommand(AccountErasureTestAgent $agent, int $userId): void
    {
        $agent->onSignalCommand(
            new CommandRequestDTO(
                'force-purge-correlation',
                CliCommands::ACCOUNT_TEST_FORCE_PURGE,
                [AccountDeletionCommandConstants::FIELD_USER_ID => $userId],
            ),
            '',
            '',
        );
    }

    /**
     * Drains one command reply and any accompanying state or DB frames.
     *
     * @return AccountErasureCommandOutcome The command's answer
     */
    private function consumeForcePurgeOutcome(): AccountErasureCommandOutcome
    {
        $replies = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof CommandReplyDTO) {
                $replies[] = $signal->data;
            }
        }

        self::assertCount(1, $replies, 'Every force purge answers the operator exactly once');

        return new AccountErasureCommandOutcome($replies[0]);
    }

    /**
     * Reads the sentence an error reply carried.
     *
     * @param CommandReplyDTO $reply Error reply from the session holder
     * @return string Refusal as it reaches the command line
     */
    private static function refusalMessage(CommandReplyDTO $reply): string
    {
        self::assertFalse($reply->isOk());
        $message = $reply->payload[CommandConstants::FIELD_MESSAGE] ?? null;
        self::assertIsString($message);

        return $message;
    }

    /**
     * What a framework collection holds in memory, by key, read past every load.
     *
     * @param string $collection Key of a framework object collection
     * @return array<int|string, Object_> Held objects by key, in key order
     * @throws ObjectCollectionNotFoundException When the context does not mount the collection
     */
    private static function heldIn(string $collection): array
    {
        $objects = Hilos::$db?->getObjectCollection($collection)
            ?? throw new ObjectCollectionNotFoundException("The framework collection '{$collection}' is not mounted");
        $held = [];
        foreach (array_keys($objects->toArray()) as $key) {
            $held[$key] = $objects[$key];
        }
        ksort($held);

        return $held;
    }

    /**
     * @param string $table Table cut by a user id column
     * @param int $userId Person
     * @return int Rows of the person in the table
     * @throws DatabaseException When the count fails
     */
    private static function rowsOf(string $table, int $userId): int
    {
        Database::sql("SELECT COUNT(*) AS `count` FROM `{$table}` WHERE `user_id` = ?", [$userId]);

        return (int)(Database::row()['count'] ?? 0);
    }

    /**
     * @param int $userId Recipient of the notifications whose deliveries are counted
     * @return int Delivery rows for the recipient
     * @throws DatabaseException When the count fails
     */
    private static function deliveriesOf(int $userId): int
    {
        Database::sql(
            'SELECT COUNT(*) AS `count` FROM `hilos_notification_delivery` `d` '
            . 'JOIN `hilos_notification` `n` ON `n`.`id` = `d`.`notification_id` WHERE `n`.`user_id` = ?',
            [$userId],
        );

        return (int)(Database::row()['count'] ?? 0);
    }

    /**
     * @return int Delivery rows, including those whose notification has gone
     * @throws DatabaseException When the count fails
     */
    private static function deliveryCount(): int
    {
        Database::sql('SELECT COUNT(*) AS `count` FROM `hilos_notification_delivery`');

        return (int)(Database::row()['count'] ?? 0);
    }

    /**
     * Inserts the person's and the neighbour's rows of the person table, which only the merge
     * table holds a key onto.
     *
     * @throws DatabaseException When the insert fails
     */
    private static function seedPersonRows(): void
    {
        Database::sqlRun(
            "INSERT IGNORE INTO `hilos_user` (`id`, `name`) VALUES (?, 'Person'), (?, 'Neighbour')",
            [self::USER_ID, self::NEIGHBOUR_ID],
        );
    }

    /**
     * Folds one account into another past every action, the way an earlier merge left it.
     *
     * @param int $userId Folded account
     * @param int $survivorUserId Account it was folded into
     * @throws DatabaseException When the insert fails
     */
    private static function seedMerge(int $userId, int $survivorUserId): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (?, ?, ?)',
            [$userId, $survivorUserId, self::CREATED_AT],
        );
    }

    /**
     * @param int $userId Person
     * @return bool Whether the person table still holds the person's row
     * @throws DatabaseException When the query fails
     */
    private static function personExists(int $userId): bool
    {
        Database::sql('SELECT `id` FROM `hilos_user` WHERE `id` = ?', [$userId]);

        return Database::row() !== null;
    }

    /**
     * @param int $id Request id
     * @return array<string, mixed> The request row, read past every in-memory collection
     * @throws DatabaseException When the query fails
     */
    private static function requestRow(int $id): array
    {
        Database::sql(
            'SELECT `effective_at`, `canceled_at`, `completed_at` FROM `hilos_account_deletion` WHERE `id` = ?',
            [$id],
        );

        return Database::row() ?? [];
    }

    /**
     * @param string $token Session token
     * @return ?int Person signed in on the session, or null when it is a guest or gone
     * @throws DatabaseException When the query fails
     */
    private static function userOf(string $token): ?int
    {
        $value = self::sessionRow($token)['user_id'] ?? null;

        return $value === null ? null : (int)$value;
    }

    /**
     * @param string $token Session token
     * @return ?int Person the session's sign-in waits on, or null when it waits on nobody
     * @throws DatabaseException When the query fails
     */
    private static function pendingSecondFactorOf(string $token): ?int
    {
        Database::sql('SELECT `pending_second_factor_user_id` FROM `hilos_session` WHERE `token` = ?', [$token]);
        $value = Database::row()['pending_second_factor_user_id'] ?? null;

        return $value === null ? null : (int)$value;
    }

    /**
     * @return list<string> Runtime collections the holder's other sweeps write, claimed for the case
     */
    private static function daemonCollections(): array
    {
        return [
            StateHilosSessionRotation::RT_COLLECTION,
            StateHilosSessionToastStack::RT_COLLECTION,
            StateHilosOAuthTrip::RT_COLLECTION,
            StateRecoveryWaiter::RT_COLLECTION,
            StateRegistrationWaiter::RT_COLLECTION,
            StateHilosCodeSendAttempt::RT_COLLECTION,
            StateHilosProfileFlow::RT_COLLECTION,
        ];
    }

    /**
     * @param class-string<Hilos> $hilosClass Project facade whose feature declaration is read
     */
    private static function bindAppClass(string $hilosClass): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $hilosClass);
    }

    /**
     * Raises or drops the tables this erasure test adds to the session integration base.
     *
     * @param bool $down Drop tables when true, create them when false
     * @throws DatabaseException When a stub statement fails
     */
    private static function runExtraStubs(bool $down): void
    {
        $tables = [
            'hilos_user_verification',
            'hilos_passkey_credential',
            'hilos_notification',
            'hilos_notification_delivery',
            'hilos_notification_preference',
            'hilos_push_subscription',
            'hilos_file',
            'hilos_user_photo',
            'hilos_legal_acceptance_export',
        ];
        // external-boundary: the up stub has no suffix in its file name
        $suffix = $down ? '_down' : '';
        foreach ($down ? array_reverse($tables) : $tables as $table) {
            $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql";
            Database::sqlRun((string)file_get_contents($stub));
        }
    }
}

/**
 * One queue pass after the force-purge command.
 */
final readonly class AccountErasureCommandOutcome
{
    /**
     * @param CommandReplyDTO $reply The command's one reply
     */
    public function __construct(
        public CommandReplyDTO $reply,
    ) {
    }
}

/**
 * Fixture project that draws a sign-in surface, so the holder arms the erasure.
 */
abstract class AccountErasureTestHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::AUTH];
}

/**
 * Fixture project that also keeps a files registry, so the holder hands erased files to it.
 */
abstract class AccountErasureFilesTestHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::AUTH, HilosFeature::FILES];
}

/**
 * Session holder of the fixture project, whose seam records the erasure or refuses it.
 */
final class AccountErasureTestAgent extends AbstractSessionsLibraryAgent
{
    /** Account whose seam refuses, to prove the rollback. */
    public ?int $failingFor = null;

    /** Account whose hook writes a fresh notification after the framework cleanup. */
    public ?int $insertLateNotificationFor = null;

    /** @var list<int> People whose project rows the seam was asked to delete */
    public array $erased = [];

    /** @var list<array{int, bool, int}> Person and rename journal state seen by each hook call */
    public array $seenAtHook = [];

    /** @var array<int, list<int>> Registry files the seam names for each erased person */
    public array $fileIdsOf = [];

    public function onStop(): void
    {
    }

    /**
     * @param int $userId Person whose account is being erased
     * @return AccountErasure One project row, and the registry files the case names for the person
     * @throws ValidationException When the case asks the seam to refuse
     * @throws DatabaseException When the person or rename journal cannot be inspected
     */
    protected function applyAccountErasure(int $userId): AccountErasure
    {
        if ($this->failingFor === $userId) {
            throw new ValidationException('The project refused the erasure');
        }
        $this->erased[] = $userId;
        Database::sql('SELECT `id` FROM `hilos_user` WHERE `id` = ?', [$userId]);
        $exists = Database::row() !== null;
        Database::sql('SELECT COUNT(*) AS `count` FROM `hilos_user_rename` WHERE `user_id` = ?', [$userId]);
        $this->seenAtHook[] = [$userId, $exists, (int)(Database::row()['count'] ?? 0)];
        if ($this->insertLateNotificationFor === $userId) {
            Database::sqlRun(
                'INSERT INTO `hilos_notification` (`user_id`, `type`, `title`) VALUES (?, ?, ?)',
                [$userId, 'account.test', 'Late notification'],
            );
        }

        return new AccountErasure(['projectRows' => 1], $this->fileIdsOf[$userId] ?? []);
    }
}

/**
 * Runtime holding the fixture's session-stage connection roster, empty.
 */
final class AccountErasureTestRtContext extends RtContext
{
    public function configure(): void
    {
        $this->_stateCollections[AccountErasureTestConnections::RT_COLLECTION] = AccountErasureTestConnections::init();
    }
}

/**
 * Session-stage connection collection of the fixture.
 */
final class AccountErasureTestConnections extends HilosSessionConnections
{
    public const string RT_COLLECTION = 'accountErasureTestConnections';
    public const string STATE_CLASS = AccountErasureTestConnection::class;
}

/**
 * Session-stage connection row adding no project fields.
 */
final class AccountErasureTestConnection extends HilosSessionConnection
{
    protected function initOwn(): void
    {
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     */
    protected function hydrateOwn(array $row): void
    {
    }

    /**
     * @return array<string, mixed> No project fields
     */
    protected function ownToArray(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $diff Incoming field changes
     */
    protected function applyOwnDiff(array $diff): void
    {
    }
}

/** The framework's agent of one person, with nothing overridden, so a sync frame can ask it to stop. */
final class AccountErasureStopTestAgent extends AbstractUserAgent
{
}
