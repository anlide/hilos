<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Actions\Collection\StatusMaintenancePeriodsActions;
use Hilos\Database\Actions\Collection\StatusWorkComponentsActions;
use Hilos\Database\Actions\Collection\StatusWorksActions;
use Hilos\Database\Actions\Item\StatusMaintenancePeriodActions;
use Hilos\Database\Actions\Item\StatusWorkActions;
use Hilos\Database\Actions\Item\StatusWorkComponentActions;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Object\Collection\StatusMaintenancePeriods as ObjectStatusMaintenancePeriods;
use Hilos\Database\Object\Collection\StatusWorkComponents as ObjectStatusWorkComponents;
use Hilos\Database\Object\Collection\StatusWorks as ObjectStatusWorks;
use Hilos\Database\Object\Objects;
use Hilos\Database\Schema\Schema;
use Hilos\Database\View\Collection\StatusMaintenancePeriods;
use Hilos\Database\View\Collection\StatusWorkComponents;
use Hilos\Database\View\Collection\StatusWorks;
use Hilos\Hilos;
use Hilos\Status\StatusAgent;

/** Owns schema outside the rollback root and restores the existing framework environment. */
abstract class StatusWorksStorageIntegrationTestCase extends FrameworkRollbackIntegrationTestCase
{
    private const string OWNER = 'status-works-storage';
    private const array TABLES = ['hilos_status_work', 'hilos_status_work_component', 'hilos_status_maintenance_period'];

    private ?int $ownerToken = null;

    /** Raises the current stubs and mounts only the real production storage chains. */
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
        Hilos::$db = new StatusWorksStorageDbContext();
        Hilos::$db->configure();
    }

    /** Drops child tables first after rollback, then releases the test's owner and reader interest. */
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
     * @param int $id Period primary id
     * @return array<string, mixed> Independent persisted period
     */
    protected function storedPeriod(int $id): array
    {
        return Database::sql('SELECT * FROM `hilos_status_maintenance_period` WHERE `id` = ?', [$id])->first()->first();
    }

    /**
     * @param int $workId Work primary id
     * @return list<array<string, mixed>> Independent selected rows
     */
    protected function storedComponents(int $workId): array
    {
        return Database::sql('SELECT * FROM `hilos_status_work_component` WHERE `work_id` = ? ORDER BY `component_key`', [$workId])->rows();
    }
}

/** Binds every production Entity/Object/View/action link without enabling unrelated feature surfaces. */
final class StatusWorksStorageDbContext extends HilosDbContext
{
    /** Mounts the three lazy production chains and both action levels. */
    public function configure(): void
    {
        $this->_objectCollections[self::statusWorks] = ObjectStatusWorks::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::statusWorks, StatusWorks::class, StatusWorksActions::class, StatusWorkActions::class);
        $this->_objectCollections[self::statusWorkComponents] = ObjectStatusWorkComponents::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::statusWorkComponents, StatusWorkComponents::class,
            StatusWorkComponentsActions::class, StatusWorkComponentActions::class);
        $this->_objectCollections[self::statusMaintenancePeriods] = ObjectStatusMaintenancePeriods::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::statusMaintenancePeriods, StatusMaintenancePeriods::class,
            StatusMaintenancePeriodsActions::class, StatusMaintenancePeriodActions::class);
    }
}
