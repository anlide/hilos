<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Detection\IdentifierDetector;
use Hilos\Auth\Flow\AuthFlowIntent;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Flow\AuthFlowStep;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\Command\AbstractLibraryCommands;
use Hilos\Auth\Library\Command\ActingSession;
use Hilos\Auth\Library\Command\PasswordCommands;
use Hilos\Auth\Library\DTO\ConfirmRegisterActionDTO;
use Hilos\Auth\Registration\RegistrationReservationService;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\SqlConnection\CantConnectToMysqlServerException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Object\Collection\RegistrationReservations as ObjectRegistrationReservations;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\HilosException;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * What an account gets at the landing: the password brought to it, or the method's own (HIL-608, HIL-825).
 *
 * The landing has two arms, and only one of them reads a reservation. When this
 * browser holds the identifier, the hold says what the account gets. When it does
 * not - a link opened on a fresh tab, or a hold that ran out in the moment between
 * the caller's own check and the service's - there is no type to read and the
 * identity is chosen by what the proven identifier IS.
 *
 * That second arm is pinned here rather than through a page, because through a page
 * it is unreachable by construction: the code handlers refuse a proof they hold
 * nothing for, so the only door into it is the expiry falling between two reads a
 * microsecond apart. The service is called directly and the hold is seeded already
 * expired, which is the same state that race produces and the one a caller of this
 * framework API can reach on purpose.
 *
 * The mail arm is here beside it deliberately: the two are one `match`, and a
 * regression in either is a person left with an account they cannot sign into.
 *
 * The password arm joined them with HIL-825, and it is the arm the ordinary
 * registration takes now: the credential is no longer read off the hold, it is
 * carried in by the caller from the screen that asked for it. Its case is pinned
 * beside the other two for the same reason - all three are one decision, and picking
 * the wrong one leaves somebody locked out of an account that was just made for them.
 *
 * The two refusal cases (HIL-992) prove the transaction those arms run inside of. They
 * call the landing of the commands group directly, past every guard a screen would put
 * in front of it, so the failure is raised AFTER the user row is inserted - and what is
 * measured is the rollback and the closing of the transaction, which nothing reachable
 * through a page exercises since the credential moved to the landing.
 */
final class RegistrationLandingIntegrationTest extends FrameworkIntegrationTestCase
{
    /**
     * Table the fixture library writes the user row into; created and dropped by the case
     * that needs it, outside the landing, because DDL commits implicitly in MySQL. Public
     * for the library at the tail of this file, which owns the rows and nothing else.
     */
    public const string FIXTURE_USER_TABLE = 'landing_fixture_user';

    /** @var list<string> Framework tables this case needs */
    private const array TABLES = ['hilos_identity', 'hilos_registration_reservation'];

    private const string SESSION_TOKEN = 'registration-landing-test-session-token';

    /** Owner of the account the landing writes an identity for; no user table is read. */
    private const int USER_ID = 4343;

    /** Seconds a seeded hold is already past its expiry by. */
    private const int EXPIRED_BY_SECONDS = -60;

    /** Seconds a seeded live hold has left. */
    private const int LIVE_FOR_SECONDS = 900;

    /** Seconds left on a hold seeded to show the accepted code pushing the expiry out. */
    private const int NEARLY_OVER_SECONDS = 30;

    /** Password the registration screen is taken to have submitted. */
    private const string PASSWORD = 'landing-secret-4343';

    /**
     * Accept key of the socket the landing acts for. The landing itself looks up no connection
     * behind it; the confirm cases below do, so the fixture runtime carries a row for it.
     */
    private const string ACCEPT_KEY = 'accept-key-of-the-landing-browser';

    /** A code the confirm cases never reach the checking of, because both refuse above it. */
    private const string SUBMITTED_CODE = '123456';

    /** Connection index of the outside observer; the case itself holds the primary one. */
    private const int RIVAL_INDEX = 1;

    /** Owner of the password identity already standing on the address the landing wants. */
    private const int RIVAL_USER_ID = 4344;

    private const string RIVAL_SECRET = 'the-other-account-secret';

    /** Display name of the marker row written after the landing to show its transaction is over. */
    private const string PROBE_NAME = 'transaction-probe';

