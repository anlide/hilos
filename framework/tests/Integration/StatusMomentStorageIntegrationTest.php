<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\EnvConstants;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\StatusIncident;
use Hilos\Database\Entity\Item\StatusIncidentUpdate;
use Hilos\Database\Entity\Item\StatusMaintenancePeriod;
use Hilos\Database\Entity\Item\StatusSubscriber;
use Hilos\Database\Entity\Item\StatusWork;
use Hilos\Database\Migration;
use Hilos\Database\Migration\FrameworkMigrationCatalog;
use Hilos\Database\Migration\FrameworkSchemaCatalog;
use Hilos\Database\MigrationSqlReader;
use Hilos\Database\Schema\EntitySchemaAudit;
use Hilos\Hilos;
use ReflectionProperty;

/**
 * Validates status signed milliseconds migration on a disposable schema outside rollback scopes (HIL-1928).
 */
final class StatusMomentStorageIntegrationTest extends FrameworkIntegrationTestCase
{
    private const string CHANGE = '20261109203922000000_HIL-1928_status_signed_moments';
    private const int DOWN_TARGET_INDEX = 132;
    private const array ENTITIES = [
        StatusIncident::class,
        StatusIncidentUpdate::class,
        StatusMaintenancePeriod::class,
        StatusSubscriber::class,
        StatusWork::class,
    ];

    private string $database;
    private string $root;
    private array $previousMigration = [];

