<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\SecondFactor\Base32;
use Hilos\Auth\SecondFactor\SecondFactorPendingMode;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Object\Collection\UserVerifications as ObjectUserVerifications;
use Hilos\Database\Verification\VerificationType;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Notification\DTO\NotificationForgetUserSignalData;
use Hilos\Notification\HilosNotifier;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt as StateHilosCodeSendAttempt;
use Hilos\Runtime\State\Item\HilosOAuthTrip as StateHilosOAuthTrip;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\Runtime\State\Item\HilosSessionToastStack as StateHilosSessionToastStack;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\State\Item\RecoveryWaiter as StateRecoveryWaiter;
use Hilos\Runtime\State\Item\RegistrationWaiter as StateRegistrationWaiter;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\AccountErasure;
use ReflectionProperty;

/**
 * The erasure of an account whose deletion fell due, run by the session holder (HIL-302).
 *
 * One transaction: the request is marked carried out, the framework's rows of the person go,
 * the project's seam deletes its own, and a failure anywhere rolls all of it back. After the
 * commit every session the person stands in is signed out and the notifications library is
 * asked to forget them. A neighbour with the same rows in every table is the proof that the
 * erasure cut by the person and nothing wider.
 */
final class AccountErasureIntegrationTest extends HilosSessionIntegrationTestCase
{
    public const int USER_ID = 302;
    public const int NEIGHBOUR_ID = 303;

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
        $request = Hilos::$db->accountDeletions->actions->request(self::USER_ID, self::PAST);
        $requestId = (int)$request->id;

        $agent = $this->runSweep();

        self::assertSame([self::USER_ID], $agent->erased);
        foreach (self::ERASED_TABLES as $table) {
            self::assertSame(0, self::rowsOf($table, self::USER_ID), "{$table} keeps nothing of the person");
            self::assertSame(1, self::rowsOf($table, self::NEIGHBOUR_ID), "{$table} keeps the neighbour");
        }
        $row = self::requestRow($requestId);
        self::assertNotNull($row['completed_at'], 'The request stays behind, carried out');
        self::assertNull($row['canceled_at']);

        $signedIn = self::sessionRow(self::SIGNED_IN_TOKEN);
        self::assertNotNull($signedIn, 'The session stays, as a guest');
        self::assertNull($signedIn['user_id']);
        $takeover = self::sessionRow(self::TAKEOVER_TOKEN);
        self::assertNotNull($takeover);
        self::assertNull($takeover['user_id'], 'The takeover the person ran is ended');
        self::assertNull($takeover['impersonator_user_id']);
        self::assertNull(self::pendingSecondFactorOf(self::WAITING_TOKEN), 'The sign-in waiting on their factor is let go');
        self::assertSame(self::NEIGHBOUR_ID, self::userOf(self::NEIGHBOUR_TOKEN));

        self::assertSame([self::USER_ID], $this->forgottenUsers());
    }

    /**
     * A failure of the project's seam rolls every row back, and the request stays due.
     *
     * @throws HilosException When seeding or the sweep fails
     */
    public function testAFailingSeamRollsEverythingBack(): void
    {
        $this->seedPerson(self::USER_ID, self::SIGNED_IN_TOKEN);
        $request = Hilos::$db->accountDeletions->actions->request(self::USER_ID, self::PAST);
        $requestId = (int)$request->id;

        $this->runSweep(failing: true);

        foreach (self::ERASED_TABLES as $table) {
            self::assertSame(1, self::rowsOf($table, self::USER_ID), "{$table} is rolled back");
        }
        $row = self::requestRow($requestId);
        self::assertNull($row['completed_at'], 'The request is still standing and due');
        self::assertNull($row['canceled_at']);
        self::assertSame(self::USER_ID, self::userOf(self::SIGNED_IN_TOKEN));
        self::assertSame([], $this->forgottenUsers());
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
     * Seeds one row of the person in every table the erasure cuts, and a session signed in as them.
     *
     * @param int $userId Person
     * @param string $token Session signed in as the person
     * @throws HilosException When a row cannot be written
     */
    private function seedPerson(int $userId, string $token): void
    {
        self::seedSession($token, $userId, self::CREATED_AT, null);
        $sessionId = (int)Hilos::$db->sessions->findByToken($token)?->id;

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
        Hilos::$db->secondFactorResets->actions->request($userId, self::FUTURE, hash('sha256', "cancel-{$userId}"));
        Hilos::$db->secondFactorSettings->actions->setResetWait($userId, 10, null, null);
        Hilos::$db->secondFactorTrusts->actions->trust($sessionId, $userId, self::FUTURE);
        Hilos::$db->stepUps->actions->confirm(
            ProtectedModeRuntime::hashSessionToken($token),
            $userId,
            StepUpOperationKey::DELETE_ACCOUNT,
            self::FUTURE,
        );
    }

    /**
     * Arms and runs the session holder's tick once.
     *
     * @param bool $failing Whether the project's seam refuses
     * @return AccountErasureTestAgent The holder that ran
     * @throws HilosException When the tick fails
     */
    private function runSweep(bool $failing = false): AccountErasureTestAgent
    {
        $agent = new AccountErasureTestAgent();
        $agent->failing = $failing;
        $agent->onStart();
        $agent->onTick();

        return $agent;
    }

    /**
     * Drains the queue and returns the people the notifications library was asked to forget.
     *
     * @return list<int> User ids, in order
     */
    private function forgottenUsers(): array
    {
        $users = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== HilosSignalConstants::HILOS_NOTIFICATION_FORGET_USER) {
                continue;
            }
            self::assertInstanceOf(AgentSignalData::class, $signal->data);
            self::assertInstanceOf(NotificationForgetUserSignalData::class, $signal->data->data);
            $users[] = $signal->data->data->userId;
        }

        return $users;
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
     * @param int $id Request id
     * @return array<string, mixed> The request row, read past every in-memory collection
     * @throws DatabaseException When the query fails
     */
    private static function requestRow(int $id): array
    {
        Database::sql('SELECT `canceled_at`, `completed_at` FROM `hilos_account_deletion` WHERE `id` = ?', [$id]);

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
     * Raises or drops the two auth tables the session integration base does not otherwise need.
     *
     * @param bool $down Drop tables when true, create them when false
     * @throws DatabaseException When a stub statement fails
     */
    private static function runExtraStubs(bool $down): void
    {
        $tables = $down
            ? ['hilos_passkey_credential', 'hilos_user_verification']
            : ['hilos_user_verification', 'hilos_passkey_credential'];
        // external-boundary: the up stub has no suffix in its file name
        $suffix = $down ? '_down' : '';
        foreach ($tables as $table) {
            $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql";
            Database::sqlRun((string)file_get_contents($stub));
        }
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
 * Session holder of the fixture project, whose seam records the erasure or refuses it.
 */
final class AccountErasureTestAgent extends AbstractSessionsLibraryAgent
{
    /** Whether the seam refuses, to prove the rollback. */
    public bool $failing = false;

    /** @var list<int> People whose project rows the seam was asked to delete */
    public array $erased = [];

    public function onStop(): void
    {
    }

    /**
     * @param int $userId Person whose account is being erased
     * @return AccountErasure Nothing of a project, and no files
     * @throws ValidationException When the case asks the seam to refuse
     */
    protected function applyAccountErasure(int $userId): AccountErasure
    {
        if ($this->failing) {
            throw new ValidationException('The project refused the erasure');
        }
        $this->erased[] = $userId;

        return new AccountErasure([], []);
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
