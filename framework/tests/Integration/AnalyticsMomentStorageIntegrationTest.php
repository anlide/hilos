<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\EnvConstants;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration;
use Hilos\Database\Migration\FrameworkMigrationCatalog;
use Hilos\Database\Migration\FrameworkSchemaCatalog;
use Hilos\Database\MigrationSqlReader;
use Hilos\Hilos;
use ReflectionProperty;

/**
 * Validates analytics signed milliseconds migration on a disposable schema outside rollback scopes (HIL-1927).
 */
final class AnalyticsMomentStorageIntegrationTest extends FrameworkIntegrationTestCase
{
    private const string CHANGE = '20261109185924000000_HIL-1927_analytics_signed_moments';

    private string $database;
    private string $root;
    private array $previousMigration = [];

    /** Opens a disposable schema, builds legacy unsigned analytics tables and copies the delivered change pair. */
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['migrationListPath', 'migrationName', 'initialized'] as $property) {
            $this->previousMigration[$property] = new ReflectionProperty(Migration::class, $property)->getValue();
        }
        new ReflectionProperty(Migration::class, 'initialized')->setValue(null, []);
        $this->database = 'hilos-analytics-moments-' . bin2hex(random_bytes(6));
        $this->root = sys_get_temp_dir() . '/' . $this->database;
        mkdir($this->root . '/Schema', 0700, true);

        $config = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::close(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::configure(DatabaseConnectionDefaults::PRIMARY_INDEX,
            $config->host, 'root', Hilos::$env[EnvConstants::DB_ROOT_PASSWORD]->string(),
            $config->database, $config->port, $config->charset);
        Database::connect();
        Database::sql('CREATE DATABASE `' . $this->database . '`');
        Database::close();
        Database::configure(DatabaseConnectionDefaults::PRIMARY_INDEX,
            $config->host, 'root', Hilos::$env[EnvConstants::DB_ROOT_PASSWORD]->string(),
            $this->database, $config->port, $config->charset);
        Database::connect();

        $legacyDdl = file_get_contents(dirname(__DIR__, 3) . '/demo/tasks/backend/Database/Migration/Schema/048_create_hilos_analytics.sql');
        foreach (MigrationSqlReader::statements($legacyDdl) as $statement) {
            Database::sql($statement);
        }

        Migration::setMigrationListPath($this->root);
        Migration::setMigrationName('Schema');
        $catalog = new FrameworkMigrationCatalog(new FrameworkSchemaCatalog());
        $copied = 0;
        foreach (glob(dirname(__DIR__, 3) . '/demo/chat/backend/Database/Migration/Schema/*.sql') as $file) {
            if (!str_contains(file_get_contents($file), self::CHANGE)) {
                continue;
            }
            copy($file, $this->root . '/Schema/' . basename($file));
            $copied++;
        }
        $this->assertSame(2, $copied, 'The delivered analytics up/down pair must be copied');
        foreach (glob($this->root . '/Schema/*.sql') as $file) {
            if (!str_ends_with($file, '_down.sql')) {
                $catalog->verifyPair(file_get_contents($file), file_get_contents(substr($file, 0, -4) . '_down.sql'));
            }
        }
        Migration::initialize();
    }

    /** Drops owned database and restores caller migration configuration. */
    protected function tearDown(): void
    {
        try {
            Database::sql('DROP DATABASE IF EXISTS `' . $this->database . '`');
            foreach (glob($this->root . '/Schema/*') as $file) {
                unlink($file);
            }
            rmdir($this->root . '/Schema');
            rmdir($this->root);
        } finally {
            foreach ($this->previousMigration as $property => $value) {
                new ReflectionProperty(Migration::class, $property)->setValue(null, $value);
            }
            parent::tearDown();
        }
    }

    public function testDeliveredUpPreservesMillisecondsAndNullWithoutRescalingAndAcceptsNegativeMoments(): void
    {
        Database::sql(
            'INSERT INTO `hilos_analytics_user_agent` (`id`, `sha1_hash`, `value`, `created_ts`) VALUES (?, UNHEX(SHA1(?)), ?, ?)',
            [1, 'ua_1', 'ua_1', 1700000000123],
        );
        Database::sql(
            'INSERT INTO `hilos_analytics_ws_connection` (`id`, `accept_key`, `opened_ts`, `closed_ts`) VALUES (?, ?, ?, ?)',
            [1, 'key_1', 1700000000456, null],
        );

        $this->assertSame(1, Migration::migrateUp());

        $uaRow = Database::sql('SELECT `created_ts` FROM `hilos_analytics_user_agent` WHERE `id` = 1')->row();
        $this->assertSame('1700000000123', (string)$uaRow['created_ts']);

        $wsRow = Database::sql('SELECT `opened_ts`, `closed_ts` FROM `hilos_analytics_ws_connection` WHERE `id` = 1')->row();
        $this->assertSame('1700000000456', (string)$wsRow['opened_ts']);
        $this->assertNull($wsRow['closed_ts']);

        $uaColumn = Database::sql("SHOW COLUMNS FROM `hilos_analytics_user_agent` LIKE 'created_ts'")->row();
        $this->assertMatchesRegularExpression('/^bigint(?:\([0-9]+\))?$/', strtolower($uaColumn['Type']));
        $this->assertSame('NO', $uaColumn['Null']);

        $wsColumn = Database::sql("SHOW COLUMNS FROM `hilos_analytics_ws_connection` LIKE 'closed_ts'")->row();
        $this->assertMatchesRegularExpression('/^bigint(?:\([0-9]+\))?$/', strtolower($wsColumn['Type']));
        $this->assertSame('YES', $wsColumn['Null']);

        Database::sql(
            'INSERT INTO `hilos_analytics_user_agent` (`id`, `sha1_hash`, `value`, `created_ts`) VALUES (?, UNHEX(SHA1(?)), ?, ?)',
            [2, 'ua_zero', 'ua_zero', 0],
        );
        Database::sql(
            'INSERT INTO `hilos_analytics_user_agent` (`id`, `sha1_hash`, `value`, `created_ts`) VALUES (?, UNHEX(SHA1(?)), ?, ?)',
            [3, 'ua_neg', 'ua_neg', -1500],
        );

        $zeroRow = Database::sql('SELECT `created_ts` FROM `hilos_analytics_user_agent` WHERE `id` = 2')->row();
        $this->assertSame('0', (string)$zeroRow['created_ts']);

        $negRow = Database::sql('SELECT `created_ts` FROM `hilos_analytics_user_agent` WHERE `id` = 3')->row();
        $this->assertSame('-1500', (string)$negRow['created_ts']);
    }

    public function testDownRestoresUnsignedPreservingRepresentableValuesAndReUpDoesNotRescale(): void
    {
        $this->assertSame(1, Migration::migrateUp());

        Database::sql(
            'INSERT INTO `hilos_analytics_user_agent` (`id`, `sha1_hash`, `value`, `created_ts`) VALUES (?, UNHEX(SHA1(?)), ?, ?)',
            [1, 'ua_zero', 'ua_zero', 0],
        );
        Database::sql(
            'INSERT INTO `hilos_analytics_user_agent` (`id`, `sha1_hash`, `value`, `created_ts`) VALUES (?, UNHEX(SHA1(?)), ?, ?)',
            [2, 'ua_pos', 'ua_pos', 1700000000123],
        );
        Database::sql(
            'INSERT INTO `hilos_analytics_ws_connection` (`id`, `accept_key`, `opened_ts`, `closed_ts`) VALUES (?, ?, ?, ?)',
            [1, 'key_1', 1700000000456, null],
        );

        $this->assertSame(1, Migration::migrateDown(131));

        $uaColumn = Database::sql("SHOW COLUMNS FROM `hilos_analytics_user_agent` LIKE 'created_ts'")->row();
        $this->assertStringContainsString('unsigned', strtolower($uaColumn['Type']));

        $zeroRow = Database::sql('SELECT `created_ts` FROM `hilos_analytics_user_agent` WHERE `id` = 1')->row();
        $this->assertSame('0', (string)$zeroRow['created_ts']);

        $posRow = Database::sql('SELECT `created_ts` FROM `hilos_analytics_user_agent` WHERE `id` = 2')->row();
        $this->assertSame('1700000000123', (string)$posRow['created_ts']);

        $wsRow = Database::sql('SELECT `opened_ts`, `closed_ts` FROM `hilos_analytics_ws_connection` WHERE `id` = 1')->row();
        $this->assertSame('1700000000456', (string)$wsRow['opened_ts']);
        $this->assertNull($wsRow['closed_ts']);

        $this->assertSame(1, Migration::migrateUp());

        $reUpColumn = Database::sql("SHOW COLUMNS FROM `hilos_analytics_user_agent` LIKE 'created_ts'")->row();
        $this->assertMatchesRegularExpression('/^bigint(?:\([0-9]+\))?$/', strtolower($reUpColumn['Type']));

        $reUpPosRow = Database::sql('SELECT `created_ts` FROM `hilos_analytics_user_agent` WHERE `id` = 2')->row();
        $this->assertSame('1700000000123', (string)$reUpPosRow['created_ts']);
    }

    public function testDownRefusesWhenNegativeMomentsExistAndLeavesTableUntouched(): void
    {
        $this->assertSame(1, Migration::migrateUp());

        Database::sql(
            'INSERT INTO `hilos_analytics_user_agent` (`id`, `sha1_hash`, `value`, `created_ts`) VALUES (?, UNHEX(SHA1(?)), ?, ?)',
            [1, 'ua_neg', 'ua_neg', -1500],
        );

        $beforeRows = Database::sql('SELECT * FROM `hilos_analytics_user_agent`')->rows();
        $beforeColumn = Database::sql("SHOW COLUMNS FROM `hilos_analytics_user_agent` LIKE 'created_ts'")->row();

        try {
            Migration::migrateDown(131);
            $this->fail('A negative moment must cause DOWN to refuse');
        } catch (DatabaseException $exception) {
            $this->assertStringContainsString('range', strtolower($exception->getMessage()));
        }

        $afterRows = Database::sql('SELECT * FROM `hilos_analytics_user_agent`')->rows();
        $afterColumn = Database::sql("SHOW COLUMNS FROM `hilos_analytics_user_agent` LIKE 'created_ts'")->row();

        $this->assertSame($beforeRows, $afterRows);
        $this->assertSame($beforeColumn['Type'], $afterColumn['Type']);
        $this->assertMatchesRegularExpression('/^bigint(?:\([0-9]+\))?$/', strtolower($afterColumn['Type']));
    }
}