    /** Opens a disposable schema, builds legacy unsigned status tables and copies the delivered change pairs. */
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['migrationListPath', 'migrationName', 'initialized'] as $property) {
            $this->previousMigration[$property] = new ReflectionProperty(Migration::class, $property)->getValue();
        }
        new ReflectionProperty(Migration::class, 'initialized')->setValue(null, []);
        $this->database = 'hilos-status-moments-' . bin2hex(random_bytes(6));
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

        $chatSchema = dirname(__DIR__, 3) . '/demo/chat/backend/Database/Migration/Schema';
        $baseFiles = [
            '102_framework_install_status_incident.sql',
            '103_framework_install_status_incident_update.sql',
            '104_framework_install_status_subscriber.sql',
            '106_framework_install_status_work.sql',
            '107_framework_install_status_work_component.sql',
            '108_framework_install_status_maintenance_period.sql',
            '109_framework_change_313e4c04d843d20b85a3a36751ce9c28e0205866b4ca48df51362e6565afc3c0.sql',
        ];
        foreach ($baseFiles as $file) {
            $sql = file_get_contents($chatSchema . '/' . $file);
            foreach (MigrationSqlReader::statements($sql) as $statement) {
                Database::sql($statement);
            }
        }

        Migration::setMigrationListPath($this->root);
        Migration::setMigrationName('Schema');
        $catalog = new FrameworkMigrationCatalog(new FrameworkSchemaCatalog());
        $copied = 0;
        foreach (glob($chatSchema . '/*.sql') as $file) {
            if (!str_contains(file_get_contents($file), self::CHANGE)) {
                continue;
            }
            copy($file, $this->root . '/Schema/' . basename($file));
            $copied++;
        }
        $this->assertSame(10, $copied, 'All five generated up/down pairs must be delivered');
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

    public function testDeliveredUpPreservesMillisecondsAndZeroAndNullWithoutRescaling(): void
    {
        Database::sql(
            'INSERT INTO `hilos_status_incident` ('
            . '`id`, `component_key`, `component_name`, `severity`, '
            . '`detected_at`, `publish_at`, `published_at`, `suppressed_at`, `resolved_at`'
            . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [1, 'web', 'Web', 'major_outage', 1700000000123, 1700000000456, 1700000000789, null, null],
        );
        Database::sql(
            'INSERT INTO `hilos_status_incident` ('
            . '`id`, `component_key`, `component_name`, `severity`, '
            . '`detected_at`, `publish_at`, `published_at`, `suppressed_at`, `resolved_at`'
            . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [2, 'api', 'API', 'minor_outage', 0, 0, null, 0, 4102444800123],
        );
        Database::sql(
            'INSERT INTO `hilos_status_incident_update` (`id`, `incident_id`, `kind`, `severity`, `text`, `at`) VALUES (?, ?, ?, ?, ?, ?)',
            [1, 1, 'automatic_detected', 'major_outage', 'Update text', 1700000000123],
        );
        Database::sql(
            'INSERT INTO `hilos_status_subscriber` ('
            . '`id`, `email`, `subscribed_at`, `confirmed`, `confirmed_at`, `confirmation_expires_at`'
            . ') VALUES (?, ?, ?, ?, ?, ?)',
            [1, 'user@example.com', 1700000000123, 1, 4102444800123, null],
        );
        Database::sql(
            'INSERT INTO `hilos_status_work` ('
            . '`id`, `public_id`, `title`, `starts_at`, `ends_at`, `created_at`, `updated_at`, '
            . '`canceled_at`, `consumed_at`, `expired_at`, `version`, `last_mail_queued_at`'
            . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                1, 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4', 'Planned maintenance',
                1700000000123, 4102444800123, 0, 1700000000123, null, null, null, 1, 1700000000123,
            ],
        );
        Database::sql(
            'INSERT INTO `hilos_status_maintenance_period` ('
            . '`id`, `public_id`, `announcement_known`, `title`, `component_keys_json`, '
            . '`started_at`, `last_observed_at`, `ended_at`, `completion_observed_at`'
            . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                1, 'b1c2d3e4f5a6b1c2d3e4f5a6b1c2d3e4', 0, 'Observed work', '["web"]',
                0, 1700000000123, null, 4102444800123,
            ],
        );

        $this->assertSame(5, Migration::migrateUp());

        $inc1 = Database::sql('SELECT * FROM `hilos_status_incident` WHERE `id` = 1')->row();
        $this->assertSame('1700000000123', (string)$inc1['detected_at']);
        $this->assertSame('1700000000456', (string)$inc1['publish_at']);
        $this->assertSame('1700000000789', (string)$inc1['published_at']);
        $this->assertNull($inc1['suppressed_at']);
        $this->assertNull($inc1['resolved_at']);

        $inc2 = Database::sql('SELECT * FROM `hilos_status_incident` WHERE `id` = 2')->row();
        $this->assertSame('0', (string)$inc2['detected_at']);
        $this->assertSame('0', (string)$inc2['publish_at']);
        $this->assertNull($inc2['published_at']);
        $this->assertSame('0', (string)$inc2['suppressed_at']);
        $this->assertSame('4102444800123', (string)$inc2['resolved_at']);

        $upd = Database::sql('SELECT * FROM `hilos_status_incident_update` WHERE `id` = 1')->row();
        $this->assertSame('1700000000123', (string)$upd['at']);

        $sub = Database::sql('SELECT * FROM `hilos_status_subscriber` WHERE `id` = 1')->row();
        $this->assertSame('1700000000123', (string)$sub['subscribed_at']);
        $this->assertSame('4102444800123', (string)$sub['confirmed_at']);
        $this->assertNull($sub['confirmation_expires_at']);

        $work = Database::sql('SELECT * FROM `hilos_status_work` WHERE `id` = 1')->row();
        $this->assertSame('1700000000123', (string)$work['starts_at']);
        $this->assertSame('4102444800123', (string)$work['ends_at']);
        $this->assertSame('0', (string)$work['created_at']);
        $this->assertSame('1700000000123', (string)$work['updated_at']);
        $this->assertNull($work['canceled_at']);
        $this->assertSame('1700000000123', (string)$work['last_mail_queued_at']);

        $period = Database::sql('SELECT * FROM `hilos_status_maintenance_period` WHERE `id` = 1')->row();
        $this->assertSame('0', (string)$period['started_at']);
        $this->assertSame('1700000000123', (string)$period['last_observed_at']);
        $this->assertNull($period['ended_at']);
        $this->assertSame('4102444800123', (string)$period['completion_observed_at']);

        $this->assertSame([], EntitySchemaAudit::audit(self::ENTITIES));
        $this->assertSame([], EntitySchemaAudit::auditMoments(self::ENTITIES));
    }

    public function testDownRestoresUnsignedPreservingRepresentableValuesAndReUpDoesNotRescale(): void
    {
        $this->assertSame(5, Migration::migrateUp());

        Database::sql(
            'INSERT INTO `hilos_status_incident` ('
            . '`id`, `component_key`, `component_name`, `severity`, '
            . '`detected_at`, `publish_at`, `published_at`, `suppressed_at`, `resolved_at`'
            . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [1, 'web', 'Web', 'major_outage', 1700000000123, 1700000000456, null, null, null],
        );
        Database::sql(
            'INSERT INTO `hilos_status_incident_update` (`id`, `incident_id`, `kind`, `severity`, `text`, `at`) VALUES (?, ?, ?, ?, ?, ?)',
            [1, 1, 'automatic_detected', 'major_outage', 'Update text', 0],
        );
        Database::sql(
            'INSERT INTO `hilos_status_subscriber` ('
            . '`id`, `email`, `subscribed_at`, `confirmed`, `confirmed_at`, `confirmation_expires_at`'
            . ') VALUES (?, ?, ?, ?, ?, ?)',
            [1, 'user@example.com', 0, 1, 4102444800123, null],
        );
        Database::sql(
            'INSERT INTO `hilos_status_work` ('
            . '`id`, `public_id`, `title`, `starts_at`, `ends_at`, `created_at`, `updated_at`, '
            . '`canceled_at`, `consumed_at`, `expired_at`, `version`, `last_mail_queued_at`'
            . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                1, 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4', 'Planned maintenance',
                1700000000123, 4102444800123, 0, 1700000000123, null, null, null, 1, null,
            ],
        );
        Database::sql(
            'INSERT INTO `hilos_status_maintenance_period` ('
            . '`id`, `public_id`, `announcement_known`, `title`, `component_keys_json`, '
            . '`started_at`, `last_observed_at`, `ended_at`, `completion_observed_at`'
            . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                1, 'b1c2d3e4f5a6b1c2d3e4f5a6b1c2d3e4', 0, 'Observed work', '["web"]',
                0, 1700000000123, null, null,
            ],
        );

        $this->assertSame(5, Migration::migrateDown(self::DOWN_TARGET_INDEX));

        foreach ([
            'hilos_status_incident' => ['detected_at', 'publish_at', 'published_at', 'suppressed_at', 'resolved_at'],
            'hilos_status_incident_update' => ['at'],
            'hilos_status_subscriber' => ['subscribed_at', 'confirmed_at', 'confirmation_expires_at'],
            'hilos_status_work' => [
                'starts_at', 'ends_at', 'created_at', 'updated_at',
                'canceled_at', 'consumed_at', 'expired_at', 'last_mail_queued_at',
            ],
            'hilos_status_maintenance_period' => ['started_at', 'last_observed_at', 'ended_at', 'completion_observed_at'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                $info = Database::sql("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'")->row();
                $this->assertStringContainsString(
                    'unsigned',
                    strtolower($info['Type']),
                    "Column {$table}.{$column} must be unsigned after down",
                );
            }
        }

        $inc1 = Database::sql('SELECT * FROM `hilos_status_incident` WHERE `id` = 1')->row();
        $this->assertSame('1700000000123', (string)$inc1['detected_at']);
        $this->assertSame('1700000000456', (string)$inc1['publish_at']);

        $this->assertSame(5, Migration::migrateUp());

        foreach ([
            'hilos_status_incident' => ['detected_at', 'publish_at', 'published_at', 'suppressed_at', 'resolved_at'],
            'hilos_status_incident_update' => ['at'],
            'hilos_status_subscriber' => ['subscribed_at', 'confirmed_at', 'confirmation_expires_at'],
            'hilos_status_work' => [
                'starts_at', 'ends_at', 'created_at', 'updated_at',
                'canceled_at', 'consumed_at', 'expired_at', 'last_mail_queued_at',
            ],
            'hilos_status_maintenance_period' => ['started_at', 'last_observed_at', 'ended_at', 'completion_observed_at'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                $info = Database::sql("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'")->row();
                $this->assertMatchesRegularExpression(
                    '/^bigint(?:\([0-9]+\))?$/',
                    strtolower($info['Type']),
                    "Column {$table}.{$column} must be signed bigint after re-up",
                );
            }
        }

        $reUpInc = Database::sql('SELECT * FROM `hilos_status_incident` WHERE `id` = 1')->row();
        $this->assertSame('1700000000123', (string)$reUpInc['detected_at']);
        $this->assertSame('1700000000456', (string)$reUpInc['publish_at']);
    }

    public function testDownRefusesWhenNegativeMomentsExistAndLeavesTableUntouched(): void
    {
        $this->assertSame(5, Migration::migrateUp());

        Database::sql(
            'INSERT INTO `hilos_status_incident` ('
            . '`id`, `component_key`, `component_name`, `severity`, `detected_at`, `publish_at`'
            . ') VALUES (?, ?, ?, ?, ?, ?)',
            [1, 'web', 'Web', 'major_outage', -1, 1000],
        );

        $beforeRows = Database::sql('SELECT * FROM `hilos_status_incident`')->rows();
        $beforeColumn = Database::sql("SHOW COLUMNS FROM `hilos_status_incident` LIKE 'detected_at'")->row();

        try {
            Migration::migrateDown(self::DOWN_TARGET_INDEX);
            $this->fail('A negative moment must cause DOWN to refuse');
        } catch (DatabaseException $exception) {
            $this->assertStringContainsString('range', strtolower($exception->getMessage()));
        }

        $afterRows = Database::sql('SELECT * FROM `hilos_status_incident`')->rows();
        $afterColumn = Database::sql("SHOW COLUMNS FROM `hilos_status_incident` LIKE 'detected_at'")->row();

        $this->assertSame($beforeRows, $afterRows);
        $this->assertSame($beforeColumn['Type'], $afterColumn['Type']);
        $this->assertMatchesRegularExpression('/^bigint(?:\([0-9]+\))?$/', strtolower($afterColumn['Type']));
    }
}
