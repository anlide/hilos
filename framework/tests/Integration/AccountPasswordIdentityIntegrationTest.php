<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Object\Collection\Identities as ObjectIdentities;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Utils\Helpers\RandomHelper;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Identity persistence, the one-password constraint, proven addresses and password moments.
 * Merge choices and their deletion logs are exercised through the live owners by
 * {@see AccountMergeCoordinatorIntegrationTest::testPasswordChoicesKeepMomentsAddressesAndDeletionLogs()}.
 */
final class AccountPasswordIdentityIntegrationTest extends FrameworkIntegrationTestCase
{
    /** @var list<string> Framework tables this case needs */
    private const array TABLES = [
        'hilos_user',
        'hilos_identity',
        'hilos_account_merge_fence',
    ];

    private const string PASSWORD = 'survivor-secret-42';

    private const string OTHER_PASSWORD = 'loser-secret-42';

    /** Precomputed so a case that seeds several rows does not pay bcrypt for each. */
    private const string SEED_HASH = '$2y$04$T9nQ3nfKGN1BFq0dPO3vQ.6ZUJqQF/2sPq6xCEkQwvPqZ6.9dJ0Aq';

    private ?DbContext $previousDb = null;

    private ?SignalRouter $previousSignalRouter = null;

    /** @var int Rolling source of user ids; ids now have a person row behind them */
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

