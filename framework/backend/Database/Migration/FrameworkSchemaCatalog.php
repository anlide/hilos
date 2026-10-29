<?php

declare(strict_types=1);

namespace Hilos\Database\Migration;

use Hilos\AdminViewMode\AdminViewModeLatchTable;
use Hilos\Core\Feature\Exception\IncompleteFeatureActivationException;
use Hilos\Core\Feature\FeatureRegistry;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseMarker;
use Hilos\Database\MigrationClaim;
use Hilos\Database\Entity\Item\ChangeLogTable;
use Hilos\Database\Entity\Item\ChangeLogField;
use Hilos\Database\Entity\Item\ChangeLogReceipt;
use Hilos\Database\Entity\Item\ChangeLogEntry;
use Hilos\Database\Entity\Item\ChangeLogChange;
use Hilos\Database\Entity\Item\ChangeLogValue;
use Hilos\Database\Schema\FrameworkTablesWithoutEntity;
use Hilos\Hilos;

/** Schema metadata only: no facade initialization, database reads or runtime mounting. */
class FrameworkSchemaCatalog
{
    public const string CHANGE_LOG = 'change_log';

    /** @var list<string> Tables of the aggregate journal schema unit, in creation order */
    public const array CHANGE_LOG_TABLES = [
        ChangeLogTable::_table,
        ChangeLogField::_table,
        ChangeLogReceipt::_table,
        ChangeLogEntry::_table,
        ChangeLogChange::_table,
        ChangeLogValue::_table,
    ];

    private const string USER = 'user';
    private const string SESSION = 'session';
    private const string IDENTITY = 'identity';
    private const string LANGUAGE = 'language';
    private const string COUNTRY = 'country';
    private const string LOCALE = 'locale';
    private const string FILE = 'file';
    private const string VERIFIER_CIRCLE = 'verifier_circle';

    /** @var array<string, FrameworkSchemaUnit> Immutable definitions indexed for this catalog instance */
    private array $units = [];

    /** @var array<int, array<string, string>> Connection and exact table name to its unit key */
    private array $unitsByTable = [];

    /**
     * @param FeatureRegistry $registry Source of feature requirements, replaceable by synthetic definitions in tests
     * @throws DatabaseException When unit keys or tables repeat, a dependency is unknown, or the graph cycles
     */
    public function __construct(private readonly FeatureRegistry $registry = new FeatureRegistry())
    {
        foreach ($this->buildUnits() as $unit) {
            if (isset($this->units[$unit->key])) {
                throw new DatabaseException("Duplicate framework schema unit {$unit->key}");
            }
            $this->units[$unit->key] = $unit;
            foreach ($unit->tables as $table) {
                if (isset($this->unitsByTable[$unit->connection][$table])) {
                    throw new DatabaseException("Table {$table} on connection {$unit->connection} belongs to multiple schema units");
                }
                $this->unitsByTable[$unit->connection][$table] = $unit->key;
            }
        }
        $this->orderedUnits(array_keys($this->units));
    }

    /**
     * Unions the core, runtime boundary and feature requirements, including their dependencies.
     *
     * @param class-string<Hilos> $hilosClass Facade declaration to select for, without making it the active facade
     * @return list<FrameworkSchemaUnit> Each selected unit once, dependencies before dependents
     * @throws DatabaseException When a required table or unit is unknown, or feature dependencies cycle
     * @throws IncompleteFeatureActivationException When a declared or required feature has no registry definition
     */
    public function selectedUnits(string $hilosClass): array
    {
        $keys = [];
        foreach ($this->units as $unit) {
            if ($unit->bootstrapOwned) {
                $keys[$unit->key] = true;
            }
        }
        $keys[self::USER] = true;
        $keys[self::SESSION] = true;
        if (Hilos::usesRuntimeOf($hilosClass)) {
            $keys[self::VERIFIER_CIRCLE] = true;
        }

        $visited = [];
        $visiting = [];
        $visitFeature = function (HilosFeature $feature) use (&$visitFeature, &$keys, &$visited, &$visiting): void {
            if (isset($visited[$feature->value])) {
                return;
            }
            if (isset($visiting[$feature->value])) {
                throw new DatabaseException("Framework schema feature dependency cycle at {$feature->value}");
            }
            $visiting[$feature->value] = true;
            $requirements = $this->registry->definition($feature)->requirements();
            foreach ($requirements->requires as $dependency) {
                $visitFeature($dependency);
            }
            foreach ($requirements->requiredDbTables as $table) {
                $key = $this->unitsByTable[DatabaseConnectionDefaults::PRIMARY_INDEX][$table] ?? null;
                if ($key === null) {
                    throw new DatabaseException("Feature {$feature->value} requires unknown primary schema table {$table}");
                }
                $keys[$key] = true;
            }
            foreach ($requirements->requiredSchemaUnits as $key) {
                $keys[$key] = true;
            }
            unset($visiting[$feature->value]);
            $visited[$feature->value] = true;
        };
        foreach (Hilos::featuresOf($hilosClass) as $feature) {
            $visitFeature($feature);
        }

        return $this->orderedUnits(array_keys($keys));
    }

