<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database\Schema;

use Hilos\AdminViewMode\AdminViewModeLatchTable;
use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\Database;
use Hilos\Database\DatabaseMarker;
use Hilos\Database\Entity\Item\AuthBlock;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Entity\Item\Identity;
use Hilos\Database\Entity\Item\OAuthProvider;
use Hilos\Database\Entity\Item\RegistrationReservation;
use Hilos\Database\Entity\Item\User;
use Hilos\Database\Entity\Item\VerifierCircleMember;
use Hilos\Database\Exception\InvalidMomentDeclarationException;
use Hilos\Database\MigrationClaim;
use Hilos\Database\PhpType;
use Hilos\Database\Schema\EntitySchemaAudit;
use Hilos\Database\Schema\FrameworkTablesWithoutEntity;
use Hilos\Database\Schema\MomentColumn;
use Hilos\Database\Schema\MomentColumns;
use Hilos\Database\Schema\TablesWithoutEntityProvider;
use Hilos\Hilos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;

/** Constant-only semantics, inheritance and storage ownership, independent of the current SQL types. */
final class MomentColumnsTest extends TestCase
{
    /** Own moments default to the standard even while their legacy mapped type is a string. */
    public function testOwnDefault(): void
    {
        $moments = MomentColumns::forEntity(OwnMomentEntity::class);
        self::assertSame(['happened_at'], array_keys($moments));
        self::assertEquals(new MomentColumn('happened_at', false, true), $moments['happened_at']);
        self::assertArrayNotHasKey('id', $moments);
        self::assertArrayNotHasKey('calendar_day', $moments);
        self::assertSame([], MomentColumns::forEntity(NoMomentEntity::class));

        foreach ([['', false, null], [' ', false, null], ['at', false, ''], ['at', false, ' '], ['at', true, 'SQL; own choice']]
            as [$column, $frameworkOwned, $storage]) {
            try {
                new MomentColumn($column, $frameworkOwned, false, $storage);
                self::fail('Invalid moment descriptor was accepted');
            } catch (InvalidMomentDeclarationException $failure) {
                self::assertStringContainsString('Moment column', $failure->getMessage());
            }
        }
    }

    /** Explicit project explanations survive unchanged, including surrounding whitespace. */
    public function testExplicitOwnCustom(): void
    {
        $moment = MomentColumns::forEntity(CustomMomentEntity::class)['happened_at'];
        self::assertFalse($moment->frameworkOwned);
        self::assertTrue($moment->mapped);
        self::assertSame(' SQL UTC datetime; external archive contract ', $moment->projectStorage);
    }

    /** Numeric string names retain distinct descriptors and unchanged project storage explanations. */
    public function testNumericStringEntityMomentStorage(): void
    {
        $entity = new class extends NoMomentEntity {
            public const array _moments = ['0', '00', '-1', '1'];
            public const array _momentStorage = [
                '0' => ' SQL DATETIME; external archive contract ',
                '00' => 'seconds; separate archive contract',
                '-1' => 'ISO UTC; historical archive contract',
            ];
        };
        $moments = MomentColumns::forEntity($entity::class);
        self::assertSame(['0', '00', '-1', '1'], array_column($moments, 'column'));
        self::assertEquals([
            '0' => new MomentColumn('0', false, false, ' SQL DATETIME; external archive contract '),
            '00' => new MomentColumn('00', false, false, 'seconds; separate archive contract'),
            '-1' => new MomentColumn('-1', false, false, 'ISO UTC; historical archive contract'),
            '1' => new MomentColumn('1', false, false),
        ], $moments);
    }

    /** A descendant adds semantics and can explicitly restore its own representation to the standard. */
    public function testComposedInheritance(): void
    {
        $moments = MomentColumns::forEntity(ComposedMomentEntity::class);
        self::assertSame(['happened_at', 'observed_at'], array_keys($moments));
        self::assertSame(CustomMomentEntity::_momentStorage['happened_at'], $moments['happened_at']->projectStorage);
        self::assertNull($moments['observed_at']->projectStorage);
        self::assertFalse($moments['observed_at']->mapped);

        $standard = MomentColumns::forEntity(StandardAgainMomentEntity::class);
        self::assertSame(array_keys($moments), array_keys($standard));
        self::assertNull($standard['happened_at']->projectStorage);
    }