    private ?DbContext $previousDb = null;

    private ?SignalRouter $previousSignalRouter = null;

    /** @var ?RtContext Runtime context to restore after the test */
    private ?RtContext $previousRt = null;

    /**
     * @throws HilosException When a stub statement fails or the context cannot be configured
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::runStubs(down: true);
        self::runStubs(down: false);

        $this->previousDb = Hilos::$db;
        $this->previousSignalRouter = Hilos::$sr;

        $db = new RegistrationLandingTestDbContext();
        $db->configure();
        Hilos::$db = $db;
        Hilos::$sr = new SignalRouter();
        $this->previousRt = Hilos::$rt;
        $rt = new RegistrationLandingTestRtContext();
        $rt->mountFeatureRuntime([]);
        $rt->configure();
        $rt->bindStateCollectionNames();
        Hilos::$rt = $rt;
    }

    /**
     * @throws HilosException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$db = $this->previousDb;

        self::runStubs(down: true);

        parent::tearDown();
    }

    /**
     * A number proved with no live hold left lands the identity a number signs in with.
     *
     * The phone confirm goes through the same landing as the letter since HIL-608, so
     * the holdless arm may not assume an address. A number landed as a mailed sign-in
     * would leave its owner unable to sign in with it and leave the number reading as
     * free to the next registration - a second account for one person, which is the
     * capture this leaf closes wearing another face.
     *
     * @throws HilosException When the hold seed or the landing fails
     */
    public function testANumberProvenWithNoLiveHoldLandsAnSmsIdentity(): void
    {
        $phone = $this->uniquePhone();
        $this->seedExpiredHold(IdentityType::SMS, $phone);

        new RegistrationReservationService()->confirmProvenAddress(self::SESSION_TOKEN, $phone, self::USER_ID);

        $identity = Hilos::$db?->identities->findByIdentity(IdentityType::SMS, $phone);
        self::assertNotNull($identity, 'A proven number must earn the identity its own method signs in with');
        self::assertSame(self::USER_ID, $identity->userId);
        self::assertNull(
            Hilos::$db?->identities->findByIdentity(IdentityType::MAGIC_LINK, $phone),
            'A number is not a mailbox, so no letter identity may be written for it',
        );
    }

    /**
     * An address proved with no live hold left still lands the mailed sign-in.
     *
     * The other arm of the same choice, and the one the leaf was written for: the
     * letter is the proof of the inbox whether or not the hold outlived it.
     *
     * @throws HilosException When the hold seed or the landing fails
     */
    public function testAnAddressProvenWithNoLiveHoldLandsAMagicLinkIdentity(): void
    {
        $email = $this->uniqueEmail();
        $this->seedExpiredHold(IdentityType::MAGIC_LINK, $email);

        new RegistrationReservationService()->confirmProvenAddress(self::SESSION_TOKEN, $email, self::USER_ID);

        $identity = Hilos::$db?->identities->findByIdentity(IdentityType::MAGIC_LINK, $email);
        self::assertNotNull($identity, 'A proven address must earn the identity its letter names');
        self::assertSame(self::USER_ID, $identity->userId);
        self::assertNull(
            Hilos::$db?->identities->findByIdentity(IdentityType::SMS, $email),
            'And nothing a number would have earned',
        );
    }

    /**
     * The password the last screen submitted is what the new account signs in with.
     *
     * The ordinary registration since HIL-825: the hold carries nothing, the plaintext
     * comes in with the landing, and it is hashed into an identity that is VERIFIED at
     * birth - the code that proved the address is the proof the old "confirm later" flag
     * used to wait for. A regression here writes an account whose password is not the one
     * its owner chose, which no error message would ever tell them.
     *
     * @throws HilosException When the hold seed or the landing fails
     */
    public function testThePasswordBroughtToTheLandingBecomesTheVerifiedIdentity(): void
    {
        $email = $this->uniqueEmail();
        $this->seedLiveHold(IdentityType::PASSWORD, self::SESSION_TOKEN, $email);

        new RegistrationReservationService()
            ->confirmProvenAddress(self::SESSION_TOKEN, $email, self::USER_ID, self::PASSWORD);

        $identity = Hilos::$db?->identities->findByIdentity(IdentityType::PASSWORD, $email);
        self::assertNotNull($identity, 'A registration that ends on a password screen earns a password identity');
        self::assertSame(self::USER_ID, $identity->userId);
        self::assertTrue($identity->verified, 'The code proved the address before the password was ever asked for');
        self::assertTrue(
            $identity->verifyPassword(self::PASSWORD),
            'The account must sign in with the password its owner submitted',
        );
    }