    /** @return list<FrameworkSchemaUnit> Complete schema metadata, without feature filtering */
    public function allUnits(): array
    {
        return array_values($this->units);
    }

    /**
     * @param string $key Stable schema unit key
     * @return FrameworkSchemaUnit Known unit metadata
     * @throws DatabaseException When the unit is unknown
     */
    public function unit(string $key): FrameworkSchemaUnit
    {
        return $this->units[$key] ?? throw new DatabaseException("Unknown framework schema unit {$key}");
    }

    /**
     * Dependencies describe stub execution order, including the country ALTER in the locale stub.
     * Aggregates keep their existing SQL intact; bootstrap tables deliberately name no stub.
     *
     * @return list<FrameworkSchemaUnit> Framework-owned schema units, independent of feature activation
     * @throws DatabaseException When a unit has inconsistent metadata
     */
    protected function buildUnits(): array
    {
        return [
            new FrameworkSchemaUnit('migration', ['migration'], null, null, bootstrapOwned: true),
            new FrameworkSchemaUnit('migration_claim', [MigrationClaim::TABLE], null, null, bootstrapOwned: true),
            new FrameworkSchemaUnit('admin_view_mode_latch', [AdminViewModeLatchTable::TABLE], null, null, bootstrapOwned: true),
            new FrameworkSchemaUnit('database_marker', [DatabaseMarker::TABLE], null, null, bootstrapOwned: true),
            $this->tableUnit(self::USER, 'hilos_user'),
            $this->tableUnit(self::SESSION, 'hilos_session', [self::USER]),
            $this->tableUnit('setting', 'hilos_setting'),
            $this->tableUnit('notification', 'hilos_notification', [self::USER]),
            $this->tableUnit('notification_preference', 'hilos_notification_preference', [self::USER]),
            $this->tableUnit('push_subscription', 'hilos_push_subscription', [self::USER]),
            $this->tableUnit('notification_delivery', 'hilos_notification_delivery'),
            $this->tableUnit('auth_block', 'hilos_auth_block'),
            $this->tableUnit('user_verification', 'hilos_user_verification', [self::USER]),
            $this->tableUnit(self::IDENTITY, 'hilos_identity', [self::USER]),
            $this->tableUnit('passkey_credential', 'hilos_passkey_credential', [self::IDENTITY, self::USER]),
            $this->tableUnit('registration_reservation', 'hilos_registration_reservation'),
            $this->tableUnit('second_factor', 'hilos_second_factor', [self::USER]),
            $this->tableUnit('second_factor_backup_code', 'hilos_second_factor_backup_code', [self::USER]),
            $this->tableUnit('second_factor_trust', 'hilos_second_factor_trust', [self::USER]),
            $this->tableUnit('second_factor_reset', 'hilos_second_factor_reset', [self::USER]),
            $this->tableUnit('second_factor_setting', 'hilos_second_factor_setting', [self::USER]),
            $this->tableUnit('step_up', 'hilos_step_up', [self::USER]),
            $this->tableUnit('access_log', 'hilos_access_log', [self::USER]),
            $this->tableUnit('account_deletion', 'hilos_account_deletion'),
            $this->tableUnit('legal_acceptance', 'hilos_legal_acceptance', [self::USER]),
            $this->tableUnit('data_export', 'hilos_data_export', [self::USER]),
            $this->tableUnit('user_rename', 'hilos_user_rename', [self::USER]),
            $this->tableUnit('user_merge', 'hilos_user_merge', [self::USER]),
            $this->tableUnit('oauth_provider', 'hilos_oauth_provider'),
            $this->tableUnit('legal_acceptance_export', 'hilos_legal_acceptance_export', [self::USER]),
            $this->tableUnit(self::LANGUAGE, 'hilos_language'),
            $this->tableUnit(self::COUNTRY, 'hilos_country', [self::LANGUAGE]),
            $this->tableUnit(self::LOCALE, 'hilos_locale', [self::LANGUAGE, self::COUNTRY]),
            $this->tableUnit('language_name', 'hilos_language_name', [self::LANGUAGE, self::LOCALE]),
            $this->tableUnit('country_name', 'hilos_country_name', [self::COUNTRY, self::LANGUAGE, self::LOCALE]),
            $this->tableUnit('i18n_reflow', 'hilos_i18n_reflow'),
            $this->tableUnit(self::FILE, 'hilos_file', [self::USER]),
            $this->tableUnit('file_variant', 'hilos_file_variant', [self::FILE]),
            $this->tableUnit('user_photo', 'hilos_user_photo', [self::USER, self::FILE]),
            $this->tableUnit(self::VERIFIER_CIRCLE, 'hilos_verifier_circle'),
            $this->tableUnit('moderation_rule', 'hilos_moderation_rule'),
            $this->tableUnit('status_incident', 'hilos_status_incident'),
            $this->tableUnit('status_incident_update', 'hilos_status_incident_update', ['status_incident']),
            $this->tableUnit('status_subscriber', 'hilos_status_subscriber'),
            $this->tableUnit('status_work', 'hilos_status_work'),
            $this->tableUnit('status_work_component', 'hilos_status_work_component', ['status_work']),
            $this->tableUnit('status_maintenance_period', 'hilos_status_maintenance_period', ['status_work']),
            new FrameworkSchemaUnit(
                'analytics',
                FrameworkTablesWithoutEntity::analyticsTables(),
                __DIR__ . '/Stub/create_hilos_analytics.sql',
                __DIR__ . '/Stub/create_hilos_analytics_down.sql',
            ),
            new FrameworkSchemaUnit(
                self::CHANGE_LOG,
                self::CHANGE_LOG_TABLES,
                __DIR__ . '/Stub/create_hilos_change_log.sql',
                __DIR__ . '/Stub/create_hilos_change_log_down.sql',
                connection: ChangeLogDatabase::CONNECTION_INDEX,
            ),
        ];
    }

