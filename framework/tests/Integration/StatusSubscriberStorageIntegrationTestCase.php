<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Actions\Collection\StatusSubscribersActions;
use Hilos\Database\Actions\Item\StatusSubscriberActions;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Object\Collection\StatusSubscribers as ObjectStatusSubscribers;
use Hilos\Database\Object\Objects;
use Hilos\Database\Schema\Schema;
use Hilos\Database\View\Collection\StatusSubscribers;
use Hilos\Hilos;
use Hilos\Status\StatusAgent;
use ReflectionProperty;

/** Keeps schema preparation and environment restoration outside marked test cases. */
abstract class StatusSubscriberStorageIntegrationTestCase extends FrameworkRollbackIntegrationTestCase
{
    private const string OWNER = 'status-subscriber-storage';

    protected StatusSubscriberSyncMirror $syncMirror;

    private ?int $ownerToken = null;
    /** @var array Original mirror registrations restored after each case */
    private array $previousMirrors = [];

    /** Raises the new table and mounts its production chain before the rollback scope opens. */
    protected function prepareIntegrationEnvironment(): void
    {
        parent::prepareIntegrationEnvironment();
        Database::sqlRun(file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_hilos_status_subscriber.sql'));
        Schema::reset();
        Schema::initialize();
        OwnershipDeclaration::claimDb(StatusAgent::class, self::OWNER);
        $this->ownerToken = ExecutionContext::push(new ExecutionFrame(agentId: self::OWNER));
        Hilos::$db = new StatusSubscriberStorageDbContext();
        Hilos::$db->configure();
        $this->previousMirrors = (new ReflectionProperty(SourceChangeBus::class, 'mirrors'))->getValue();
        $this->syncMirror = new StatusSubscriberSyncMirror();
        SourceChangeBus::subscribe($this->syncMirror);
    }

    /** Drops the table after rollback and releases the owner before restoring the inherited environment. */
    protected function releaseIntegrationEnvironment(): void
    {
        try {
            Database::sqlRun('DROP TABLE IF EXISTS `hilos_status_subscriber`');
        } finally {
            (new ReflectionProperty(SourceChangeBus::class, 'mirrors'))->setValue(null, $this->previousMirrors);
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
     * @param int $id Persisted primary key
     * @return array<string, mixed> Independent SQL storage row, including DB-only secrets for assertions
     */
    protected function stored(int $id): array
    {
        return Database::sql('SELECT * FROM `hilos_status_subscriber` WHERE `id` = ?', [$id])->first()->first();
    }
}

/** Mounts only the new production chain while the environment base owns its own fixture tables. */
final class StatusSubscriberStorageDbContext extends HilosDbContext
{
    /** Configures the lazy production chain without requiring other Status feature dependencies. */
    public function configure(): void
    {
        $this->_objectCollections[self::statusSubscribers] = ObjectStatusSubscribers::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::statusSubscribers, StatusSubscribers::class, StatusSubscribersActions::class, StatusSubscriberActions::class);
    }
}

/** Captures the same scalar rows and diffs that feed the DB-sync publication. */
final class StatusSubscriberSyncMirror implements SourceMirrorSubscriberInterface
{
    /** @var list<array<string, mixed>> Non-secret subscription row facts */
    public array $rows = [];

    /**
     * @param SourceChange $change Store mutation
     * @param SourceChangeProvenance $provenance Origin of the write
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($change->sourceKey === HilosDbContext::statusSubscribers) {
            $this->rows[] = $change->row;
        }
    }
}