    /** Dropping a parent's moment is refused even if a later descendant restores it. */
    public function testAncestorMomentsCannotDisappear(): void
    {
        foreach ([LostMomentEntity::class, ReintroducedMomentEntity::class] as $class) {
            try {
                MomentColumns::forEntity($class);
                self::fail('Inherited moment was silently lost');
            } catch (InvalidMomentDeclarationException $failure) {
                self::assertStringContainsString('happened_at', $failure->getMessage());
                self::assertStringContainsString(LostMomentEntity::class, $failure->getMessage());
            }
        }
    }

    /** @return iterable<string, array{string}> Invalid Entity classes and list declarations */
    public static function malformedLists(): iterable
    {
        yield 'unknown class' => ['MissingMomentEntity'];
        yield 'not an Entity' => [stdClass::class];
        yield 'associative list' => [(new class extends OwnMomentEntity {
            public const array _moments = ['first' => self::happened_at];
        })::class];
        yield 'sparse list' => [(new class extends OwnMomentEntity {
            public const array _moments = [1 => self::happened_at];
        })::class];
        yield 'duplicate' => [(new class extends OwnMomentEntity {
            public const array _moments = [self::happened_at, self::happened_at];
        })::class];
        yield 'empty' => [(new class extends OwnMomentEntity {
            public const array _moments = [''];
        })::class];
        yield 'blank' => [(new class extends OwnMomentEntity {
            public const array _moments = ['  '];
        })::class];
        yield 'integer' => [(new class extends OwnMomentEntity {
            public const array _moments = [123];
        })::class];
        yield 'integer zero' => [(new class extends NoMomentEntity {
            public const array _moments = [0];
        })::class];
        yield 'null' => [(new class extends OwnMomentEntity {
            public const array _moments = [null];
        })::class];
        yield 'nested array' => [(new class extends OwnMomentEntity {
            public const array _moments = [[self::happened_at]];
        })::class];
    }

    /** @param string $class Invalid Entity declaration */
    #[DataProvider('malformedLists')]
    public function testMalformedListsAreRefused(string $class): void
    {
        $this->expectException(InvalidMomentDeclarationException::class);
        $this->expectExceptionMessage($class);
        MomentColumns::forEntity($class);
    }

    /** @return iterable<string, array{class-string<Entity>, string}> Invalid storage maps and diagnostic column names */
    public static function malformedStorage(): iterable
    {
        yield 'non-moment' => [(new class extends OwnMomentEntity {
            public const array _momentStorage = [self::id => 'string; external key'];
        })::class, 'id'];
        yield 'list instead of map' => [(new class extends OwnMomentEntity {
            public const array _momentStorage = ['SQL; archive contract'];
        })::class, '0'];
        yield 'zero key is not leading-zero moment' => [(new class extends NoMomentEntity {
            public const array _moments = ['00'];
            public const array _momentStorage = ['0' => 'SQL; archive contract'];
        })::class, '0'];
        yield 'leading-zero key is not zero moment' => [(new class extends NoMomentEntity {
            public const array _moments = ['0'];
            public const array _momentStorage = ['00' => 'SQL; archive contract'];
        })::class, '00'];
        yield 'numeric moment still needs explanation' => [(new class extends NoMomentEntity {
            public const array _moments = ['0'];
            public const array _momentStorage = ['0' => null];
        })::class, '0'];
        yield 'empty key' => [(new class extends OwnMomentEntity {
            public const array _momentStorage = ['' => 'SQL; archive contract'];
        })::class, ''];
        yield 'empty explanation' => [(new class extends OwnMomentEntity {
            public const array _momentStorage = [self::happened_at => ''];
        })::class, 'happened_at'];
        yield 'blank explanation' => [(new class extends OwnMomentEntity {
            public const array _momentStorage = [self::happened_at => '  '];
        })::class, 'happened_at'];
        yield 'null explanation' => [(new class extends OwnMomentEntity {
            public const array _momentStorage = [self::happened_at => null];
        })::class, 'happened_at'];
        yield 'integer explanation' => [(new class extends OwnMomentEntity {
            public const array _momentStorage = [self::happened_at => 123];
        })::class, 'happened_at'];
        yield 'array explanation' => [(new class extends OwnMomentEntity {
            public const array _momentStorage = [self::happened_at => []];
        })::class, 'happened_at'];
    }

