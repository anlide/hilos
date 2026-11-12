<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Backup\FullRestore\MigrationOperationKind;
use Hilos\Backup\FullRestore\MigrationOperationReader;
use Hilos\Backup\FullRestore\StructureDefaults;
use Hilos\Backup\FullRestore\TableStructure;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Entity\Item\SecondFactor as EntitySecondFactor;
use Hilos\Database\Entity\Item\SecondFactorBackupCode as EntitySecondFactorBackupCode;
use Hilos\Database\Entity\Item\SecondFactorReset as EntitySecondFactorReset;
use Hilos\Database\Entity\Item\SecondFactorSetting as EntitySecondFactorSetting;
use Hilos\Database\Entity\Item\SecondFactorTrust as EntitySecondFactorTrust;
use Hilos\Database\Entity\Item\User;
use Hilos\Database\Migration\FrameworkMigrationCatalog;
use Hilos\Database\Migration\FrameworkSchemaCatalog;
use Hilos\Database\MigrationSqlReader;
use Hilos\Database\Object\Item\SecondFactor as ObjectSecondFactor;
use Hilos\Database\Object\Item\SecondFactorBackupCode as ObjectSecondFactorBackupCode;
use Hilos\Database\Object\Item\SecondFactorReset as ObjectSecondFactorReset;
use Hilos\Database\Object\Item\SecondFactorSetting as ObjectSecondFactorSetting;
use Hilos\Database\Object\Item\SecondFactorTrust as ObjectSecondFactorTrust;
use Hilos\Hilos;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Real DDL over the five delivered second-factor parts, so outside rollback scopes: every case changes
 * a column's physical type. The SQL run here is the generated pair the chat demo received, verified
 * against the immutable catalog, and every case takes the schema unit it judges.
 *
 * Moments are listed per unit in the order their columns stand in the stub, and every seeded or
 * expected row of a case is written in that same order. A moment the object does not offer a setter
 * for is seeded on the entity.
 */
final class SecondFactorMomentStorageIntegrationTest extends FrameworkIntegrationTestCase
{
    private const string CHANGE = '20261111235717678237_HIL-1922_second_factor_moments';
    private const string STAGING_PREFIX = '__hil1922_';

    private const string FACTOR_UNIT = 'second_factor';
    private const string BACKUP_UNIT = 'second_factor_backup_code';
    private const string RESET_UNIT = 'second_factor_reset';
    private const string SETTING_UNIT = 'second_factor_setting';
    private const string TRUST_UNIT = 'second_factor_trust';

    private const string LEGACY_REQUIRED = 'TIMESTAMP NOT NULL';
    private const string LEGACY_OPTIONAL = 'TIMESTAMP NULL DEFAULT NULL';
    private const string LEGACY_STAMPED = 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP';

    /** @var array<string, class-string<Entity>> Entity of the table each delivered part converts, by schema unit */
    private const array ENTITIES = [
        self::FACTOR_UNIT => EntitySecondFactor::class,
        self::BACKUP_UNIT => EntitySecondFactorBackupCode::class,
        self::RESET_UNIT => EntitySecondFactorReset::class,
        self::SETTING_UNIT => EntitySecondFactorSetting::class,
        self::TRUST_UNIT => EntitySecondFactorTrust::class,
    ];

    /** @var array<string, class-string> Object of the table each delivered part converts, by schema unit */
    private const array OBJECTS = [
        self::FACTOR_UNIT => ObjectSecondFactor::class,
        self::BACKUP_UNIT => ObjectSecondFactorBackupCode::class,
        self::RESET_UNIT => ObjectSecondFactorReset::class,
        self::SETTING_UNIT => ObjectSecondFactorSetting::class,
        self::TRUST_UNIT => ObjectSecondFactorTrust::class,
    ];

    /** @var array<string, string> Table each delivered part converts, by schema unit */
    private const array TABLES = [
        self::FACTOR_UNIT => EntitySecondFactor::_table,
        self::BACKUP_UNIT => EntitySecondFactorBackupCode::_table,
        self::RESET_UNIT => EntitySecondFactorReset::_table,
        self::SETTING_UNIT => EntitySecondFactorSetting::_table,
        self::TRUST_UNIT => EntitySecondFactorTrust::_table,
    ];