    /**
     * The landing ends every hold on the address: this browser's and the racing ones.
     *
     * The race moved here with the account (HIL-825): several browsers may prove one
     * address, because the letter reaches the inbox and not the browser, and the one that
     * saves a password first takes it. The losers are named back so they can be told, and
     * their rows have to go - a hold left standing would refuse them a second attempt on
     * an address that now has an owner.
     *
     * @throws HilosException When the hold seed or the landing fails
     */
    public function testTheLandingClearsTheWinnersHoldAndNamesTheLosers(): void
    {
        $email = $this->uniqueEmail();
        $loserToken = self::SESSION_TOKEN . '-loser';
        $this->seedLiveHold(IdentityType::PASSWORD, self::SESSION_TOKEN, $email);
        $this->seedLiveHold(IdentityType::PASSWORD, $loserToken, $email);

        $losers = new RegistrationReservationService()
            ->confirmProvenAddress(self::SESSION_TOKEN, $email, self::USER_ID, self::PASSWORD);

        self::assertSame([$loserToken], $losers, 'Every other browser on the address is named exactly once');
        self::assertNull(
            $this->reservations()->findActiveForSession(self::SESSION_TOKEN),
            'The winner\'s hold has served its purpose and must not claim a registration that is over',
        );
        self::assertNull(
            $this->reservations()->findActiveForSession($loserToken),
            'A loser holding on would be refused its own second attempt for the whole TTL',
        );
    }

    /**
     * An accepted code leaves the hold alive and marked, and pushes its expiry out.
     *
     * What replaced "the code creates the account" (HIL-825). The proof has to outlive the
     * code, because the code is spent the moment it is accepted and the person still owes
     * a password; and the hold has to outlive the reading of the letter, because from here
     * it holds the address while somebody invents one. A hold that ran out on its
     * fourteenth minute would take the address away one minute into the thinking.
     *
     * @throws HilosException When the hold seed or the mark fails
     */
    public function testAnAcceptedCodeLeavesTheHoldProvenAndExtended(): void
    {
        $email = $this->uniqueEmail();
        $this->seedLiveHold(IdentityType::PASSWORD, self::SESSION_TOKEN, $email, self::NEARLY_OVER_SECONDS);
        $expiredAt = $this->reservations()->findActiveForSession(self::SESSION_TOKEN)?->expiresAt;

        $marked = new RegistrationReservationService()->markProven(self::SESSION_TOKEN, $email);

        self::assertTrue($marked, 'This browser holds the address it just proved');
        $reservation = $this->reservations()->findActiveForSession(self::SESSION_TOKEN);
        self::assertNotNull($reservation, 'Proving an address ends the code, not the hold');
        self::assertTrue($reservation->isProven(), 'The proof is durable, so a reload lands on the password screen');
        self::assertNotNull($reservation->codeAcceptedAt);
        self::assertGreaterThan(
            (string)$expiredAt,
            $reservation->expiresAt,
            'After the code the hold keeps the address while a password is chosen',
        );
    }

    /**
     * A browser that holds nothing proves nothing, whoever else is registering the address.
     *
     * The hold went away between the caller's check and this one - swept, or evicted by
     * another socket of the same browser submitting a different address. Marking anyway
     * would be marking somebody else's row or none at all, so the refusal is what lets the
     * caller answer the honest "your registration expired".
     *
     * @throws HilosException When the mark fails
     */
    public function testProvingWithoutAHoldIsRefused(): void
    {
        $email = $this->uniqueEmail();

        self::assertFalse(new RegistrationReservationService()->markProven(self::SESSION_TOKEN, $email));
    }