    /**
     * @param class-string<Entity> $class Invalid Entity storage declaration
     * @param string $column Column named in the refusal
     */
    #[DataProvider('malformedStorage')]
    public function testMalformedStorageMapsAreRefused(string $class, string $column): void
    {
        $this->expectException(InvalidMomentDeclarationException::class);
        $this->expectExceptionMessageMatches('/' . Entity::META_MOMENT_STORAGE . '.*\[' . preg_quote($column, '/') . '\]/');
        MomentColumns::forEntity($class);
    }

    /** @return iterable<string, array{class-string<Entity>, string}> Attempts to change framework semantics or storage */
    public static function frameworkOverrides(): iterable
    {
        yield 'ID is not a moment' => [(new class extends User {
            public const array _moments = [...parent::_moments, self::id];
        })::class, User::id];
        yield 'other framework scalar is not a moment' => [(new class extends User {
            public const array _moments = [...parent::_moments, self::theme_pick];
        })::class, User::theme_pick];
        yield 'mapped framework opt-out' => [(new class extends User {
            public const array _momentStorage = [self::last_activity => 'seconds; project convention'];
        })::class, User::last_activity];
        yield 'DB-only framework opt-out' => [(new class extends VerifierCircleMember {
            public const array _momentStorage = [self::created_at => 'SQL; project convention'];
        })::class, VerifierCircleMember::created_at];
        yield 'framework moment hidden' => [(new class extends VerifierCircleMember {
            public const array _moments = [];
        })::class, VerifierCircleMember::created_at];
    }

    /**
     * @param class-string<Entity> $class Invalid framework extension
     * @param string $column Framework column that must be named
     */
    #[DataProvider('frameworkOverrides')]
    public function testFrameworkReclassificationAndOptOutAreRefused(string $class, string $column): void
    {
        $this->expectException(InvalidMomentDeclarationException::class);
        $this->expectExceptionMessage($column);
        MomentColumns::forEntity($class);
    }

    /** Physical DB-only moments remain unmapped; mapped describes the requested Entity's actual list. */
    public function testMappedAndDatabaseOnlyMoments(): void
    {
        foreach ([
            AuthBlock::class => [AuthBlock::updated_at],
            RegistrationReservation::class => [RegistrationReservation::created_at],
        ] as $class => $columns) {
            $moments = MomentColumns::forEntity($class);
            foreach ($columns as $column) {
                self::assertEquals(new MomentColumn($column, true, false), $moments[$column]);
            }
        }
        foreach ([
            Identity::class => [Identity::created_at, Identity::updated_at],
            OAuthProvider::class => [OAuthProvider::created_at, OAuthProvider::updated_at],
        ] as $class => $columns) {
            $moments = MomentColumns::forEntity($class);
            foreach ($columns as $column) {
                self::assertEquals(new MomentColumn($column, true, true), $moments[$column]);
            }
        }
        self::assertTrue(MomentColumns::forEntity(AuthBlock::class)[AuthBlock::blocked_until]->mapped);
        self::assertEquals(
            new MomentColumn(VerifierCircleMember::created_at, true, true),
            MomentColumns::forEntity(VerifierCircleMember::class)[VerifierCircleMember::created_at],
        );
        self::assertTrue(MomentColumns::forEntity(User::class)[User::last_activity]->frameworkOwned);
        $unmapped = new class extends OwnMomentEntity {
            public const array _columns = [self::id, self::calendar_day];
        };
        self::assertFalse(MomentColumns::forEntity($unmapped::class)['happened_at']->mapped);

        foreach (EntitySchemaAudit::frameworkEntities() as $class) {
            foreach (MomentColumns::forEntity($class) as $column => $moment) {
                self::assertTrue($moment->frameworkOwned, $class . ':' . $column);
                self::assertNull($moment->projectStorage);
            }
        }
    }