    /**
     * Every moment column with the definition projects held before this change, in stub order, by schema unit.
     *
     * A column held as {@see LEGACY_OPTIONAL} is the one that stays nullable as BIGINT.
     *
     * @var array<string, array<string, string>>
     */
    private const array LEGACY_MOMENTS = [
        self::FACTOR_UNIT => [
            EntitySecondFactor::confirmed_at => self::LEGACY_OPTIONAL,
            EntitySecondFactor::last_used_at => self::LEGACY_OPTIONAL,
            EntitySecondFactor::created_at => self::LEGACY_STAMPED,
        ],
        self::BACKUP_UNIT => [
            EntitySecondFactorBackupCode::used_at => self::LEGACY_OPTIONAL,
            EntitySecondFactorBackupCode::created_at => self::LEGACY_STAMPED,
        ],
        self::RESET_UNIT => [
            EntitySecondFactorReset::requested_at => self::LEGACY_REQUIRED,
            EntitySecondFactorReset::effective_at => self::LEGACY_REQUIRED,
            EntitySecondFactorReset::notified_at => self::LEGACY_REQUIRED,
            EntitySecondFactorReset::canceled_at => self::LEGACY_OPTIONAL,
            EntitySecondFactorReset::completed_at => self::LEGACY_OPTIONAL,
        ],
        self::SETTING_UNIT => [
            EntitySecondFactorSetting::pending_reset_wait_from => self::LEGACY_OPTIONAL,
            EntitySecondFactorSetting::app_code_misses_from => self::LEGACY_OPTIONAL,
            EntitySecondFactorSetting::app_code_locked_until => self::LEGACY_OPTIONAL,
            EntitySecondFactorSetting::updated_at => self::LEGACY_STAMPED,
        ],
        self::TRUST_UNIT => [
            EntitySecondFactorTrust::trusted_until => self::LEGACY_REQUIRED,
            EntitySecondFactorTrust::created_at => self::LEGACY_STAMPED,
        ],
    ];

    /** @var array<string, array<string, string>> Object field of each moment column, in stub order, by schema unit */
    private const array OBJECT_FIELDS = [
        self::FACTOR_UNIT => [
            EntitySecondFactor::confirmed_at => ObjectSecondFactor::confirmedAt,
            EntitySecondFactor::last_used_at => ObjectSecondFactor::lastUsedAt,
            EntitySecondFactor::created_at => ObjectSecondFactor::createdAt,
        ],
        self::BACKUP_UNIT => [
            EntitySecondFactorBackupCode::used_at => ObjectSecondFactorBackupCode::usedAt,
            EntitySecondFactorBackupCode::created_at => ObjectSecondFactorBackupCode::createdAt,
        ],
        self::RESET_UNIT => [
            EntitySecondFactorReset::requested_at => ObjectSecondFactorReset::requestedAt,
            EntitySecondFactorReset::effective_at => ObjectSecondFactorReset::effectiveAt,
            EntitySecondFactorReset::notified_at => ObjectSecondFactorReset::notifiedAt,
            EntitySecondFactorReset::canceled_at => ObjectSecondFactorReset::canceledAt,
            EntitySecondFactorReset::completed_at => ObjectSecondFactorReset::completedAt,
        ],
        self::SETTING_UNIT => [
            EntitySecondFactorSetting::pending_reset_wait_from => ObjectSecondFactorSetting::pendingResetWaitFrom,
            EntitySecondFactorSetting::app_code_misses_from => ObjectSecondFactorSetting::appCodeMissesFrom,
            EntitySecondFactorSetting::app_code_locked_until => ObjectSecondFactorSetting::appCodeLockedUntil,
            EntitySecondFactorSetting::updated_at => ObjectSecondFactorSetting::updatedAt,
        ],
        self::TRUST_UNIT => [
            EntitySecondFactorTrust::trusted_until => ObjectSecondFactorTrust::trustedUntil,
            EntitySecondFactorTrust::created_at => ObjectSecondFactorTrust::createdAt,
        ],
    ];

    /** @var array<string, array{string, ?string, string}> Null, Default and Extra SHOW COLUMNS prints for each legacy definition */
    private const array LEGACY_SHAPES = [
        self::LEGACY_REQUIRED => ['NO', null, ''],
        self::LEGACY_OPTIONAL => ['YES', null, ''],
        self::LEGACY_STAMPED => ['NO', 'current_timestamp()', ''],
    ];

    /**
     * Foreign key each current stub declares, as information_schema prints it.
     *
     * @var array<string, list<array{string, string, string, string}>>
     */
    private const array FOREIGN_KEYS = [
        self::FACTOR_UNIT => [['fk_second_factor_user', EntitySecondFactor::user_id, User::_table, User::id]],
        self::BACKUP_UNIT => [['fk_second_factor_backup_code_user', EntitySecondFactorBackupCode::user_id, User::_table, User::id]],
        self::RESET_UNIT => [['fk_second_factor_reset_user', EntitySecondFactorReset::user_id, User::_table, User::id]],
        self::SETTING_UNIT => [['fk_second_factor_setting_user', EntitySecondFactorSetting::user_id, User::_table, User::id]],
        self::TRUST_UNIT => [['fk_second_factor_trust_user', EntitySecondFactorTrust::user_id, User::_table, User::id]],
    ];

    private const string USER_STUB = 'create_hilos_user';

    /** Person the rows are recorded on. */
    private const int USER_ID = 1922001;

    /** Second person, so a setting row can sit beside the first. */
    private const int OTHER_USER_ID = 1922002;

    private ?DbContext $previousDb = null;

    private ?SignalRouter $previousRouter = null;