    /**
     * An address refused inside the landing transaction leaves no user row, and no transaction open.
     *
     * The guarantee the landing exists for: the account and the identity that makes it
     * reachable stand or fall together. Until HIL-825 a demo case forced this refusal by
     * seeding a hold with no credential; that shape is gone, and every refusal the demo can
     * still reach is caught by a guard IN FRONT of the transaction. Here the landing is
     * called directly, past the guards, and the refusal is raised by the identity write
     * itself - the collection refuses a second password identity on the address - AFTER
     * the library has inserted the user row.
     *
     * Two things are proven, and the second on purpose apart from the first: the row was
     * there inside the transaction and is gone outside it, and the transaction is CLOSED.
     * A ROLLBACK TO SAVEPOINT would pass the first and fail the second, and a transaction
     * left open on a worker's connection is the orphan-account failure the landing's own
     * cleanup is written against.
     *
     * @throws HilosException When a seed, the fixture table, the landing or the probe fails
     */
    public function testATakenAddressRefusedInsideTheTransactionLeavesNoUserAndCloses(): void
    {
        $email = $this->uniqueEmail();
        $this->createFixtureUserTable();
        try {
            $this->seedLiveHold(IdentityType::PASSWORD, self::SESSION_TOKEN, $email);
            Hilos::$db?->identities->createPasswordIdentity(self::RIVAL_USER_ID, $email, self::RIVAL_SECRET);
            $library = new RegistrationLandingFixtureLibrary();

            $outcome = new RegistrationLandingTestCommands($library)
                ->land(new ActingSession(self::ACCEPT_KEY, self::SESSION_TOKEN, null), $email, 'Landing', self::PASSWORD);

            self::assertNotNull($outcome, 'A taken address is answered with a rollback outcome, not thrown');
            self::assertFalse($outcome->ok);
            self::assertSame(AuthFlowOutcome::CODE_IDENTIFIER_TAKEN, $outcome->code);
            self::assertSame(AuthFlowStep::IDENTIFIER, $outcome->step);
            self::assertSame(AuthFlowIntent::LOGIN, $outcome->intent);
            $this->assertTheLandingRolledBackAndClosed($library);
        } finally {
            $this->dropFixtureUserTable();
        }
    }

    /**
     * A failure of another kind inside the transaction rolls it back, closes it, and is rethrown.
     *
     * The other way out of the landing: not a duplicate answered as an outcome but a
     * HilosException carried up to the caller. It is reached with no forgery - a password
     * hold landed with no password is the contradiction the reservation service refuses -
     * and it is pinned apart from the first case because the two catches are two lines,
     * and either could lose its rollback alone.
     *
     * @throws HilosException When a seed, the fixture table or the probe fails
     */
    public function testAFailureRaisedInsideTheTransactionRollsBackAndCloses(): void
    {
        $email = $this->uniqueEmail();
        $this->createFixtureUserTable();
        try {
            $this->seedLiveHold(IdentityType::PASSWORD, self::SESSION_TOKEN, $email);
            $library = new RegistrationLandingFixtureLibrary();

            $raised = null;
            try {
                new RegistrationLandingTestCommands($library)
                    ->land(new ActingSession(self::ACCEPT_KEY, self::SESSION_TOKEN, null), $email, 'Landing', null);
            } catch (LogicException $failure) {
                $raised = $failure;
            }

            self::assertNotNull($raised, 'A hold that cannot be landed is refused with its failure, not answered');
            $this->assertTheLandingRolledBackAndClosed($library);
        } finally {
            $this->dropFixtureUserTable();
        }
    }