    /** @return iterable<string, array{string, string}> Malformed providers and diagnostic fragments */
    public static function malformedProviders(): iterable
    {
        yield 'unknown class' => ['MissingMomentProvider', 'MissingMomentProvider'];
        yield 'wrong interface' => [stdClass::class, stdClass::class];
        yield 'interface itself' => [TablesWithoutEntityProvider::class, TablesWithoutEntityProvider::class];
        yield 'duplicate tables' => [(new class extends OwnMomentProvider {
            public const array TABLES = ['own_raw', 'own_raw'];
        })::class, 'tables()'];
        yield 'non-list tables' => [(new class extends OwnMomentProvider {
            public const array TABLES = ['name' => 'own_raw'];
        })::class, 'tables()'];
        yield 'empty table' => [(new class extends OwnMomentProvider {
            public const array TABLES = [''];
        })::class, 'tables()'];
        yield 'non-string table' => [(new class extends OwnMomentProvider {
            public const array TABLES = [123];
        })::class, 'tables()'];
        yield 'missing table entry' => [(new class extends OwnMomentProvider {
            public const array MOMENTS = [];
        })::class, 'own_raw'];
        yield 'extra moment table' => [(new class extends OwnMomentProvider {
            public const array MOMENTS = ['own_raw' => ['at'], 'stray' => []];
        })::class, 'stray'];
        yield 'zero moment table is not leading-zero table' => [(new class extends EmptyMomentProvider {
            public const array TABLES = ['00'];
            public const array MOMENTS = ['00' => [], '0' => []];
        })::class, 'undeclared table [0]'];
        yield 'leading-zero moment table is not zero table' => [(new class extends EmptyMomentProvider {
            public const array TABLES = ['0'];
            public const array MOMENTS = ['0' => [], '00' => []];
        })::class, 'undeclared table [00]'];
        yield 'moment list is null' => [(new class extends OwnMomentProvider {
            public const array MOMENTS = ['own_raw' => null];
        })::class, 'own_raw'];
        yield 'moment list is associative' => [(new class extends OwnMomentProvider {
            public const array MOMENTS = ['own_raw' => ['key' => 'at']];
        })::class, 'own_raw'];
        yield 'duplicate moments' => [(new class extends OwnMomentProvider {
            public const array MOMENTS = ['own_raw' => ['at', 'at']];
        })::class, 'at'];
        yield 'empty moment' => [(new class extends OwnMomentProvider {
            public const array MOMENTS = ['own_raw' => ['']];
        })::class, 'own_raw'];
        yield 'non-string moment' => [(new class extends OwnMomentProvider {
            public const array MOMENTS = ['own_raw' => [false]];
        })::class, 'own_raw'];
        yield 'integer zero moment' => [(new class extends OwnMomentProvider {
            public const array MOMENTS = ['own_raw' => ['at', 0]];
        })::class, 'own_raw'];
        yield 'extra storage table' => [(new class extends OwnMomentProvider {
            public const array STORAGE = ['stray' => ['at' => 'SQL; archive contract']];
        })::class, 'stray'];
        yield 'zero storage table is not leading-zero table' => [(new class extends EmptyMomentProvider {
            public const array TABLES = ['00'];
            public const array MOMENTS = ['00' => []];
            public const array STORAGE = ['0' => []];
        })::class, 'undeclared table [0]'];
        yield 'leading-zero storage table is not zero table' => [(new class extends EmptyMomentProvider {
            public const array TABLES = ['0'];
            public const array MOMENTS = ['0' => []];
            public const array STORAGE = ['00' => []];
        })::class, 'undeclared table [00]'];
        yield 'storage is not a column map' => [(new class extends OwnMomentProvider {
            public const array STORAGE = ['own_raw' => null];
        })::class, 'own_raw'];
        yield 'storage on a non-moment' => [(new class extends OwnMomentProvider {
            public const array STORAGE = ['own_raw' => ['id' => 'SQL; archive contract']];
        })::class, 'id'];
        yield 'zero storage key is not leading-zero moment' => [(new class extends OwnMomentProvider {
            public const array MOMENTS = ['own_raw' => ['at', '00']];
            public const array STORAGE = ['own_raw' => ['0' => 'SQL; archive contract']];
        })::class, 'undeclared moment [0]'];
        yield 'leading-zero storage key is not zero moment' => [(new class extends OwnMomentProvider {
            public const array MOMENTS = ['own_raw' => ['at', '0']];
            public const array STORAGE = ['own_raw' => ['00' => 'SQL; archive contract']];
        })::class, 'undeclared moment [00]'];
        yield 'storage explanation is null' => [(new class extends OwnMomentProvider {
            public const array STORAGE = ['own_raw' => ['at' => null]];
        })::class, 'at'];
        yield 'storage explanation is blank' => [(new class extends OwnMomentProvider {
            public const array STORAGE = ['own_raw' => ['at' => ' ']];
        })::class, 'at'];
        yield 'framework moment removed' => [(new class extends EmptyMomentProvider {
            public const array TABLES = [MigrationClaim::TABLE];
            public const array MOMENTS = [MigrationClaim::TABLE => []];
        })::class, 'claimed_at'];
        yield 'framework storage overridden' => [(new class extends EmptyMomentProvider {
            public const array TABLES = [MigrationClaim::TABLE];
            public const array MOMENTS = [MigrationClaim::TABLE => ['claimed_at']];
            public const array STORAGE = [MigrationClaim::TABLE => ['claimed_at' => 'SQL; project choice']];
        })::class, 'claimed_at'];
        yield 'ancestor moment removed' => [LostProviderMoment::class, 'at'];
        yield 'ancestor table removed' => [LostProviderTable::class, 'at'];
        yield 'provider throws' => [(new class extends OwnMomentProvider {
            /** @return array<string, list<string>> Broken fixture implementation */
            public static function moments(): array
            {
                throw new InvalidMomentDeclarationException('Broken fixture');
            }
        })::class, 'could not be read'];
    }

