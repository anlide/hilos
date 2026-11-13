<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Schema\Schema;
use ReflectionProperty;

/** Adds unchanged notification stubs and a real execution claim to the rollback environment. */
abstract class NotificationDeliveryApiIntegrationTestCase extends FrameworkRollbackIntegrationTestCase
{
    private const string DELIVERY_AGENT = 'notification-delivery-api-test';
    private const array TABLES = ['hilos_notification', 'hilos_notification_delivery', 'hilos_account_deletion'];

    /** @var list<string> Tables raised before the rollback scope */
    private array $deliveryTables = [];
    private ?int $deliveryExecutionToken = null;
    /** @var array<string, mixed> Bus state to restore after the local mirror subscription */
    private array $previousBus = [];

    /** Creates schema before the case root and uses the production context's action mount. */
    protected function prepareIntegrationEnvironment(): void
    {
        parent::prepareIntegrationEnvironment();
        foreach (['mirrors', 'reactions', 'provenance'] as $property) {
            $this->previousBus[$property] = new ReflectionProperty(SourceChangeBus::class, $property)->getValue();
        }
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        foreach (self::TABLES as $table) {
            Database::sqlRun((string)file_get_contents(
                dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . '.sql',
            ));
            $this->deliveryTables[] = $table;
        }
        Schema::reset();
        Schema::initialize();
        TruthSourceRegistry::register(HilosDbContext::notificationDeliveries, TruthSourceKeys::all(), self::DELIVERY_AGENT);
        $this->deliveryExecutionToken = ExecutionContext::push(new ExecutionFrame(agentId: self::DELIVERY_AGENT));
    }

    /** Releases extra schema after rollback/reset, then lets the base restore its facades and connections. */
    protected function releaseIntegrationEnvironment(): void
    {
        try {
            foreach (array_reverse($this->deliveryTables) as $table) {
                Database::sqlRun((string)file_get_contents(
                    dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . '_down.sql',
                ));
            }
        } finally {
            $this->deliveryTables = [];
            TruthSourceRegistry::unregisterAgent(self::DELIVERY_AGENT);
            if ($this->deliveryExecutionToken !== null) {
                ExecutionContext::pop($this->deliveryExecutionToken);
                $this->deliveryExecutionToken = null;
            }
            foreach ($this->previousBus as $property => $value) {
                new ReflectionProperty(SourceChangeBus::class, $property)->setValue(null, $value);
            }
            $this->previousBus = [];
            parent::releaseIntegrationEnvironment();
        }
    }
}