    /**
     * Starts from absent tables: none of the five, nor the person they point at, is part of the shared test schema.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = Hilos::$db;
        $this->previousRouter = Hilos::$sr;
        self::dropFixtureTables();
        self::runStub(self::USER_STUB);
        Database::sql(
            'INSERT INTO `' . User::_table . '` (`' . User::id . '`, `' . User::name . '`) VALUES (?, ?), (?, ?)',
            [self::USER_ID, 'Second factor owner', self::OTHER_USER_ID, 'Second factor other'],
        );
        Database::sql("SET time_zone = '+00:00'");
        $context = new SecondFactorMomentStorageDbContext();
        $context->configure();
        Hilos::$db = $context;
        Hilos::$sr = new SignalRouter();
    }

    /** Drops the five tables and the two people, and puts the facades back. */
    protected function tearDown(): void
    {
        try {
            self::dropFixtureTables();
        } finally {
            Hilos::$db = $this->previousDb;
            Hilos::$sr = $this->previousRouter;
            parent::tearDown();
        }
    }

    /**
     * @param string $unit Schema unit of the part under test
     */
    #[DataProvider('units')]
    public function testFreshMomentsAreSignedBigintWithIndexesAndForeignKeys(string $unit): void
    {
        self::fresh($unit);

        $this->assertMoments($unit);
        $this->assertSame(self::expectedIndexes($unit), self::indexes($unit));
        $this->assertSame(self::FOREIGN_KEYS[$unit], self::foreignKeys($unit));
    }

    /**
     * @param string $unit Schema unit of the part under test
     * @param list<list<?string>> $legacy Rows as projects hold them, moments in stub order
     * @param list<list<?int>> $expected The same rows in epoch milliseconds after the delivered UP
     */
    #[DataProvider('legacyRows')]
    public function testDeliveredUpConvertsLegacyRowsAndKeepsIndexForeignKeyAndOrder(
        string $unit,
        array $legacy,
        array $expected,
    ): void {
        self::fresh($unit);
        $columns = self::columns($unit);
        $indexes = self::indexes($unit);
        $foreignKeys = self::foreignKeys($unit);
        self::runStub(self::stub($unit) . '_down');
        $this->legacy($unit);
        foreach ($legacy as $ordinal => $moments) {
            self::seed($unit, $ordinal, $moments);
        }

        Database::sql("SET time_zone = '+05:00'");
        self::runDelivered($unit);
        Database::sql("SET time_zone = '+00:00'");

        $this->assertSame($expected, self::moments($unit));
        $this->assertSame($columns, self::columns($unit));
        $this->assertSame($indexes, self::indexes($unit));
        $this->assertSame($foreignKeys, self::foreignKeys($unit));
        $this->assertMoments($unit);
    }

    /**
     * @return iterable<string, array{string, list<list<?string>>, list<list<?int>>}> Legacy rows of each part and their
     *   converted moments; the second row leaves its optional moments absent
     */
    public static function legacyRows(): iterable
    {
        yield self::FACTOR_UNIT => [
            self::FACTOR_UNIT,
            [
                ['2030-06-15 12:34:56', '2001-09-09 01:46:40', '1970-01-01 00:00:01'],
                [null, null, '2030-06-15 12:34:56'],
            ],
            [
                [1907757296000, 1000000000000, 1000],
                [null, null, 1907757296000],
            ],
        ];
        yield self::BACKUP_UNIT => [
            self::BACKUP_UNIT,
            [
                ['2030-06-15 12:34:56', '2001-09-09 01:46:40'],
                [null, '1970-01-01 00:00:01'],
            ],
            [
                [1907757296000, 1000000000000],
                [null, 1000],
            ],
        ];
        yield self::RESET_UNIT => [
            self::RESET_UNIT,
            [
                ['2030-06-15 12:34:56', '2001-09-09 01:46:40', '1970-01-01 00:00:01', '2001-09-09 01:46:41', '2001-09-09 01:46:42'],
                ['1970-01-01 00:00:01', '2030-06-15 12:34:56', '2001-09-09 01:46:40', null, null],
            ],
            [
                [1907757296000, 1000000000000, 1000, 1000000001000, 1000000002000],
                [1000, 1907757296000, 1000000000000, null, null],
            ],
        ];
        yield self::SETTING_UNIT => [
            self::SETTING_UNIT,
            [
                ['2030-06-15 12:34:56', '2001-09-09 01:46:40', '1970-01-01 00:00:01', '2001-09-09 01:46:41'],
                [null, null, null, '1970-01-01 00:00:01'],
            ],
            [
                [1907757296000, 1000000000000, 1000, 1000000001000],
                [null, null, null, 1000],
            ],
        ];
        yield self::TRUST_UNIT => [
            self::TRUST_UNIT,
            [
                ['2030-06-15 12:34:56', '2001-09-09 01:46:40'],
                ['1970-01-01 00:00:01', '2001-09-09 01:46:41'],
            ],
            [
                [1907757296000, 1000000000000],
                [1000, 1000000001000],
            ],
        ];
    }