    /**
     * @param string $class Invalid provider declaration
     * @param string $fragment Diagnostic table, column or declaration fragment
     */
    #[DataProvider('malformedProviders')]
    public function testProviderShape(string $class, string $fragment): void
    {
        $this->expectException(InvalidMomentDeclarationException::class);
        $this->expectExceptionMessage($fragment);
        MomentColumns::forProvider($class);
    }

    /** Providers preserve framework moments, own defaults, inherited custom storage and explicit empty tables. */
    public function testProjectProviderAndCustomStorage(): void
    {
        $moments = MomentColumns::forProvider(OwnMomentProvider::class);
        self::assertEquals(new MomentColumn('at', false, false), $moments['own_raw']['at']);
        $provider = new class extends CustomMomentProvider {
            public const array TABLES = [...parent::TABLES, MigrationClaim::TABLE, 'no_moments'];
            public const array MOMENTS = [
                ...parent::MOMENTS,
                'own_raw' => ['at', '0'],
                MigrationClaim::TABLE => ['claimed_at', 'reviewed_at'],
                'no_moments' => [],
            ];
            public const array STORAGE = [
                ...parent::STORAGE,
                MigrationClaim::TABLE => ['reviewed_at' => 'SQL; external review contract'],
            ];
        };
        $moments = MomentColumns::forProvider($provider::class);
        self::assertEquals(new MomentColumn('at', false, false, ' SQL; external source '), $moments['own_raw']['at']);
        self::assertEquals(new MomentColumn('0', false, false), $moments['own_raw']['0']);
        self::assertEquals(new MomentColumn('claimed_at', true, false), $moments[MigrationClaim::TABLE]['claimed_at']);
        self::assertEquals(
            new MomentColumn('reviewed_at', false, false, 'SQL; external review contract'),
            $moments[MigrationClaim::TABLE]['reviewed_at'],
        );
        self::assertSame([], $moments['no_moments']);
        $standard = new class extends CustomMomentProvider {
            public const array STORAGE = [];
        };
        self::assertNull(MomentColumns::forProvider($standard::class)['own_raw']['at']->projectStorage);
    }

