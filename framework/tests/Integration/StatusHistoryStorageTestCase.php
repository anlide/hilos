<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Actions\Collection\StatusIncidentsActions;
use Hilos\Database\Actions\Collection\StatusMaintenancePeriodsActions;
use Hilos\Database\Actions\Item\StatusIncidentActions;
use Hilos\Database\Actions\Item\StatusMaintenancePeriodActions;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Object\Collection\StatusIncidents as ObjectStatusIncidents;
use Hilos\Database\Object\Collection\StatusMaintenancePeriods as ObjectStatusMaintenancePeriods;
use Hilos\Database\Object\Objects;
use Hilos\Database\Schema\Schema;
use Hilos\Database\View\Collection\StatusIncidents;
use Hilos\Database\View\Collection\StatusMaintenancePeriods;
use Hilos\Hilos;
use Hilos\Status\StatusAgent;

/** Creates schema before the managed rollback root and restores the production facade afterwards. */
abstract class StatusHistoryStorageTestCase extends FrameworkRollbackIntegrationTestCase
{
    private const string OWNER = 'status-history-storage';
    private const array TABLES = ['hilos_status_work', 'hilos_status_maintenance_period', 'hilos_status_incident'];

    private ?int $ownerToken = null;

    /** Installs current stubs outside the rollback root and mounts the two real production read paths. */
    protected function prepareIntegrationEnvironment(): void
    {
        parent::prepareIntegrationEnvironment();
        foreach (self::TABLES as $table) {
            Database::sqlRun(file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . '.sql'));
        }
        Schema::reset();
        Schema::initialize();
        OwnershipDeclaration::claimDb(StatusAgent::class, self::OWNER);
        $this->ownerToken = ExecutionContext::push(new ExecutionFrame(agentId: self::OWNER));
        Hilos::$db = new StatusHistoryStorageDbContext();
        Hilos::$db->configure();
    }

    /** Releases fixture schema after rollback and drops the temporary owner/read declarations. */
    protected function releaseIntegrationEnvironment(): void
    {
        try {
            foreach (array_reverse(self::TABLES) as $table) {
                Database::sqlRun('DROP TABLE IF EXISTS `' . $table . '`');
            }
        } finally {
            TruthSourceRegistry::unregisterAgent(self::OWNER);
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(self::OWNER));
            if ($this->ownerToken !== null) {
                ExecutionContext::pop($this->ownerToken);
                $this->ownerToken = null;
            }
            parent::releaseIntegrationEnvironment();
        }
    }

    /**
     * @param int $id Explicit numeric primary key
     * @param int $at Start in epoch milliseconds UTC
     * @param ?string $outcome Null for an open fact
     * @param ?int $publishedAt Publication timestamp
     * @param string $title Stored component name
     */
    protected function incident(
        int $id,
        int $at,
        ?string $outcome = 'resolved',
        ?int $publishedAt = 1500,
        string $title = 'Saved 50%_! component',
    ): void {
        Database::sql('INSERT INTO `hilos_status_incident` '
            . '(`id`, `component_key`, `component_name`, `severity`, `peak_severity`, `detected_at`, `publish_at`, '
            . '`published_at`, `suppressed_at`, `resolved_at`, `outcome`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, 'retired', $title, 'partial_outage', 'major_outage', $at, 1500, $publishedAt,
                $publishedAt === null ? 1200 : null, $outcome === null ? null : 3000, $outcome]);
    }

    /**
     * @param int $id Explicit numeric primary key
     * @param int $at Start in epoch milliseconds UTC
     * @param bool $completed Whether completion was observed
     * @param ?int $endedAt Exact end, null when continuity was lost
     * @param string $keys Stored JSON component set
     * @param string $title Stored announcement title
     */
    protected function period(
        int $id,
        int $at,
        bool $completed = true,
        ?int $endedAt = null,
        string $keys = '["retired","api"]',
        string $title = 'Saved 50%_! work',
    ): void {
        Database::sql('INSERT INTO `hilos_status_maintenance_period` '
            . '(`id`, `public_id`, `work_public_id`, `announcement_known`, `title`, `component_keys_json`, '
            . '`started_at`, `last_observed_at`, `ended_at`, `completion_observed_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, str_pad((string)$id, 32, '0', STR_PAD_LEFT), str_repeat('b', 32), true, $title, $keys,
                $at, $at, $endedAt, $completed ? 3000 : null]);
    }
}

/** Production Entity/Object/View/action chains with the same lazy-key strategy as the Status feature. */
final class StatusHistoryStorageDbContext extends HilosDbContext
{
    /** Mounts the two history sources without enabling unrelated pages or runtime state. */
    public function configure(): void
    {
        $this->_objectCollections[self::statusIncidents] = ObjectStatusIncidents::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::statusIncidents, StatusIncidents::class, StatusIncidentsActions::class, StatusIncidentActions::class);
        $this->_objectCollections[self::statusMaintenancePeriods] = ObjectStatusMaintenancePeriods::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::statusMaintenancePeriods, StatusMaintenancePeriods::class,
            StatusMaintenancePeriodsActions::class, StatusMaintenancePeriodActions::class);
    }
}
