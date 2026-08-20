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
 * What a proof with no hold behind it earns its account (HIL-608).
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
        /** @var ObjectRegistrationReservations $collection */
        $collection = Hilos::$db?->getObjectCollection(HilosDbContext::registrationReservations);
        $collection->createReservation($type, self::SESSION_TOKEN, $identifier, null, self::EXPIRED_BY_SECONDS);
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