    /** Provider column map keys preserve numeric string names independently of their storage choice. */
    public function testNumericStringProviderMomentStorage(): void
    {
        $provider = new class extends EmptyMomentProvider {
            public const array TABLES = ['own_raw'];
            public const array MOMENTS = ['own_raw' => ['0', '00', '-1', '1']];
            public const array STORAGE = ['own_raw' => [
                '0' => ' SQL DATETIME; external archive contract ',
                '00' => 'seconds; separate archive contract',
                '-1' => 'ISO UTC; historical archive contract',
            ]];
        };
        $moments = MomentColumns::forProvider($provider::class);
        self::assertSame(['0', '00', '-1', '1'], array_column($moments['own_raw'], 'column'));
        self::assertEquals(['own_raw' => [
            '0' => new MomentColumn('0', false, false, ' SQL DATETIME; external archive contract '),
            '00' => new MomentColumn('00', false, false, 'seconds; separate archive contract'),
            '-1' => new MomentColumn('-1', false, false, 'ISO UTC; historical archive contract'),
            '1' => new MomentColumn('1', false, false),
        ]], $moments);
    }

    /** Numeric string tables support explicit empty moment lists and their own storage maps. */
    public function testNumericStringProviderTables(): void
    {
        $provider = new class extends EmptyMomentProvider {
            public const array TABLES = ['0', '00', '-1', '1'];
            public const array MOMENTS = ['0' => ['at'], '00' => ['at'], '-1' => ['at'], '1' => []];
            public const array STORAGE = [
                '0' => ['at' => ' SQL DATETIME; external archive contract '],
                '00' => ['at' => 'seconds; separate archive contract'],
                '-1' => ['at' => 'ISO UTC; historical archive contract'],
            ];
        };
        self::assertEquals([
            '0' => ['at' => new MomentColumn('at', false, false, ' SQL DATETIME; external archive contract ')],
            '00' => ['at' => new MomentColumn('at', false, false, 'seconds; separate archive contract')],
            '-1' => ['at' => new MomentColumn('at', false, false, 'ISO UTC; historical archive contract')],
            '1' => [],
        ], MomentColumns::forProvider($provider::class));
    }

    /** PURGE affects restore policy, never the semantics of the moments in that physical table. */
    public function testFrameworkRawAndBootstrapMoments(): void
    {
        $moments = MomentColumns::forProvider(FrameworkTablesWithoutEntity::class);
        self::assertEqualsCanonicalizing(FrameworkTablesWithoutEntity::tables(), array_keys($moments));
        self::assertSame([], $moments['migration']);
        foreach ([
            'hilos_analytics_payload_json' => 'created_ts',
            'hilos_analytics_page_params' => 'created_ts',
            'hilos_analytics_person_event' => 'created_ts',
            MigrationClaim::TABLE => 'claimed_at',
            AdminViewModeLatchTable::TABLE => 'closed_at',
            DatabaseMarker::TABLE => 'written_at',
        ] as $table => $column) {
            self::assertEquals(new MomentColumn($column, true, false), $moments[$table][$column]);
        }
        self::assertSame(AnonymizationStrategy::PURGE, FrameworkTablesWithoutEntity::pii()['hilos_analytics_person_event']);
        self::assertSame(['from_ts', 'to_ts'], array_keys($moments['hilos_analytics_loss']));
        foreach ($moments as $columns) {
            foreach ($columns as $moment) {
                self::assertTrue($moment->frameworkOwned);
                self::assertFalse($moment->mapped);
                self::assertNull($moment->projectStorage);
            }
        }
    }