    /**
     * @param string $unit Schema unit of the part under test
     */
    #[DataProvider('units')]
    public function testRestorePlannerReplaysEveryDeliveredUpStatement(string $unit): void
    {
        $this->legacy($unit);
        $structure = self::structure($unit);

        $this->replayDeliveredUp($unit, $structure, 5);

        self::runStub(self::stub($unit) . '_down');
        self::fresh($unit);
        $fresh = self::structure($unit);
        $this->assertEquals($fresh->columns, $structure->columns);
        $this->assertEquals($fresh->keys, $structure->keys);
        $this->assertEquals($fresh->foreignKeys, $structure->foreignKeys);
    }

    /**
     * @param string $unit Schema unit of the part under test
     * @param list<list<?int>> $rows Rows in epoch milliseconds, moments in stub order
     * @param list<list<?string>> $stored The same rows as the legacy columns print them after DOWN
     * @param list<list<?int>> $readBack The same rows after UP again, down to the second
     */
    #[DataProvider('representableRows')]
    public function testDownRestoresTheLegacyColumnsAndIndexesAndUpReadsThemBackToTheSecond(
        string $unit,
        array $rows,
        array $stored,
        array $readBack,
    ): void {
        $this->legacy($unit);
        $legacyColumns = self::columns($unit);
        self::runStub(self::stub($unit) . '_down');
        self::fresh($unit);
        $indexes = self::indexes($unit);
        foreach ($rows as $ordinal => $moments) {
            self::seed($unit, $ordinal, $moments);
        }

        self::runDelivered($unit, down: true);

        foreach (self::LEGACY_MOMENTS[$unit] as $column => $definition) {
            $this->assertLegacy($unit, $column, $definition);
        }
        $this->assertSame($legacyColumns, self::columns($unit));
        $this->assertSame($indexes, self::indexes($unit));
        $this->assertSame($stored, self::stored($unit));

        self::runDelivered($unit);

        $this->assertSame($readBack, self::moments($unit));
        $this->assertMoments($unit);
    }

    /**
     * @return iterable<string, array{string, list<list<?int>>, list<list<?string>>, list<list<?int>>}> Millisecond rows of
     *   each part inside the TIMESTAMP range, what DOWN stores for them, and what UP reads back
     */
    public static function representableRows(): iterable
    {
        yield self::FACTOR_UNIT => [
            self::FACTOR_UNIT,
            [
                [1000000000123, 1000, 2147483647999],
                [null, null, 1000000001999],
            ],
            [
                ['2001-09-09 01:46:40', '1970-01-01 00:00:01', '2038-01-19 03:14:07'],
                [null, null, '2001-09-09 01:46:41'],
            ],
            [
                [1000000000000, 1000, 2147483647000],
                [null, null, 1000000001000],
            ],
        ];
        yield self::BACKUP_UNIT => [
            self::BACKUP_UNIT,
            [
                [1000000000123, 2147483647999],
                [null, 1000],
            ],
            [
                ['2001-09-09 01:46:40', '2038-01-19 03:14:07'],
                [null, '1970-01-01 00:00:01'],
            ],
            [
                [1000000000000, 2147483647000],
                [null, 1000],
            ],
        ];
        yield self::RESET_UNIT => [
            self::RESET_UNIT,
            [
                [1000000000123, 1000, 2147483647999, 1000000001999, 1000000002999],
                [1000, 1000000001999, 1000000000123, null, null],
            ],
            [
                ['2001-09-09 01:46:40', '1970-01-01 00:00:01', '2038-01-19 03:14:07', '2001-09-09 01:46:41', '2001-09-09 01:46:42'],
                ['1970-01-01 00:00:01', '2001-09-09 01:46:41', '2001-09-09 01:46:40', null, null],
            ],
            [
                [1000000000000, 1000, 2147483647000, 1000000001000, 1000000002000],
                [1000, 1000000001000, 1000000000000, null, null],
            ],
        ];
        yield self::SETTING_UNIT => [
            self::SETTING_UNIT,
            [
                [1000000000123, 1000, 2147483647999, 1000000001999],
                [null, null, null, 1000],
            ],
            [
                ['2001-09-09 01:46:40', '1970-01-01 00:00:01', '2038-01-19 03:14:07', '2001-09-09 01:46:41'],
                [null, null, null, '1970-01-01 00:00:01'],
            ],
            [
                [1000000000000, 1000, 2147483647000, 1000000001000],
                [null, null, null, 1000],
            ],
        ];
        yield self::TRUST_UNIT => [
            self::TRUST_UNIT,
            [
                [1000000000123, 2147483647999],
                [1000, 1000000001999],
            ],
            [
                ['2001-09-09 01:46:40', '2038-01-19 03:14:07'],
                ['1970-01-01 00:00:01', '2001-09-09 01:46:41'],
            ],
            [
                [1000000000000, 2147483647000],
                [1000, 1000000001000],
            ],
        ];
    }