    /**
     * A code typed on an address that became somebody else's is answered as taken, not as expired.
     *
     * The door the loser of a race walks into when its submit and the news pass each other
     * by a fraction of a second (HIL-833). The winner's landing took this browser's hold
     * away, so the check that used to be asked first found nothing and answered "the
     * registration expired" - which is wrong twice over: the code in the letter is alive,
     * and the new one that screen offers walks the same circle back to the same taken
     * address. Asking the ADDRESS first is the whole of the fix, and this is what it says.
     *
     * @throws HilosException When the identity seed or the confirm fails
     */
    public function testACodeOnAnAddressThatBecameSomebodysIsAnsweredAsTaken(): void
    {
        $email = $this->uniqueEmail();
        Hilos::$db?->identities->createPasswordIdentity(self::RIVAL_USER_ID, $email, self::RIVAL_SECRET);

        $outcome = new PasswordCommands(new RegistrationLandingFixtureLibrary())
            ->confirmRegister(self::ACCEPT_KEY, new ConfirmRegisterActionDTO($email, self::SUBMITTED_CODE));

        self::assertNotNull($outcome, 'A refusal is answered to the submitting socket, not handed to the session');
        self::assertFalse($outcome->ok);
        self::assertSame(AuthFlowOutcome::CODE_IDENTIFIER_TAKEN, $outcome->code);
        self::assertSame(AuthFlowStep::IDENTIFIER, $outcome->step);
        self::assertSame(AuthFlowIntent::LOGIN, $outcome->intent);
    }

    /**
     * A dead hold on an address nobody took is still answered as a hold that ran out.
     *
     * The branch the reorder moved PAST, pinned so it cannot be swallowed by the one put in
     * front of it: an address still free has no account to send anybody to, and the honest
     * answer there is the screen that offers a fresh code.
     *
     * @throws HilosException When the confirm fails
     */
    public function testACodeOnAFreeAddressWithNoHoldIsStillAnsweredAsExpired(): void
    {
        $outcome = new PasswordCommands(new RegistrationLandingFixtureLibrary())
            ->confirmRegister(self::ACCEPT_KEY, new ConfirmRegisterActionDTO($this->uniqueEmail(), self::SUBMITTED_CODE));

        self::assertNotNull($outcome);
        self::assertFalse($outcome->ok);
        self::assertSame(AuthFlowOutcome::CODE_RESERVATION_EXPIRED, $outcome->code);
        self::assertSame(AuthFlowStep::CODE_EXPIRED, $outcome->step);
    }

    /**
     * Writes a live hold of one session on an identifier.
     *
     * @param string $type Reserving method the hold is made under (see IdentityType)
     * @param string $sessionToken Session cookie token of the browser leading it
     * @param string $identifier Normalized identifier the hold names
     * @param int $ttlSeconds Seconds the hold has left
     * @throws HilosException When the reservation insert fails
     */
    private function seedLiveHold(
        string $type,
        string $sessionToken,
        string $identifier,
        int $ttlSeconds = self::LIVE_FOR_SECONDS,
    ): void {
        $this->reservations()->createReservation($type, $sessionToken, $identifier, $ttlSeconds);
    }

    /**
     * @return ObjectRegistrationReservations Framework-owned reservation primitives
     * @throws HilosException When the collection is unavailable
     */
    private function reservations(): ObjectRegistrationReservations
    {
        /** @var ObjectRegistrationReservations $collection */
        $collection = Hilos::$db?->getObjectCollection(HilosDbContext::registrationReservations);

        return $collection;
    }

    /**
     * Writes a hold of this session that is already past its expiry.
     *
     * The state the race leaves behind: the row is there, so the caller's check saw
     * it, and it is dead, so the landing's own read finds nothing to land.
     *
     * @param string $type Reserving method the dead hold was made under (see IdentityType)
     * @param string $identifier Normalized identifier the hold names
     * @throws HilosException When the reservation insert fails
     */
    private function seedExpiredHold(string $type, string $identifier): void
    {
        $this->reservations()->createReservation($type, self::SESSION_TOKEN, $identifier, self::EXPIRED_BY_SECONDS);
    }

    /**
     * @return string Unique lowercase address for one case
     */
    private function uniqueEmail(): string
    {
        return RandomHelper::hex(8) . '@example.test';
    }