    /** Metadata remains readable before any database configuration, facade contexts or backup activation. */
    public function testReadBeforeDatabaseAndContextInitialization(): void
    {
        $configurations = new ReflectionProperty(Database::class, 'configurations');
        $savedConfigurations = $configurations->getValue();
        $savedDb = Hilos::$db;
        $savedRt = Hilos::$rt;
        $savedEnv = Hilos::$env;
        $savedSetting = Hilos::$setting;
        try {
            $configurations->setValue(null, []);
            Hilos::$db = null;
            Hilos::$rt = null;
            Hilos::$env = null;
            Hilos::$setting = null;
            self::assertSame([], Database::getConfiguredIndices());
            self::assertTrue(MomentColumns::forEntity(User::class)[User::last_activity]->frameworkOwned);
            self::assertFalse(MomentColumns::forEntity(OwnMomentEntity::class)['happened_at']->frameworkOwned);
            self::assertArrayHasKey(DatabaseMarker::TABLE, MomentColumns::forProvider(FrameworkTablesWithoutEntity::class));
            self::assertArrayHasKey('own_raw', MomentColumns::forProvider(OwnMomentProvider::class));
            self::assertSame([], Database::getConfiguredIndices());
            self::assertNull(Hilos::$db);
            self::assertNull(Hilos::$rt);
        } finally {
            $configurations->setValue(null, $savedConfigurations);
            Hilos::$db = $savedDb;
            Hilos::$rt = $savedRt;
            Hilos::$env = $savedEnv;
            Hilos::$setting = $savedSetting;
        }
    }
}

class NoMomentEntity extends Entity
{
    public const string id = 'id';
    public const string calendar_day = 'calendar_day';
    public const string happened_at = 'happened_at';
    public const string observed_at = 'observed_at';
    public const string _table = 'moment_record';
    public const array _columns = [self::id, self::calendar_day, self::happened_at];
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::calendar_day => PhpType::DATE->value,
        self::happened_at => PhpType::DATETIME->value,
    ];
}

class OwnMomentEntity extends NoMomentEntity
{
    public const array _moments = [self::happened_at];
}

class CustomMomentEntity extends OwnMomentEntity
{
    public const array _momentStorage = [self::happened_at => ' SQL UTC datetime; external archive contract '];
}

class ComposedMomentEntity extends CustomMomentEntity
{
    public const array _moments = [...parent::_moments, self::observed_at];
}

final class StandardAgainMomentEntity extends ComposedMomentEntity
{
    public const array _momentStorage = [];
}

class LostMomentEntity extends OwnMomentEntity
{
    public const array _moments = [];
}

final class ReintroducedMomentEntity extends LostMomentEntity
{
    public const array _moments = [self::happened_at];
}

class EmptyMomentProvider implements TablesWithoutEntityProvider
{
    public const array TABLES = [];
    public const array MOMENTS = [];
    public const array STORAGE = [];

    /** @return list<string> Fixture table names */
    public static function tables(): array
    {
        return static::TABLES;
    }

    /** @return array<string, list<string>> Fixture physical moments */
    public static function moments(): array
    {
        return static::MOMENTS;
    }

    /** @return array<string, array<string, string>> Fixture project storage exceptions */
    public static function momentStorage(): array
    {
        return static::STORAGE;
    }

    /** @return array<string, array<string, AnonymizationStrategy>> Verdicts are outside this reader's contract */
    public static function pii(): array
    {
        return [];
    }

    /** @return array<string, list<string>> Verdicts are outside this reader's contract */
    public static function piiNotPersonal(): array
    {
        return [];
    }
}

class OwnMomentProvider extends EmptyMomentProvider
{
    public const array TABLES = ['own_raw'];
    public const array MOMENTS = ['own_raw' => ['at']];
}

class CustomMomentProvider extends OwnMomentProvider
{
    public const array STORAGE = ['own_raw' => ['at' => ' SQL; external source ']];
}

final class LostProviderMoment extends OwnMomentProvider
{
    public const array MOMENTS = ['own_raw' => []];
}

final class LostProviderTable extends OwnMomentProvider
{
    public const array TABLES = [];
    public const array MOMENTS = [];
}