    /**
     * @param string $unit Schema unit of the part under test
     * @param string $column Moment column holding the value
     * @param int $moment Stored value the legacy TIMESTAMP cannot hold
     */
    #[DataProvider('unrepresentableMoments')]
    public function testDownRefusesBeforeReplacingAnUnrepresentableMoment(string $unit, string $column, int $moment): void
    {
        self::fresh($unit);
        $moments = array_fill_keys(array_keys(self::LEGACY_MOMENTS[$unit]), 1000);
        $moments[$column] = $moment;
        self::seed($unit, 0, array_values($moments));
        $before = self::rows($unit);

        try {
            self::runDelivered($unit, down: true);
            $this->fail('A value outside the TIMESTAMP range must refuse DOWN');
        } catch (DatabaseException $failure) {
            $this->assertStringContainsString('cannot be represented', $failure->getMessage());
        }

        $this->assertSame($before, self::rows($unit));
        $this->assertMoments($unit);
    }

    /**
     * @return iterable<string, array{string, string, int}> Each moment column of each part with each value outside TIMESTAMP(0)
     */
    public static function unrepresentableMoments(): iterable
    {
        foreach (self::LEGACY_MOMENTS as $unit => $moments) {
            foreach (array_keys($moments) as $column) {
                yield $unit . '.' . $column . ' at the epoch' => [$unit, $column, 0];
                yield $unit . '.' . $column . ' before the epoch' => [$unit, $column, -1];
                yield $unit . '.' . $column . ' after 2038' => [$unit, $column, 2147483648000];
            }
        }
    }

    /**
     * @param string $unit Schema unit of the part under test
     * @param ?int $moment Epoch milliseconds written into every moment, or null where the column is optional
     */
    #[DataProvider('roundTripMoments')]
    public function testEntityObjectAndSyncRoundTripExactMoments(string $unit, ?int $moment): void
    {
        self::fresh($unit);
        $entityClass = self::ENTITIES[$unit];
        $objectClass = self::OBJECTS[$unit];
        $entity = new $entityClass();
        foreach (self::keyColumns($unit, 0) as $column => $value) {
            $entity->$column = $value;
        }
        $values = [];
        foreach (array_keys(self::LEGACY_MOMENTS[$unit]) as $column) {
            $value = self::valueFor($unit, $column, $moment);
            $entity->$column = $value;
            $values[$column] = $value;
        }
        $object = $objectClass::fromEntity($entity);
        $object->sync();

        $this->assertSame([array_values($values)], self::moments($unit));
        $created = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($created);
        $this->assertSame(SignalConstants::DB_SYNC_CREATED, $created->signalType->getType());
        $this->assertInstanceOf(DbSyncCreatedSignalData::class, $created->data);
        $collectionClass = $objectClass::OBJECT_COLLECTION_CLASS;
        $this->assertSame($collectionClass::COLLECTION_KEY, $created->data->collectionKey);
        $primary = $entityClass::_primary;
        $this->assertSame((string)$entity->$primary, $created->data->idString);
        foreach ($values as $column => $value) {
            $this->assertSame($value, $created->data->row[$column], $column);
        }
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());

        $read = $entityClass::getById($entity->$primary);
        $this->assertNotNull($read);
        $wrapped = $objectClass::fromEntity($read);
        foreach (self::OBJECT_FIELDS[$unit] as $column => $field) {
            $this->assertSame($values[$column], $read->$column, $column);
            $this->assertSame($values[$column], $wrapped->$field, $field);
            $this->assertSame($values[$column], $wrapped->toArray()[$field], $field);
        }

        $replacement = 1735689600456;
        foreach (array_keys($values) as $column) {
            $entity->$column = $replacement;
        }
        $object->sync();

        $updated = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($updated);
        $this->assertSame(SignalConstants::DB_SYNC_UPDATED, $updated->signalType->getType());
        $this->assertInstanceOf(DbSyncUpdatedSignalData::class, $updated->data);
        $this->assertSame($values, $updated->data->previous);
        $replaced = array_fill_keys(array_keys($values), $replacement);
        $this->assertSame($replaced, $updated->data->row);
        $this->assertSame([array_values($replaced)], self::moments($unit));
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    /**
     * @return iterable<string, array{string, ?int}> Signed moments a BIGINT must keep exactly, and an absent optional moment
     */
    public static function roundTripMoments(): iterable
    {
        foreach (array_keys(self::TABLES) as $unit) {
            yield $unit . ' at the epoch' => [$unit, 0];
            yield $unit . ' before the epoch' => [$unit, -1];
            yield $unit . ' after 2038' => [$unit, 2147483648123];
            yield $unit . ' with milliseconds' => [$unit, 1000000000123];
            yield $unit . ' with optional moments absent' => [$unit, null];
        }
    }

    /**
     * @param string $unit Schema unit of the part under test
     */
    #[DataProvider('units')]
    public function testRealWritersStoreIntegerMomentsWithoutSqlDefaults(string $unit): void
    {
        self::fresh($unit);
        $before = TimeHelper::nowMs();
        self::write($unit, 2147483648123);
        $after = TimeHelper::nowMs();

        $this->assertMoments($unit);
        $this->assertWritten($unit, $before, $after, 2147483648123);
    }

    /** @return iterable<string, array{string}> Every schema unit this change delivers */
    public static function units(): iterable
    {
        foreach (array_keys(self::TABLES) as $unit) {
            yield $unit => [$unit];
        }
    }

