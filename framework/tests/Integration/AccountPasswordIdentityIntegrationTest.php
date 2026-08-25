<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\Object\Collection\Identities as ObjectIdentities;
use Hilos\Database\Object\Item\Identity as ObjectIdentity;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * One password per account, and what a merge does with two of them (HIL-692).
 *
 * A rule about the SHAPE of a table is only worth what the table says, so this runs
 * against a real one. Two things are pinned here that no mock could answer: that the
 * second password is refused by the write itself, whichever address it arrives on, and
 * that a password which did not survive a merge stops being a credential while its
 * address stays the person's - a demoted row, not a deleted one, is the difference
 * between "you have one password now" and "you have lost that address".
 *
 * The secret is read back with a query of its own because the column is deliberately
 * not ORM-mapped: nothing on the object surface could show whether an erase happened.
 */
final class AccountPasswordIdentityIntegrationTest extends FrameworkIntegrationTestCase
{
    /** @var list<string> Framework tables this case needs */
    private const array TABLES = ['hilos_identity'];

    private const string PASSWORD = 'survivor-secret-42';

    private const string OTHER_PASSWORD = 'loser-secret-42';

    /** Precomputed so a case that seeds several rows does not pay bcrypt for each. */
    private const string SEED_HASH = '$2y$04$T9nQ3nfKGN1BFq0dPO3vQ.6ZUJqQF/2sPq6xCEkQwvPqZ6.9dJ0Aq';

    private ?DbContext $previousDb = null;

    private ?SignalRouter $previousSignalRouter = null;

    /** @var int Rolling source of user ids; a framework table carries no FK to a project user */
    private int $nextUserId = 1;

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

        $db = new AccountPasswordTestDbContext();
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
     * A second password on a second address is refused by the write, not by the caller.
     *
     * @throws HilosException When an identity query or write fails
     */
    public function testAnAccountIsRefusedASecondPasswordOnAnotherAddress(): void
    {
        $userId = $this->nextUserId();
        $this->identities()->createPasswordIdentity($userId, $this->uniqueEmail(), self::PASSWORD);

        $this->expectException(DuplicateValueException::class);
        $this->identities()->createPasswordIdentity($userId, $this->uniqueEmail(), self::OTHER_PASSWORD);
    }

    /**
     * The address of somebody else's password is still refused by its own guard.
     *
     * @throws HilosException When an identity query or write fails
     */
    public function testATakenAddressIsStillRefusedToAnAccountWithNoPassword(): void
    {
        $email = $this->uniqueEmail();
        $this->identities()->createPasswordIdentity($this->nextUserId(), $email, self::PASSWORD);

        $this->expectException(DuplicateValueException::class);
        $this->identities()->createPasswordIdentity($this->nextUserId(), $email, self::OTHER_PASSWORD);
    }

    /**
     * The account's password is found by the account, and an account without one says so.
     *
     * @throws HilosException When an identity query or write fails
     */
    public function testThePasswordIsFoundByAccountAndAbsenceIsAnAnswer(): void
    {
        $userId = $this->nextUserId();
        $email = $this->uniqueEmail();
        $this->identities()->createPasswordIdentity($userId, $email, self::PASSWORD);

        $found = $this->identities()->findPasswordByUser($userId);
        self::assertNotNull($found);
        self::assertSame($email, $found->identifier);
        self::assertNull($this->identities()->findPasswordByUser($this->nextUserId()));
    }

    /**
     * Data older than the rule reads as exactly one secret, deterministically the first.
     *
     * @throws HilosException When an identity query or write fails
     */
    public function testAnAccountCarryingTwoPasswordsIsReadAsTheLowestIdOne(): void
    {
        $userId = $this->nextUserId();
        $first = $this->seedPasswordRow($userId, $this->uniqueEmail());
        $this->seedPasswordRow($userId, $this->uniqueEmail());

        self::assertSame($first, $this->identities()->findPasswordByUser($userId)?->id);
    }