    /** Both plaintext creation and the hash-seed entrance persist a moment at birth. */
    public function testPasswordCreationPersistsItsMomentBeforeAnnouncingTheRow(): void
    {
        foreach ([false, true] as $prehashed) {
            $before = TimeHelper::nowMs();
            $identity = $prehashed
                ? $this->identities()->createPasswordIdentityWithHash($this->nextUserId(), $this->uniqueEmail(), self::SEED_HASH)
                : $this->identities()->createPasswordIdentity($this->nextUserId(), $this->uniqueEmail(), self::PASSWORD);
            $after = TimeHelper::nowMs();

            self::assertIsInt($identity->valueForColumn(EntityIdentity::created_at));
            self::assertSame(
                $identity->valueForColumn(EntityIdentity::created_at),
                $identity->valueForColumn(EntityIdentity::updated_at),
            );
            $stored = Database::sql('SELECT `created_at`, `updated_at` FROM `hilos_identity` WHERE `id` = ?', [$identity->id])->row();
            self::assertGreaterThanOrEqual($before, (int)$stored['created_at']);
            self::assertLessThanOrEqual($after, (int)$stored['created_at']);
            self::assertSame($identity->valueForColumn(EntityIdentity::created_at), (int)$stored['created_at']);
            self::assertSame($stored['created_at'], $stored['updated_at']);

            self::assertGreaterThanOrEqual($before, $identity->passwordChangedAt);
            self::assertLessThanOrEqual(TimeHelper::nowMs(), $identity->passwordChangedAt);
            self::assertSame(
                $identity->passwordChangedAt,
                (int)Database::sql('SELECT `password_changed_at` FROM `hilos_identity` WHERE `id` = ?', [$identity->id])
                    ->rows()[0]['password_changed_at'],
            );

            $created = [];
            while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
                if ($frame->data instanceof DbSyncCreatedSignalData && $frame->data->collectionKey === HilosDbContext::identities) {
                    $created[] = $frame->data;
                }
            }
            self::assertCount(1, $created);
            self::assertSame($identity->passwordChangedAt, $created[0]->row[EntityIdentity::password_changed_at]);
            self::assertSame((int)$stored['created_at'], $created[0]->row[EntityIdentity::created_at]);
            self::assertSame((int)$stored['updated_at'], $created[0]->row[EntityIdentity::updated_at]);
        }
    }

    /** @return iterable<string, array{string}> Each mapped or secret-only update entrance */
    public static function momentUpdateOperations(): iterable
    {
        yield 'password change' => ['recordPasswordChange'];
        yield 'verification' => ['markVerified'];
        yield 'password hash' => ['setPasswordHash'];
        yield 'password removal' => ['clearPassword'];
    }

    /** @param string $operation Update entrance exercised against a fixed old stamp */
    #[DataProvider('momentUpdateOperations')]
    public function testUpdatesStampTheIdentityWithoutChangingItsCreationMoment(string $operation): void
    {
        $id = $this->seedPasswordRow($this->nextUserId(), $this->uniqueEmail());
        Database::sqlRun(
            'UPDATE `hilos_identity` SET `verified` = 0, `created_at` = 1735689600123, `updated_at` = 1735689600456 WHERE `id` = ?',
            [$id],
        );
        $identity = $this->identities()->get($id);
        self::assertNotNull($identity);

        $before = TimeHelper::nowMs();
        match ($operation) {
            'recordPasswordChange' => Hilos::$db->identities[$id]->actions->recordPasswordChange(),
            'markVerified' => $identity->markVerified(),
            'setPasswordHash' => $identity->setPasswordHash('replacement-hash'),
            'clearPassword' => $identity->clearPassword(),
        };
        $after = TimeHelper::nowMs();

        $stored = Database::sql('SELECT * FROM `hilos_identity` WHERE `id` = ?', [$id])->row();
        self::assertSame(1735689600123, (int)$stored['created_at']);
        self::assertGreaterThanOrEqual($before, (int)$stored['updated_at']);
        self::assertLessThanOrEqual($after, (int)$stored['updated_at']);
        self::assertSame((int)$stored['updated_at'], $identity->valueForColumn(EntityIdentity::updated_at));
        self::assertSame([], $identity->getChangedColumns(), 'A secret write must not leave a pending stamp for a later sync');
        match ($operation) {
            'recordPasswordChange' => self::assertGreaterThanOrEqual($before, (int)$stored['password_changed_at']),
            'markVerified' => self::assertSame(1, (int)$stored['verified']),
            'setPasswordHash' => self::assertSame('replacement-hash', $stored['secret']),
            'clearPassword' => self::assertNull($stored['secret']),
        };
    }

    /** A read and an empty sync leave a genuinely old, fractional stamp intact. */
    public function testEmptySyncDoesNotAdvanceTheIdentityMoment(): void
    {
        $id = $this->seedPasswordRow($this->nextUserId(), $this->uniqueEmail());
        Database::sqlRun('UPDATE `hilos_identity` SET `updated_at` = 1735689600456 WHERE `id` = ?', [$id]);
        $identity = $this->identities()->get($id);
        self::assertNotNull($identity);

        $identity->sync();

        self::assertSame(1735689600456, $identity->valueForColumn(EntityIdentity::updated_at));
        self::assertSame(1735689600456, (int)Database::sql('SELECT `updated_at` FROM `hilos_identity` WHERE `id` = ?', [$id])
            ->field('updated_at'));
    }

    /** @return iterable<string, array{int}> Signed millisecond values, including the legacy TIMESTAMP boundary */
    public static function storedPasswordMoments(): iterable
    {
        yield 'epoch' => [0];
        yield 'before epoch' => [-1];
        yield 'after 2038' => [2147483648123];
        yield 'fractional second' => [1735689600123];
    }

    /** @param int $moment Persisted milliseconds the cold ORM must preserve exactly */
    #[DataProvider('storedPasswordMoments')]
    public function testReadsSignedPasswordMillisecondsWithoutRescaling(int $moment): void
    {
        $id = $this->seedPasswordRow($this->nextUserId(), $this->uniqueEmail());
        Database::sqlRun('UPDATE `hilos_identity` SET `password_changed_at` = ? WHERE `id` = ?', [$moment, $id]);

        self::assertSame($moment, Hilos::$db->identities[$id]->passwordChangedAt);
        self::assertSame($moment, $this->identities()->get($id)?->passwordChangedAt);
    }

    /** An old row without evidence of when its password was set stays unknown on read and verification. */
    public function testLegacyPasswordMomentStaysNull(): void
    {
        $id = $this->seedPasswordRow($this->nextUserId(), $this->uniqueEmail());
        Database::sqlRun('UPDATE `hilos_identity` SET `verified` = 0 WHERE `id` = ?', [$id]);

        self::assertNull(Hilos::$db->identities[$id]->passwordChangedAt);
        Hilos::$db->identities[$id]->markVerified();
        self::assertNull(Hilos::$db->identities[$id]->passwordChangedAt);
        self::assertNull(Database::sql('SELECT `password_changed_at` FROM `hilos_identity` WHERE `id` = ?', [$id])
            ->rows()[0]['password_changed_at']);
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
            . ' (`created_at`, `updated_at`, `' . EntityIdentity::user_id . '`, `' . EntityIdentity::type . '`,'
            . ' `' . EntityIdentity::identifier . '`, `' . EntityIdentity::secret . '`,'
            . ' `' . EntityIdentity::verified . '`) VALUES (1735689600000, 1735689600000, ?, ?, ?, ?, 1)',
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
     * @return int A persisted person id no other account in this case uses
     * @throws HilosException When the person row cannot be inserted
     */
    private function nextUserId(): int
    {
        $userId = $this->nextUserId++;
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, 'Fixture person')", [$userId]);

        return $userId;
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
        foreach ($down ? array_reverse(self::TABLES) : self::TABLES as $table) {
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