    /**
     * Every moment of the part is signed BIGINT with no SQL writer, in the nullability its column carries.
     *
     * @param string $unit Schema unit whose table is judged
     */
    private function assertMoments(string $unit): void
    {
        foreach (self::LEGACY_MOMENTS[$unit] as $column => $definition) {
            $row = self::column($unit, $column);
            $this->assertMatchesRegularExpression('/^bigint(?:\([0-9]+\))?$/', $row['Type'], $column);
            $this->assertSame($definition === self::LEGACY_OPTIONAL ? 'YES' : 'NO', $row['Null'], $column);
            $this->assertNull($row['Default'], $column);
            $this->assertSame('', $row['Extra'], $column);
            $this->assertStagingAbsent($unit, $column);
        }
    }

    /**
     * @param string $unit Schema unit whose table is judged
     * @param string $column Moment column that must be back in its legacy form
     * @param string $definition Legacy definition the column carried before this change
     */
    private function assertLegacy(string $unit, string $column, string $definition): void
    {
        $row = self::column($unit, $column);
        $this->assertSame('timestamp', $row['Type'], $column);
        $this->assertSame(self::LEGACY_SHAPES[$definition], [$row['Null'], $row['Default'], $row['Extra']], $column);
        $this->assertStagingAbsent($unit, $column);
    }

    /**
     * @param string $unit Schema unit a conversion ran over
     * @param string $column Moment column whose staging column must be gone
     */
    private function assertStagingAbsent(string $unit, string $column): void
    {
        $this->assertArrayNotHasKey(self::STAGING_PREFIX . $column, array_column(self::columns($unit), null, 'Field'));
    }

    /**
     * The create path wrote integers: a caller-supplied moment exactly, a required stamp inside the call, and the rest absent.
     *
     * @param string $unit Schema unit whose row was just written
     * @param int $before Epoch milliseconds taken before the write
     * @param int $after Epoch milliseconds taken after the write
     * @param int $supplied Moment the call itself named
     */
    private function assertWritten(string $unit, int $before, int $after, int $supplied): void
    {
        $stored = array_combine(array_keys(self::LEGACY_MOMENTS[$unit]), self::moments($unit)[0]);
        $stamps = match ($unit) {
            self::FACTOR_UNIT => [EntitySecondFactor::created_at],
            self::BACKUP_UNIT => [EntitySecondFactorBackupCode::created_at],
            self::RESET_UNIT => [EntitySecondFactorReset::requested_at, EntitySecondFactorReset::notified_at],
            self::SETTING_UNIT => [EntitySecondFactorSetting::updated_at],
            self::TRUST_UNIT => [EntitySecondFactorTrust::created_at],
        };
        $exact = match ($unit) {
            self::RESET_UNIT => [EntitySecondFactorReset::effective_at => $supplied],
            self::SETTING_UNIT => [EntitySecondFactorSetting::pending_reset_wait_from => $supplied],
            self::TRUST_UNIT => [EntitySecondFactorTrust::trusted_until => $supplied],
            default => [],
        };
        foreach ($stored as $column => $value) {
            if (in_array($column, $stamps, true)) {
                $this->assertGreaterThanOrEqual($before, $value, $column);
                $this->assertLessThanOrEqual($after, $value, $column);
            } elseif (array_key_exists($column, $exact)) {
                $this->assertSame($exact[$column], $value, $column);
            } else {
                $this->assertNull($value, $column);
            }
        }
    }

    /**
     * Applies every statement of a delivered UP to the planner's model of the table, as a full restore does.
     *
     * @param string $unit Schema unit whose delivered part is replayed
     * @param TableStructure $structure Model of the table before the part
     * @param int $statementCount Flat statements the part is delivered as
     */
    private function replayDeliveredUp(string $unit, TableStructure $structure, int $statementCount): void
    {
        $statements = MigrationSqlReader::statements(self::deliveredPair($unit)[0]);

        $this->assertCount($statementCount, $statements);
        foreach ($statements as $statement) {
            foreach (MigrationOperationReader::read($statement, self::database()) as $operation) {
                $this->assertNotSame(MigrationOperationKind::UNKNOWN, $operation->kind, $statement);
                if ($operation->kind === MigrationOperationKind::ALTER_TABLE) {
                    $structure->alter($operation);
                }
            }
        }
    }

    /**
     * Raises the part's table as projects hold it before this change.
     *
     * @param string $unit Schema unit of the part
     */
    private function legacy(string $unit): void
    {
        self::fresh($unit);
        $changes = [];
        foreach (self::LEGACY_MOMENTS[$unit] as $column => $definition) {
            $changes[] = 'MODIFY `' . $column . '` ' . $definition;
        }
        Database::sql('ALTER TABLE `' . self::TABLES[$unit] . '` ' . implode(', ', $changes));
    }

    /**
     * Raises the part's table as the current stub defines it.
     *
     * @param string $unit Schema unit of the part
     */
    private static function fresh(string $unit): void
    {
        self::runStub(self::stub($unit));
    }