    /**
     * Only two passwords force a choice; one or none is decided by the merge itself.
     *
     * @throws HilosException When an identity query or write fails
     */
    public function testOnlyTwoPasswordsMakeTheFateNecessary(): void
    {
        $survivorId = $this->nextUserId();
        $loserId = $this->nextUserId();
        $strangerId = $this->nextUserId();
        $this->identities()->createPasswordIdentity($survivorId, $this->uniqueEmail(), self::PASSWORD);

        self::assertFalse($this->identities()->passwordFateNeeded($loserId, $survivorId));

        $this->identities()->createPasswordIdentity($loserId, $this->uniqueEmail(), self::OTHER_PASSWORD);

        self::assertTrue($this->identities()->passwordFateNeeded($loserId, $survivorId));
        self::assertFalse($this->identities()->passwordFateNeeded($strangerId, $strangerId));
    }

    /**
     * The survivor keeps its password and the loser's address arrives without a secret.
     *
     * @throws HilosException When an identity query, write, or the merge fails
     */
    public function testKeepingTheSurvivorsPasswordLeavesTheLosersAddressAsALinkAddress(): void
    {
        $survivorId = $this->nextUserId();
        $loserId = $this->nextUserId();
        $survivorEmail = $this->uniqueEmail();
        $loserEmail = $this->uniqueEmail();
        $this->identities()->createPasswordIdentity($survivorId, $survivorEmail, self::PASSWORD);
        $this->identities()->createPasswordIdentity($loserId, $loserEmail, self::OTHER_PASSWORD)->markVerified();

        $this->identities()->rePointToUser($loserId, $survivorId, PasswordFate::SURVIVOR);

        $kept = $this->identities()->findPasswordByUser($survivorId);
        self::assertSame($survivorEmail, $kept?->identifier);
        self::assertTrue($kept?->verifyPassword(self::PASSWORD));

        $demoted = $this->identities()->findByIdentity(IdentityType::MAGIC_LINK, $loserEmail);
        self::assertNotNull($demoted, 'The address must stay the person\'s, as a row of another type');
        self::assertSame($survivorId, $demoted->userId);
        self::assertTrue($demoted->verified, 'A proven address stays proven through the demotion');
        self::assertNull($this->readSecret($demoted), 'A demoted row must carry no secret at all');
    }

    /**
     * The loser's password moves across and the survivor's own is the one demoted.
     *
     * @throws HilosException When an identity query, write, or the merge fails
     */
    public function testKeepingTheLosersPasswordMovesItAndDemotesTheSurvivors(): void
    {
        $survivorId = $this->nextUserId();
        $loserId = $this->nextUserId();
        $survivorEmail = $this->uniqueEmail();
        $loserEmail = $this->uniqueEmail();
        $this->identities()->createPasswordIdentity($survivorId, $survivorEmail, self::PASSWORD);
        $this->identities()->createPasswordIdentity($loserId, $loserEmail, self::OTHER_PASSWORD);

        $this->identities()->rePointToUser($loserId, $survivorId, PasswordFate::LOSER);

        $kept = $this->identities()->findPasswordByUser($survivorId);
        self::assertSame($loserEmail, $kept?->identifier);
        self::assertTrue($kept?->verifyPassword(self::OTHER_PASSWORD));
        self::assertNotNull($this->identities()->findByIdentity(IdentityType::MAGIC_LINK, $survivorEmail));
    }

    /**
     * Neither secret survives, and the person keeps both addresses to set one anew with.
     *
     * @throws HilosException When an identity query, write, or the merge fails
     */
    public function testKeepingNeitherPasswordLeavesTheAccountWithBothAddressesAndNoSecret(): void
    {
        $survivorId = $this->nextUserId();
        $loserId = $this->nextUserId();
        $survivorEmail = $this->uniqueEmail();
        $loserEmail = $this->uniqueEmail();
        $this->identities()->createPasswordIdentity($survivorId, $survivorEmail, self::PASSWORD);
        $this->identities()->createPasswordIdentity($loserId, $loserEmail, self::OTHER_PASSWORD);

        $this->identities()->rePointToUser($loserId, $survivorId, PasswordFate::NONE);

        self::assertNull($this->identities()->findPasswordByUser($survivorId));
        self::assertCount(2, $this->identities()->listByUser($survivorId));
        foreach ([$survivorEmail, $loserEmail] as $email) {
            self::assertNotNull($this->identities()->findByIdentity(IdentityType::MAGIC_LINK, $email));
        }
    }

