<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users;

use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Database\View\Collection\HilosUserBlockSource;
use Hilos\Database\View\Item\DbItem;
use Hilos\Hilos;
use Hilos\Users\AccountBlockReader;
use Hilos\Users\Exception\UserBlockSourceMissingException;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * The framework's question "is this account blocked", asked of a source the project supplies (HIL-944).
 *
 * The project answers in bulk and nothing else; the single read is derived here, so the cases
 * pin both shapes off the one fixture source and pin what the reader does before the source is
 * ever reached - the ids that are no account, the empty list, the duplicates.
 *
 * The failures are pinned as refusals, not as a false: a block seam that answers "not blocked"
 * on a wiring gap lets the blocked account through, and says nothing about why.
 */
final class AccountBlockReaderTest extends TestCase
{
    /** @var int Account the fixture source reports as blocked */
    private const int BLOCKED_USER_ID = 7;

    /** @var int Account the fixture source reports as not blocked */
    private const int ACTIVE_USER_ID = 8;

    /** @var int Account the fixture source holds no row for */
    private const int GONE_USER_ID = 9;

    /** Temporary main log file, so the refusal case writes its journal line somewhere harmless */
    private string $logFile = '';

    protected function setUp(): void
    {
        AccountBlockReaderUsers::$asked = [];
        AccountBlockReaderUsers::$rows = [self::BLOCKED_USER_ID => true, self::ACTIVE_USER_ID => false];
        Hilos::$db = AccountBlockReaderDbContext::create(AccountBlockReaderUsers::class);

        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-account-block-reader');
        Logger::setLogFile($this->logFile);
    }

    protected function tearDown(): void
    {
        Hilos::$db = null;
        SourceInterestRegistry::readsWhatItMounts();

        Logger::resetLogFile();
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    public function testASingleReadAnswersTheAccountsOwnFlag(): void
    {
        $reader = new AccountBlockReader();

        $this->assertTrue($reader->isBlocked(self::BLOCKED_USER_ID));
        $this->assertFalse($reader->isBlocked(self::ACTIVE_USER_ID));
        $this->assertSame([[self::BLOCKED_USER_ID], [self::ACTIVE_USER_ID]], AccountBlockReaderUsers::$asked);
    }

    public function testABulkReadAnswersEveryRequestedIdInOneCall(): void
    {
        $answers = (new AccountBlockReader())->blockedAmong([self::ACTIVE_USER_ID, self::BLOCKED_USER_ID]);

        $this->assertSame([self::ACTIVE_USER_ID => false, self::BLOCKED_USER_ID => true], $answers);
        $this->assertSame([[self::ACTIVE_USER_ID, self::BLOCKED_USER_ID]], AccountBlockReaderUsers::$asked);
    }

    /**
     * A user that is gone is not a blocked account, and the caller iterating what it asked about
     * must not have to test every key.
     */
    public function testAnIdWithNoRowAnswersFalse(): void
    {
        $answers = (new AccountBlockReader())->blockedAmong([self::GONE_USER_ID]);

        $this->assertSame([self::GONE_USER_ID => false], $answers);
    }

    /**
     * Zero is the framework's "no user resolved" and a guest is no account: nothing to be blocked,
     * and nothing worth waking the source for - not even when no source exists.
     */
    public function testANonPositiveIdAnswersFalseWithoutReachingTheSource(): void
    {
        Hilos::$db = null;
        $reader = new AccountBlockReader();

        $this->assertFalse($reader->isBlocked(0));
        $this->assertSame([0 => false, -3 => false], $reader->blockedAmong([0, -3]));
    }

    public function testANonPositiveIdBesideARealOneIsNotPassedToTheSource(): void
    {
        $answers = (new AccountBlockReader())->blockedAmong([0, self::BLOCKED_USER_ID]);

        $this->assertSame([0 => false, self::BLOCKED_USER_ID => true], $answers);
        $this->assertSame([[self::BLOCKED_USER_ID]], AccountBlockReaderUsers::$asked);
    }

    public function testAnEmptyListAnswersAnEmptyMapWithoutTouchingTheDatabaseLayer(): void
    {
        Hilos::$db = null;

        $this->assertSame([], (new AccountBlockReader())->blockedAmong([]));
    }

    public function testDuplicatesCollapseIntoOneKeyAndOneAskedId(): void
    {
        $answers = (new AccountBlockReader())->blockedAmong([self::BLOCKED_USER_ID, self::BLOCKED_USER_ID]);

        $this->assertSame([self::BLOCKED_USER_ID => true], $answers);
        $this->assertSame([[self::BLOCKED_USER_ID]], AccountBlockReaderUsers::$asked);
    }

    public function testAProjectWithoutABlockSourceIsRefusedNotAnsweredFalse(): void
    {
        Hilos::$db = AccountBlockReaderDbContext::create(AccountBlockReaderPlainUsers::class);

        $this->expectException(UserBlockSourceMissingException::class);
        $this->expectExceptionMessage(
            'No user block source: ' . Hilos::appClass() . ' declares no database collection implementing '
            . HilosUserBlockSource::class . '; HilosFeature::HILOS_USERS requires one'
        );

        (new AccountBlockReader())->isBlocked(self::BLOCKED_USER_ID);
    }

    public function testAProcessWithoutADatabaseLayerIsRefusedNotAnsweredFalse(): void
    {
        Hilos::$db = null;

        $this->expectException(UserBlockSourceMissingException::class);
        $this->expectExceptionMessage(
            'No user block source: this process runs without a database layer, so the account block fact cannot be read here'
        );

        (new AccountBlockReader())->isBlocked(self::BLOCKED_USER_ID);
    }

    /**
     * A worker that does not read the users collection is refused by the read guard, and the
     * refusal reaches the caller as it was raised: the reader neither catches nor translates it.
     */
    public function testAProcessThatDoesNotReadTheSourceIsRefusedByTheReadGuard(): void
    {
        SourceInterestRegistry::readsWhatIsDelivered();

        $this->expectException(DbCollectionNotReadableException::class);

        (new AccountBlockReader())->isBlocked(self::BLOCKED_USER_ID);
    }

    /**
     * The key is found by looking at what is mounted, and not by reading it: the activation
     * check asks in a process where no reader interest exists, and must still get an answer.
     */
    public function testTheSourceKeyIsNamedWithoutPassingTheReadGuard(): void
    {
        SourceInterestRegistry::readsWhatIsDelivered();

        $this->assertSame(AccountBlockReaderDbContext::USERS, Hilos::$db?->userBlockSourceKey());
        $this->assertNull(AccountBlockReaderDbContext::create(AccountBlockReaderPlainUsers::class)->userBlockSourceKey());
    }
}

/**
 * Minimal stored user row: the fixture source answers from its own map and never loads one.
 */
final class AccountBlockReaderObject extends Object_
{
    public const string ENTITY_CLASS = AccountBlockReaderEntity::class;
}

/**
 * Minimal single-column entity behind that row.
 */
final class AccountBlockReaderEntity extends Entity
{
    public const string _table = 'account_block_reader_test';
    public const string _primary = 'id';
    public const array _columns = ['id'];
    public const array _types = ['id' => 'integer'];