    /**
     * Writes one row through the collection action that creates it.
     *
     * @param string $unit Schema unit of the part
     * @param int $supplied Moment the call itself names, where the action takes one
     */
    private static function write(string $unit, int $supplied): void
    {
        if ($unit === self::FACTOR_UNIT) {
            Hilos::$db->secondFactors->actions->startEnrolment(self::USER_ID, 'Phone', 'SECRET');

            return;
        }
        if ($unit === self::BACKUP_UNIT) {
            Hilos::$db->secondFactorBackupCodes->actions->issueSet(self::USER_ID, ['code']);

            return;
        }
        if ($unit === self::RESET_UNIT) {
            Hilos::$db->secondFactorResets->actions->request(self::USER_ID, $supplied, str_repeat('a', 64));

            return;
        }
        if ($unit === self::SETTING_UNIT) {
            Hilos::$db->secondFactorSettings->actions->setResetWait(self::USER_ID, null, null, $supplied);

            return;
        }
        Hilos::$db->secondFactorTrusts->actions->trust(1, self::USER_ID, $supplied);
    }

    /**
     * Writes one row of the part's table with its moments in their current representation.
     *
     * @param string $unit Schema unit of the part
     * @param int $ordinal Position of the row in its case, which keeps its unique key apart from the others
     * @param list<int|string|null> $moments Stored values in stub order of the moment columns
     */
    private static function seed(string $unit, int $ordinal, array $moments): void
    {
        $row = self::keyColumns($unit, $ordinal) + array_combine(array_keys(self::LEGACY_MOMENTS[$unit]), $moments);
        Database::sql(
            'INSERT INTO `' . self::TABLES[$unit] . '` (`' . implode('`, `', array_keys($row)) . '`)'
                . ' VALUES (?' . str_repeat(', ?', count($row) - 1) . ')',
            array_values($row),
        );
    }

    /**
     * The trust's grant moment came after this change (HIL-1900) and is not one it converts, so it
     * travels with the other required columns.
     *
     * @param string $unit Schema unit of the part
     * @param int $ordinal Position of the row in its case
     * @return array<string, int|string> Required columns other than the moments this change converts, unique per
     *   ordinal where the table asks it
     */
    private static function keyColumns(string $unit, int $ordinal): array
    {
        return match ($unit) {
            self::FACTOR_UNIT => [
                EntitySecondFactor::user_id => self::USER_ID,
                EntitySecondFactor::label => 'Phone ' . $ordinal,
            ],
            self::BACKUP_UNIT => [
                EntitySecondFactorBackupCode::user_id => self::USER_ID,
            ],
            self::RESET_UNIT => [
                EntitySecondFactorReset::user_id => self::USER_ID,
            ],
            self::SETTING_UNIT => [
                EntitySecondFactorSetting::user_id => $ordinal === 0 ? self::USER_ID : self::OTHER_USER_ID,
            ],
            self::TRUST_UNIT => [
                EntitySecondFactorTrust::session_id => $ordinal + 1,
                EntitySecondFactorTrust::user_id => self::USER_ID,
                EntitySecondFactorTrust::granted_at => 1735689600000,
            ],
        };
    }

    /**
     * @param string $unit Schema unit of the part
     * @param string $column Moment column
     * @param ?int $moment Value the case names, or null where an optional column stays absent
     * @return ?int Value stored in that column
     */
    private static function valueFor(string $unit, string $column, ?int $moment): ?int
    {
        if ($moment === null && self::LEGACY_MOMENTS[$unit][$column] === self::LEGACY_OPTIONAL) {
            return null;
        }

        return $moment ?? 0;
    }

    /** Drops every table a case may have raised, the five that point at a person before the person. */
    private static function dropFixtureTables(): void
    {
        foreach (self::TABLES as $table) {
            self::runStub('create_' . $table . '_down');
        }
        self::runStub(self::USER_STUB . '_down');
    }

    /**
     * @param string $unit Schema unit of the part
     * @return string File name of the unit's create stub, without extension
     */
    private static function stub(string $unit): string
    {
        return 'create_' . self::TABLES[$unit];
    }

    /** @param string $stub Stub file name without extension */
    private static function runStub(string $stub): void
    {
        Database::sqlRun((string)file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/' . $stub . '.sql'));
    }

    /**
     * @param string $unit Schema unit whose delivered part runs
     * @param bool $down Whether to run the part's down direction
     */
    private static function runDelivered(string $unit, bool $down = false): void
    {
        foreach (MigrationSqlReader::statements(self::deliveredPair($unit)[$down ? 1 : 0]) as $statement) {
            Database::sql($statement);
        }
    }

    /**
     * @param string $unit Schema unit of this change
     * @return array{0: string, 1: string} Up and down text of the pair the chat demo received, verified against the catalog
     */
    private static function deliveredPair(string $unit): array
    {
        $reference = '"changeId":"' . self::CHANGE . '","unit":"' . $unit . '"';
        foreach (glob(dirname(__DIR__, 3) . '/demo/chat/backend/Database/Migration/Schema/*.sql') as $file) {
            if (str_ends_with($file, '_down.sql') || !str_contains((string)file_get_contents($file), $reference)) {
                continue;
            }
            $up = (string)file_get_contents($file);
            $down = (string)file_get_contents(substr($file, 0, -4) . '_down.sql');
            new FrameworkMigrationCatalog(new FrameworkSchemaCatalog())->verifyPair($up, $down);

            return [$up, $down];
        }
        self::fail('The chat demo did not receive ' . $unit . ' of ' . self::CHANGE);
    }

    /** @return string Schema the primary connection executes in */
    private static function database(): string
    {
        return Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX)->database;
    }