    /**
     * @param string $key Unit key
     * @param string $table Exact SQL table name of an existing stub pair
     * @param list<string> $dependencies Units that must precede this stub
     * @return FrameworkSchemaUnit Single-table primary schema unit
     * @throws DatabaseException When the unit metadata is inconsistent
     */
    private function tableUnit(string $key, string $table, array $dependencies = []): FrameworkSchemaUnit
    {
        return new FrameworkSchemaUnit(
            $key,
            [$table],
            __DIR__ . '/Stub/create_' . $table . '.sql',
            __DIR__ . '/Stub/create_' . $table . '_down.sql',
            $dependencies,
        );
    }

    /**
     * @param list<string> $keys Units whose dependency closure to order
     * @return list<FrameworkSchemaUnit> Units in stable dependency order, each once
     * @throws DatabaseException When a unit is unknown or its dependency graph cycles
     */
    private function orderedUnits(array $keys): array
    {
        $ordered = [];
        $visited = [];
        $visiting = [];
        $visit = function (string $key) use (&$visit, &$ordered, &$visited, &$visiting): void {
            if (isset($visited[$key])) {
                return;
            }
            if (isset($visiting[$key])) {
                throw new DatabaseException("Framework schema unit dependency cycle at {$key}");
            }
            $unit = $this->units[$key] ?? throw new DatabaseException("Unknown framework schema unit {$key}");
            $visiting[$key] = true;
            foreach ($unit->dependencies as $dependency) {
                $visit($dependency);
            }
            unset($visiting[$key]);
            $visited[$key] = true;
            $ordered[] = $unit;
        };
        foreach ($keys as $key) {
            $visit($key);
        }

        return $ordered;
    }
}