    /**
     * A fate naming an account that has no password is an outcome, not bad input.
     *
     * @throws HilosException When an identity query, write, or the merge fails
     */
    public function testNamingAnAccountWithoutAPasswordSimplyLeavesNone(): void
    {
        $survivorId = $this->nextUserId();
        $loserId = $this->nextUserId();
        $this->identities()->createPasswordIdentity($loserId, $this->uniqueEmail(), self::OTHER_PASSWORD);

        $this->identities()->rePointToUser($loserId, $survivorId, PasswordFate::SURVIVOR);

        self::assertNull($this->identities()->findPasswordByUser($survivorId));
    }

    /**
     * The one password of the two accounts survives a merge nobody had to decide.
     *
     * @throws HilosException When an identity query, write, or the merge fails
     */
    public function testTheOnlyPasswordSurvivesAMergeWithNoFateNamed(): void
    {
        $survivorId = $this->nextUserId();
        $loserId = $this->nextUserId();
        $loserEmail = $this->uniqueEmail();
        $this->identities()->createPasswordIdentity($loserId, $loserEmail, self::OTHER_PASSWORD);

        $this->identities()->rePointToUser($loserId, $survivorId, null);

        self::assertSame($loserEmail, $this->identities()->findPasswordByUser($survivorId)?->identifier);
    }

    /**
     * Merging two passworded accounts with nothing named is a caller's mistake, and refused.
     *
     * @throws HilosException When an identity query or write fails
     */
    public function testTwoPasswordsWithNoFateNamedRefuseToMerge(): void
    {
        $survivorId = $this->nextUserId();
        $loserId = $this->nextUserId();
        $this->identities()->createPasswordIdentity($survivorId, $this->uniqueEmail(), self::PASSWORD);
        $this->identities()->createPasswordIdentity($loserId, $this->uniqueEmail(), self::OTHER_PASSWORD);

        $this->expectException(LogicException::class);
        $this->identities()->rePointToUser($loserId, $survivorId, null);
    }

    /**
     * An address the survivor already reaches by link keeps the row it already has.
     *
     * @throws HilosException When an identity query, write, or the merge fails
     */
    public function testADemotionOntoAnExistingLinkAddressDropsTheRowInstead(): void
    {
        $survivorId = $this->nextUserId();
        $loserId = $this->nextUserId();
        $shared = $this->uniqueEmail();
        $this->identities()->createPasswordIdentity($survivorId, $this->uniqueEmail(), self::PASSWORD);
        $this->identities()->createPasswordIdentity($loserId, $shared, self::OTHER_PASSWORD);
        $this->seedMagicLinkRow($survivorId, $shared);

        $this->identities()->rePointToUser($loserId, $survivorId, PasswordFate::SURVIVOR);

        self::assertNull($this->identities()->findByIdentity(IdentityType::PASSWORD, $shared));
        self::assertSame(
            $survivorId,
            $this->identities()->findByIdentity(IdentityType::MAGIC_LINK, $shared)?->userId,
        );
    }

    /**
     * The scenario the leaf exists for: after the merge either address finds the one secret.
     *
     * @throws HilosException When an identity query, write, or the merge fails
     */
    public function testEitherAddressResolvesToTheAccountAndItsOnePassword(): void
    {
        $survivorId = $this->nextUserId();
        $loserId = $this->nextUserId();
        $survivorEmail = $this->uniqueEmail();
        $loserEmail = $this->uniqueEmail();
        $this->identities()->createPasswordIdentity($survivorId, $survivorEmail, self::PASSWORD);
        $this->identities()->createPasswordIdentity($loserId, $loserEmail, self::OTHER_PASSWORD)->markVerified();

        $this->identities()->rePointToUser($loserId, $survivorId, PasswordFate::SURVIVOR);

        foreach ([$survivorEmail, $loserEmail] as $email) {
            $accountId = $this->identities()->findAccountIdByEmail($email);
            self::assertSame($survivorId, $accountId, "{$email} must name the surviving account");
            self::assertTrue(
                $this->identities()->findPasswordByUser($survivorId)?->verifyPassword(self::PASSWORD),
                "signing in through {$email} must check the account's one secret",
            );
        }
    }