    /**
     * @param string $unit Schema unit of the part
     * @return array<string, list<string>> Columns of every index of its table in key order, by index name
     */
    private static function expectedIndexes(string $unit): array
    {
        $entity = self::ENTITIES[$unit];
        $indexes = ['PRIMARY' => [$entity::_primary]];
        foreach ($entity::_indexes as $name => $index) {
            $indexes[$name] = $index[Entity::INDEX_COLUMNS];
        }
        ksort($indexes);

        return $indexes;
    }

    /**
     * @param string $unit Schema unit of the part
     * @return TableStructure The part's table as the restore planner models it from its live definition
     */
    private static function structure(string $unit): TableStructure
    {
        $collations = array_column(
            Database::sql(
                'SELECT COLLATION_NAME, CHARACTER_SET_NAME FROM information_schema.COLLATION_CHARACTER_SET_APPLICABILITY',
            )->rows(),
            'CHARACTER_SET_NAME',
            'COLLATION_NAME',
        );
        $create = Database::sql('SHOW CREATE TABLE `' . self::TABLES[$unit] . '`')->row()['Create Table'];

        return TableStructure::read(
            $create,
            self::database(),
            new StructureDefaults('InnoDB', 'utf8mb4', 'utf8mb4_general_ci', charsetByCollation: $collations),
        );
    }

    /**
     * @param string $unit Schema unit of the part
     * @return array<string, list<string>> Columns of every index of its table in key order, by index name
     */
    private static function indexes(string $unit): array
    {
        $indexes = [];
        foreach (Database::sql('SHOW INDEX FROM `' . self::TABLES[$unit] . '`')->rows() as $row) {
            $indexes[$row['Key_name']][] = $row['Column_name'];
        }
        ksort($indexes);

        return $indexes;
    }

    /**
     * @param string $unit Schema unit of the part
     * @return list<array{string, string, string, string}> Constraint, column, parent table and parent column of each foreign key
     */
    private static function foreignKeys(string $unit): array
    {
        $keys = [];
        foreach (Database::sql(
            'SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME'
                . ' FROM information_schema.KEY_COLUMN_USAGE'
                . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL'
                . ' ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION',
            [self::database(), self::TABLES[$unit]],
        )->rows() as $row) {
            $keys[] = [
                $row['CONSTRAINT_NAME'],
                $row['COLUMN_NAME'],
                $row['REFERENCED_TABLE_NAME'],
                $row['REFERENCED_COLUMN_NAME'],
            ];
        }

        return $keys;
    }

    /**
     * @param string $unit Schema unit of the part
     * @return list<array<string, mixed>> Columns of its table in physical order
     */
    private static function columns(string $unit): array
    {
        return Database::sql('SHOW COLUMNS FROM `' . self::TABLES[$unit] . '`')->rows();
    }

    /**
     * @param string $unit Schema unit of the part
     * @param string $column Column to describe
     * @return array<string, mixed> Its SHOW COLUMNS row
     */
    private static function column(string $unit, string $column): array
    {
        return Database::sql('SHOW COLUMNS FROM `' . self::TABLES[$unit] . '` LIKE ?', [$column])->row();
    }

    /**
     * @param string $unit Schema unit of the part
     * @return list<array<string, mixed>> Every row of its table, oldest first
     */
    private static function rows(string $unit): array
    {
        $primary = self::ENTITIES[$unit]::_primary;

        return Database::sql('SELECT * FROM `' . self::TABLES[$unit] . '` ORDER BY `' . $primary . '`')->rows();
    }

    /**
     * @param string $unit Schema unit of the part
     * @return list<list<?string>> Its moments as the database prints them, in stub order, oldest row first
     */
    private static function stored(string $unit): array
    {
        $columns = array_keys(self::LEGACY_MOMENTS[$unit]);

        return array_map(
            static fn (array $row): array => array_map(static fn (string $column): ?string => $row[$column], $columns),
            self::rows($unit),
        );
    }

    /**
     * @param string $unit Schema unit of the part
     * @return list<list<?int>> Its moments in epoch milliseconds, in stub order, oldest row first
     */
    private static function moments(string $unit): array
    {
        return array_map(
            static fn (array $row): array => array_map(
                static fn (?string $value): ?int => $value === null ? null : (int)$value,
                $row,
            ),
            self::stored($unit),
        );
    }
}

/**
 * A framework database context with nothing but the framework's own collections.
 *
 * The second-factor writers are framework-owned, so the smallest honest context for them is
 * {@see HilosDbContext} with no project collections added.
 */
final class SecondFactorMomentStorageDbContext extends HilosDbContext
{
}
