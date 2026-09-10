<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Registration\RegistrationReservationService;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Object\Collection\RegistrationReservations as ObjectRegistrationReservations;
use Hilos\Hilos;
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
 */
final class RegistrationLandingIntegrationTest extends FrameworkIntegrationTestCase
{
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

    private ?DbContext $previousDb = null;

    private ?SignalRouter $previousSignalRouter = null;

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
    }

    /**
     * @throws HilosException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
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