    /**
     * @return string Unique E.164 number for one case
     */
    private function uniquePhone(): string
    {
        return '+1' . str_pad((string)random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
    }

    /**
     * The proof both refusal cases share, in three parts.
     *
     * The user row was visible inside the transaction - the library counted it on the same
     * connection right after its insert, so a failure raised BEFORE the insert cannot pass
     * as a rollback. It is gone outside it. And a marker written afterwards is visible to
     * another connection, which a transaction left open would have swallowed; that part is
     * apart from the second because a ROLLBACK TO SAVEPOINT would empty the table and leave
     * the transaction standing.
     *
     * @param RegistrationLandingFixtureLibrary $library Library that inserted the user row and counted it in place
     * @throws DatabaseException When the count, the marker write or the probe count fails
     * @throws DatabaseConnectionException When the second connection cannot be opened
     * @throws CantConnectToMysqlServerException When connect retries are exhausted
     * @throws EnvException When env variables are missing or invalid
     */
    private function assertTheLandingRolledBackAndClosed(RegistrationLandingFixtureLibrary $library): void
    {
        self::assertSame(
            1,
            $library->rowsSeenInsideTransaction,
            'The user row must have been inserted before the failure, or the transaction was never exercised',
        );
        self::assertSame(
            0,
            RegistrationLandingFixtureLibrary::rowsVisible(),
            'A refused landing leaves no user row behind, not even an uncommitted one',
        );

        Database::sql(
            'INSERT INTO `' . self::FIXTURE_USER_TABLE . '` (`display_name`) VALUES (?)',
            [self::PROBE_NAME],
        );
        self::assertSame(
            1,
            $this->rowsSeenByAnotherConnection(),
            'A marker written after the landing must be visible from outside: a transaction left open would have taken it in',
        );
    }

    /**
     * Counts the fixture rows as a second connection sees them: committed ones, and only those.
     *
     * @return int Fixture rows visible from outside the case's own connection
     * @throws DatabaseException When the count fails
     * @throws DatabaseConnectionException When the second connection cannot be opened
     * @throws CantConnectToMysqlServerException When connect retries are exhausted
     * @throws EnvException When env variables are missing or invalid
     */
    private function rowsSeenByAnotherConnection(): int
    {
        Database::configure(
            index: self::RIVAL_INDEX,
            host: Hilos::$env[EnvConstants::DB_HOST]->string(),
            user: Hilos::$env[EnvConstants::DB_USERNAME]->string(),
            password: Hilos::$env[EnvConstants::DB_PASSWORD]->string(),
            database: Hilos::$env[EnvConstants::DB_DATABASE]->string(),
            port: Hilos::$env[EnvConstants::DB_PORT]->int(),
            charset: DatabaseConnectionDefaults::CHARSET,
        );
        Database::connect(self::RIVAL_INDEX);
        Database::useConnection(self::RIVAL_INDEX);

        try {
            return RegistrationLandingFixtureLibrary::rowsVisible();
        } finally {
            Database::close(self::RIVAL_INDEX);
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        }
    }

    /**
     * Creates the fixture user table, outside the landing.
     *
     * DDL commits implicitly in MySQL, so a CREATE inside the call would end the very
     * transaction the case asks about. A leftover of an interrupted run is dropped first,
     * as the stub tables are.
     *
     * @throws DatabaseException When a statement fails
     */
    private function createFixtureUserTable(): void
    {
        $this->dropFixtureUserTable();
        Database::sqlRun(
            'CREATE TABLE `' . self::FIXTURE_USER_TABLE . '` ('
            . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            . '`display_name` VARCHAR(255) NOT NULL'
            . ') ' . DatabaseConnectionDefaults::DDL_TABLE_SUFFIX,
        );
    }

    /**
     * @throws DatabaseException When the statement fails
     */
    private function dropFixtureUserTable(): void
    {
        Database::sqlRun('DROP TABLE IF EXISTS `' . self::FIXTURE_USER_TABLE . '`');
    }

    /**
     * Runs one direction of the stub file of every table this case uses.
     *
     * @param bool $down Run the down (drop) stubs when true, the create stubs when false
     * @throws HilosException When a stub statement fails
     */
    private static function runStubs(bool $down): void
    {
        // external-boundary: the neutral element of the name being built - the up file carries no suffix
        $suffix = $down ? '_down' : '';
        foreach (self::TABLES as $table) {
            $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql";
            Database::sqlRun((string)file_get_contents($stub));
        }
    }
}

/**
 * A framework database context with nothing but the framework's own collections.
 *
 * The landing reads identities and reservations, both framework-owned, so the
 * smallest honest context for it is {@see HilosDbContext} with no project
 * collections - the user it writes an identity for is an id, and the users table
 * belongs to the project.
 */
final class RegistrationLandingTestDbContext extends HilosDbContext
{
}

/**
 * Users library of a fixture project whose one job is to insert a user row and say it did.
 *
 * The seam the landing transaction wraps: {@see createUser()} writes into the case's
 * fixture table and, before returning, counts the rows on the SAME connection - inside
 * the transaction - so the case can tell a rollback from a failure that never reached the
 * insert. The other two seams are unreachable on the refusal paths the cases walk.
 * afterUserCreated() is left as inherited: it runs after the commit, and a refused landing
 * never gets there.
 */
final class RegistrationLandingFixtureLibrary extends AbstractUsersLibraryAgent
{
    /** Rows of the fixture table createUser() saw right after its insert, or null while it has not run */
    public ?int $rowsSeenInsideTransaction = null;