    /**
     * Reads the secret column of one identity, which no object surface exposes.
     *
     * @param ObjectIdentity $identity Identity whose stored secret to read
     * @return ?string Stored hash, or null when the row carries none
     * @throws HilosException When the secret query fails
     */
    private function readSecret(ObjectIdentity $identity): ?string
    {
        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int((int)$identity->id));
        $row = Database::sql(
            'SELECT `' . EntityIdentity::secret . '` FROM `' . EntityIdentity::_table . '`'
            . ' WHERE `' . EntityIdentity::id . '` = ?',
            $params,
        )->first()?->first();

        $secret = $row[EntityIdentity::secret] ?? null;

        return is_string($secret) ? $secret : null;
    }

    /**
     * Writes a password row straight to the table, past the guard this case is testing.
     *
     * @param int $userId Owning user id
     * @param string $email Lowercased address the row carries
     * @return int Id of the written row
     * @throws HilosException When the insert fails
     */
    private function seedPasswordRow(int $userId, string $email): int
    {
        return $this->seedRow($userId, $email, IdentityType::PASSWORD, self::SEED_HASH);
    }

    /**
     * Writes a verified `magic_link` row straight to the table.
     *
     * @param int $userId Owning user id
     * @param string $email Lowercased address the row carries
     * @return int Id of the written row
     * @throws HilosException When the insert fails
     */
    private function seedMagicLinkRow(int $userId, string $email): int
    {
        return $this->seedRow($userId, $email, IdentityType::MAGIC_LINK, null);
    }

    /**
     * Writes one identity row directly, so a case can build a state the layer refuses to.
     *
     * @param int $userId Owning user id
     * @param string $email Lowercased address the row carries
     * @param string $type Identity type (see IdentityType)
     * @param ?string $secret Stored hash, or null for a row that carries none
     * @return int Id of the written row
     * @throws HilosException When the insert fails
     */
    private function seedRow(int $userId, string $email, string $type, ?string $secret): int
    {
        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int($userId));
        $params->add(SqlParam::string($type));
        $params->add(SqlParam::string($email));
        $params->add(SqlParam::auto($secret));
        Database::sql(
            'INSERT INTO `' . EntityIdentity::_table . '`'
            . ' (`' . EntityIdentity::user_id . '`, `' . EntityIdentity::type . '`,'
            . ' `' . EntityIdentity::identifier . '`, `' . EntityIdentity::secret . '`,'
            . ' `' . EntityIdentity::verified . '`) VALUES (?, ?, ?, ?, 1)',
            $params,
        );

        return Database::lastInsertId();
    }

    /**
     * @return ObjectIdentities Identity persistence primitives
     * @throws HilosException When the collection is unavailable
     */
    private function identities(): ObjectIdentities
    {
        /** @var ObjectIdentities $collection */
        $collection = Hilos::$db?->getObjectCollection(HilosDbContext::identities);

        return $collection;
    }

    /**
     * @return int A user id no other account in this case uses
     */
    private function nextUserId(): int
    {
        return $this->nextUserId++;
    }

    /**
     * @return string Unique lowercase address for one account
     */
    private function uniqueEmail(): string
    {
        return RandomHelper::hex(8) . '@example.test';
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
 * The identity layer is framework-owned and reads one framework table, so the smallest
 * honest context for it is {@see HilosDbContext} with no project collections.
 */
final class AccountPasswordTestDbContext extends HilosDbContext
{
}
