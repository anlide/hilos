<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Table\Mutation\TableMutationType;
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
use Hilos\Utils\Logger;

/**
 * One password per account, and what a merge does with two of them (HIL-692, HIL-713).
 *
 * A rule about the SHAPE of a table is only worth what the table says, so this runs
 * against a real one. Two things are pinned here that no mock could answer: that the
 * second password is refused by the write itself, whichever address it arrives on, and
 * what happens to the password that did not survive a merge. That second one turns on
 * whether the address was ever PROVEN. A proven one is demoted, not deleted - the
 * difference between "you have one password now" and "you have lost that address". An
 * unproven one is deleted, because a row nobody proved holds the address without being
 * able to open it, and a password on it could then be minted for any account at all.
 *
 * Either deletion is logged, so the log file is swapped for a temporary one the way
 * {@see VerificationConsumeLogIntegrationTest} does it, and the lines are read back.
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

    /** Temporary main log file the assertions read the written lines back from */
    private string $logFile = '';

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

        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-account-password-identity-log');
        Logger::setLogFile($this->logFile);
    }

    /**
     * @throws HilosException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        Logger::resetLogFile();
        if ($this->logFile !== '' && file_exists($this->logFile)) {
            unlink($this->logFile);
        }

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
     * The loser's password moves across and the survivor's unproven address goes with it.
     *
     * @throws HilosException When an identity query, write, or the merge fails
     */
    public function testKeepingTheLosersPasswordMovesItAndDropsTheSurvivorsUnprovenRow(): void
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
        self::assertNull(
            $this->identities()->findByIdentity(IdentityType::MAGIC_LINK, $survivorEmail),
            'An address nobody proved must not be left behind as a row that only holds it',
        );
    }

    /**
     * Neither secret survives and neither address was proven, so no row survives either.
     *
     * @throws HilosException When an identity query, write, or the merge fails
     */
    public function testKeepingNeitherUnprovenPasswordLeavesTheAccountWithNoRowsAtAll(): void
    {
        $survivorId = $this->nextUserId();
        $loserId = $this->nextUserId();
        $survivorEmail = $this->uniqueEmail();
        $loserEmail = $this->uniqueEmail();
        $this->identities()->createPasswordIdentity($survivorId, $survivorEmail, self::PASSWORD);
        $this->identities()->createPasswordIdentity($loserId, $loserEmail, self::OTHER_PASSWORD);

        $this->identities()->rePointToUser($loserId, $survivorId, PasswordFate::NONE);

        self::assertNull($this->identities()->findPasswordByUser($survivorId));
        self::assertCount(0, $this->identities()->listByUser($survivorId));
        foreach ([$survivorEmail, $loserEmail] as $email) {
            self::assertNull($this->identities()->findByIdentity(IdentityType::MAGIC_LINK, $email));
        }
    }

    /**
     * The same merge over PROVEN addresses: both stay, as the rule HIL-692 wrote says.
     *
     * @throws HilosException When an identity query, write, or the merge fails
     */
    public function testKeepingNeitherProvenPasswordLeavesBothAddressesAndNoSecret(): void
    {
        $survivorId = $this->nextUserId();
        $loserId = $this->nextUserId();
        $survivorEmail = $this->uniqueEmail();
        $loserEmail = $this->uniqueEmail();
        $this->identities()->createPasswordIdentity($survivorId, $survivorEmail, self::PASSWORD)->markVerified();
        $this->identities()->createPasswordIdentity($loserId, $loserEmail, self::OTHER_PASSWORD)->markVerified();

        $this->identities()->rePointToUser($loserId, $survivorId, PasswordFate::NONE);

        self::assertNull($this->identities()->findPasswordByUser($survivorId));
        self::assertCount(2, $this->identities()->listByUser($survivorId));
        foreach ([$survivorEmail, $loserEmail] as $email) {
            $demoted = $this->identities()->findByIdentity(IdentityType::MAGIC_LINK, $email);
            self::assertNotNull($demoted, 'A proven address stays the person\'s, as a row of another type');
            self::assertSame($survivorId, $demoted->userId);
            self::assertNull($this->readSecret($demoted), 'A demoted row must carry no secret at all');
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
     * The demoted row is proven here on purpose: an unproven one would be dropped for
     * the other reason before the duplicate is ever looked for, and this case is about
     * the duplicate.
     *
     * @throws HilosException When an identity query, write, or the merge fails
     */
    public function testADemotionOntoAnExistingLinkAddressDropsTheRowInstead(): void
    {
        $survivorId = $this->nextUserId();
        $loserId = $this->nextUserId();
        $shared = $this->uniqueEmail();
        $this->identities()->createPasswordIdentity($survivorId, $this->uniqueEmail(), self::PASSWORD);
        $this->identities()->createPasswordIdentity($loserId, $shared, self::OTHER_PASSWORD)->markVerified();
        $this->seedMagicLinkRow($survivorId, $shared);

        $this->identities()->rePointToUser($loserId, $survivorId, PasswordFate::SURVIVOR);

        self::assertNull($this->identities()->findByIdentity(IdentityType::PASSWORD, $shared));
        self::assertSame(
            $survivorId,
            $this->identities()->findByIdentity(IdentityType::MAGIC_LINK, $shared)?->userId,
        );

        $written = $this->log();
        self::assertStringContainsString('Account merge deleted an identity row instead of demoting it', $written);
        self::assertStringContainsString('"reason":"magic_link_exists"', $written);
        self::assertStringContainsString('"identifier":"' . $shared . '"', $written);
    }

    /**
     * The hole the leaf closes: the freed address is free, for anybody, including a stranger.
     *
     * Before this, the unproven row stayed as a `magic_link` nobody could sign in with,
     * while (password, address) was still vacant - so the address answered "taken" to a
     * person and "free" to the write, and the write is the one that decides.
     *
     * Which is why the row itself is what the first assertion is about. The two reads
     * below it answered exactly this way while the hole was open: an unverified row of
     * another type is not an account to {@see ObjectIdentities::findAccountIdByEmail()}, and the
     * write only ever looked for a (password, address) row. Only "no row mentions it at
     * all" tells the freed address apart from the half-taken one.
     *
     * @throws HilosException When an identity query, write, or the merge fails
     */
    public function testAnAddressFreedByTheMergeIsFreeForAnotherAccountToTake(): void
    {
        $survivorId = $this->nextUserId();
        $loserId = $this->nextUserId();
        $strangerId = $this->nextUserId();
        $survivorEmail = $this->uniqueEmail();
        $this->identities()->createPasswordIdentity($survivorId, $survivorEmail, self::PASSWORD);
        $this->identities()->createPasswordIdentity($loserId, $this->uniqueEmail(), self::OTHER_PASSWORD);

        $this->identities()->rePointToUser($loserId, $survivorId, PasswordFate::LOSER);

        self::assertNull(
            $this->identities()->findUserIdByEmail($survivorEmail),
            'A freed address must be mentioned by no row at all, not by one nobody can use',
        );
        self::assertNull($this->identities()->findAccountIdByEmail($survivorEmail));
        self::assertSame(
            $strangerId,
            $this->identities()->createPasswordIdentity($strangerId, $survivorEmail, self::PASSWORD)->userId,
        );
    }

    /**
     * The line the leaf was written for: a deleted sign-in row nobody asked to delete.
     *
     * @throws HilosException When an identity query, write, or the merge fails
     */
    public function testDeletingAnUnprovenRowIsWrittenDownWithItsAddressAndBothAccounts(): void
    {
        $survivorId = $this->nextUserId();
        $loserId = $this->nextUserId();
        $survivorEmail = $this->uniqueEmail();
        $doomedId = $this->identities()->createPasswordIdentity($survivorId, $survivorEmail, self::PASSWORD)->id;
        $this->identities()->createPasswordIdentity($loserId, $this->uniqueEmail(), self::OTHER_PASSWORD);

        $this->identities()->rePointToUser($loserId, $survivorId, PasswordFate::LOSER);

        $written = $this->log();
        self::assertStringContainsString('Account merge deleted an identity row instead of demoting it', $written);
        self::assertStringContainsString('"reason":"unverified"', $written);
        self::assertStringContainsString('"identityId":' . $doomedId, $written);
        self::assertStringContainsString('"identifier":"' . $survivorEmail . '"', $written);
        self::assertStringContainsString('"ownerUserId":' . $survivorId, $written);
        self::assertStringContainsString('"fromUserId":' . $loserId, $written);
        self::assertStringContainsString('"toUserId":' . $survivorId, $written);
    }

    /**
     * A demotion that kept the row says nothing: the line is about a row that went away.
     *
     * @throws HilosException When an identity query, write, or the merge fails
     */
    public function testADemotionThatKeptTheRowIsNotWrittenDownAsADeletion(): void
    {
        $survivorId = $this->nextUserId();
        $loserId = $this->nextUserId();
        $this->identities()->createPasswordIdentity($survivorId, $this->uniqueEmail(), self::PASSWORD)->markVerified();
        $this->identities()->createPasswordIdentity($loserId, $this->uniqueEmail(), self::OTHER_PASSWORD);

        $this->identities()->rePointToUser($loserId, $survivorId, PasswordFate::LOSER);

        self::assertStringNotContainsString(
            'Account merge deleted an identity row instead of demoting it',
            $this->log(),
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
     * Marking a row verified is announced like any other write to it (HIL-299).
     *
     * Registration creates the password row unproven and flips it a moment later. The
     * creation is announced, so a flip that is not leaves every other reader - the worker
     * that draws the profile among them - holding the row as unproven for good: the
     * account shows "Unverified" and no Email row, although the table says otherwise.
     *
     * @throws HilosException When an identity query or write fails
     */
    public function testMarkingARowVerifiedIsAnnouncedToItsReaders(): void
    {
        $identity = $this->identities()->createPasswordIdentity($this->nextUserId(), $this->uniqueEmail(), self::PASSWORD);

        /** @var list<SourceChange> $announced */
        $announced = [];
        SourceChangeBus::subscribe(new class ($announced) implements SourceChangeSubscriberInterface {
            /**
             * @param list<SourceChange> $announced Sink the case reads
             */
            public function __construct(private array &$announced)
            {
            }

            public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
            {
                $this->announced[] = $change;
            }
        });

        try {
            $identity->markVerified();
        } finally {
            SourceChangeBus::reset();
        }

        $updates = array_values(array_filter(
            $announced,
            static fn (SourceChange $change): bool => $change->mutationType === TableMutationType::Update
                && $change->sourceKey === HilosDbContext::identities
                && $change->sourceId === (string)$identity->id,
        ));
        self::assertCount(1, $updates, 'the flip to verified must reach the readers of the row');
        self::assertTrue((bool)($updates[0]->row[EntityIdentity::verified] ?? false));
    }

    /**
     * An email change moves the password row and the link row of that address, both proven (HIL-299).
     *
     * The password row starts unproven on purpose: the new address has just answered its
     * code, so whatever the old row said about the old address, the new one is proven.
     *
     * @throws HilosException When an identity query or write fails
     */
    public function testAnEmailChangeMovesThePasswordAndLinkRowsOfThatAddressAsProven(): void
    {
        $userId = $this->nextUserId();
        $from = $this->uniqueEmail();
        $to = $this->uniqueEmail();
        $this->identities()->createPasswordIdentity($userId, $from, self::PASSWORD);
        $this->seedMagicLinkRow($userId, $from);

        self::assertSame(2, $this->identities()->changeEmail($userId, $from, $to));

        self::assertSame(
            [
                [IdentityType::PASSWORD, $to, true],
                [IdentityType::MAGIC_LINK, $to, true],
            ],
            self::rowsOf($userId),
        );
        self::assertTrue($this->identities()->findPasswordByUser($userId)?->verifyPassword(self::PASSWORD));
    }

    /**
     * Rows of another address, and the rows that are not an email at all, stay as they were.
     *
     * @throws HilosException When an identity query or write fails
     */
    public function testAnEmailChangeLeavesOtherAddressesAndNonEmailRowsAlone(): void
    {
        $userId = $this->nextUserId();
        $from = $this->uniqueEmail();
        $to = $this->uniqueEmail();
        $other = $this->uniqueEmail();
        $this->seedMagicLinkRow($userId, $from);
        $this->seedMagicLinkRow($userId, $other);
        $this->seedRow($userId, '+48500100200', IdentityType::SMS, null);
        $this->seedRow($userId, 'google:' . $from, IdentityType::OAUTH, null);

        self::assertSame(1, $this->identities()->changeEmail($userId, $from, $to));

        self::assertSame(
            [
                [IdentityType::MAGIC_LINK, $to, true],
                [IdentityType::MAGIC_LINK, $other, true],
                [IdentityType::SMS, '+48500100200', true],
                [IdentityType::OAUTH, 'google:' . $from, true],
            ],
            self::rowsOf($userId),
        );
    }

    /**
     * An address another account holds is refused before any row of this account is written.
     *
     * The other account holds the address only as a link row, so the password row - checked
     * first and free of any clash of its own - would already be rewritten by a method that
     * wrote as it checked.
     *
     * @throws HilosException When an identity query or write fails
     */
    public function testAnAddressAnotherAccountHoldsIsRefusedBeforeAnyRowIsWritten(): void
    {
        $userId = $this->nextUserId();
        $from = $this->uniqueEmail();
        $to = $this->uniqueEmail();
        $this->seedPasswordRow($userId, $from);
        $this->seedMagicLinkRow($userId, $from);
        $this->seedMagicLinkRow($this->nextUserId(), $to);

        try {
            $this->identities()->changeEmail($userId, $from, $to);
            self::fail('an address another account holds must be refused');
        } catch (DuplicateValueException) {
        }

        self::assertSame(
            [
                [IdentityType::PASSWORD, $from, true],
                [IdentityType::MAGIC_LINK, $from, true],
            ],
            self::rowsOf($userId),
        );
    }

    /**
     * @return string Everything written to the log so far in this case
     */
    private function log(): string
    {
        return (string)file_get_contents($this->logFile);
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
     * Reads an account's rows straight from the table, past the object cache the write went through.
     *
     * @param int $userId Owning user id
     * @return list<array{0: string, 1: string, 2: bool}> Type, identifier and proven flag of each row, by id
     * @throws HilosException When the query fails
     */
    private static function rowsOf(int $userId): array
    {
        Database::sql(
            'SELECT `' . EntityIdentity::type . '`, `' . EntityIdentity::identifier . '`, `' . EntityIdentity::verified . '`'
            . ' FROM `' . EntityIdentity::_table . '` WHERE `' . EntityIdentity::user_id . '` = ? ORDER BY `' . EntityIdentity::id . '`',
            [$userId],
        );

        return array_map(
            static fn (array $row): array => [
                (string)$row[EntityIdentity::type],
                (string)$row[EntityIdentity::identifier],
                (bool)$row[EntityIdentity::verified],
            ],
            Database::rows(),
        );
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