    public ?int $id = null;
}

/**
 * @extends Objects<AccountBlockReaderObject>
 */
final class AccountBlockReaderObjects extends Objects
{
    public const string OBJECT_CLASS = AccountBlockReaderObject::class;
    public const string COLLECTION_KEY = AccountBlockReaderDbContext::USERS;
}

/**
 * Users view that reports blocks out of a fixed map and records every question it was asked.
 */
final class AccountBlockReaderUsers extends DbCollection implements HilosUserBlockSource
{
    /** @var array<int, bool> Block flag per user id that has a row */
    public static array $rows = [];

    /** @var list<list<int>> Id lists the reader passed, in call order */
    public static array $asked = [];

    public function blockedAmong(array $userIds): array
    {
        self::$asked[] = $userIds;

        $answers = [];
        foreach ($userIds as $userId) {
            $answers[$userId] = self::$rows[$userId] ?? false;
        }

        return $answers;
    }

    protected function createDbItem(Object_ $object): DbItem
    {
        return new AccountBlockReaderDbItem($object);
    }
}

/**
 * Users view of a project that supplies no block source.
 */
final class AccountBlockReaderPlainUsers extends DbCollection
{
    protected function createDbItem(Object_ $object): DbItem
    {
        return new AccountBlockReaderDbItem($object);
    }
}

/**
 * Minimal view item, never built by these cases.
 */
final class AccountBlockReaderDbItem extends DbItem
{
}

/**
 * DB context mounting one users collection under the project's own key.
 */
final class AccountBlockReaderDbContext extends HilosDbContext
{
    public const string USERS = 'unitAccountBlockReaderUsers';

    /**
     * Mounts the users collection lazily by key, so a read that passes the guard needs no database behind it.
     *
     * @param class-string<DbCollection> $usersClass View class mounted as the users collection
     * @return self Mounted context
     */
    public static function create(string $usersClass): self
    {
        $context = new self();
        $context->_objectCollections[self::USERS] = AccountBlockReaderObjects::initDB(Objects::LAZY_STRATEGY_KEY);
        $context->setRepresent(self::USERS, $usersClass);

        return $context;
    }

    public function configure(): void
    {
    }
}