    /**
     * @return int Rows of the fixture user table the current connection can see
     * @throws DatabaseException When the count fails or answers no row
     */
    public static function rowsVisible(): int
    {
        Database::sql('SELECT COUNT(*) AS `total` FROM `' . RegistrationLandingIntegrationTest::FIXTURE_USER_TABLE . '`');
        $row = Database::row();
        if ($row === null) {
            throw new DatabaseException('COUNT(*) answered no row');
        }

        return (int)$row['total'];
    }

    /**
     * @throws DatabaseException When the fixture insert or the count after it fails
     */
    public function createUser(string $displayName): int
    {
        Database::sql(
            'INSERT INTO `' . RegistrationLandingIntegrationTest::FIXTURE_USER_TABLE . '` (`display_name`) VALUES (?)',
            [$displayName],
        );
        $userId = Database::lastInsertId();
        $this->rowsSeenInsideTransaction = self::rowsVisible();

        return $userId;
    }

    public function displayNameOf(int $userId): ?string
    {
        throw new LogicException('The fixture library names no users');
    }

    protected function buildAuthMethods(): IdentifierDetector
    {
        throw new LogicException('The fixture library detects nothing');
    }
}

/**
 * Commands group that opens the landing to the case; it holds no commands of its own.
 */
final class RegistrationLandingTestCommands extends AbstractLibraryCommands
{
    /**
     * @param ActingSession $acting Browser the proof arrived on
     * @param string $identifier Normalized identifier the proof just settled
     * @param string $displayName Name the new account is created with
     * @param ?string $plainPassword Password the account signs in with, or null for a way in that carries none
     * @return ?AuthFlowOutcome The taken-address rollback to answer with, or null when the holder answers
     * @throws EmptyValueException When the display name is empty
     * @throws InvalidFormatException When the proven identifier is neither an address nor a number
     * @throws InvalidArgumentException When the hand-off frame cannot be named or queued
     * @throws HilosException When the account, identity, project bookkeeping, or reservation write fails
     */
    public function land(ActingSession $acting, string $identifier, string $displayName, ?string $plainPassword): ?AuthFlowOutcome
    {
        return $this->landRegistration($acting, $identifier, $displayName, $plainPassword);
    }
}

/**
 * A runtime context whose only row is the socket the confirm cases submit from: what
 * {@see AbstractLibraryCommands::acting()} resolves a browser with.
 */
final class RegistrationLandingTestRtContext extends RtContext
{
    public function configure(): void
    {
        $connections = RegistrationLandingTestConnections::init();
        $connections->add(RegistrationLandingTestConnection::create(
            'accept-key-of-the-landing-browser',
            null,
            'registration-landing-test-session-token',
        ));
        $this->_stateCollections[RegistrationLandingTestConnections::RT_COLLECTION] = $connections;
    }
}

/**
 * The project's session-stage connection rows, standing in for a real project's.
 */
final class RegistrationLandingTestConnections extends HilosSessionConnections
{
    /** @var string Runtime collection name this fixture mounts under */
    public const string RT_COLLECTION = 'registrationLandingTestConnections';

    public const string STATE_CLASS = RegistrationLandingTestConnection::class;
}

/**
 * One such row, with nothing of a project's own on it.
 */
final class RegistrationLandingTestConnection extends HilosSessionConnection
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
     * @return array<string, mixed> Own fields, of which this fixture has none
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
