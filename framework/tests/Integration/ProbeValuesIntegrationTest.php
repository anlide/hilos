<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Hilos;

/** Real probe storage and the per-node write boundary, without switching the live probe agent. */
final class ProbeValuesIntegrationTest extends FrameworkIntegrationTestCase
{
    private const string OWNER_AGENT_ID = 'test-agent:probe-node-a';
    private const string OTHER_AGENT_ID = 'test-agent:probe-node-b';

    private ?DbContext $previousDb = null;
    private ?SignalRouter $previousSignalRouter = null;

    protected function setUp(): void
    {
        parent::setUp();
        self::runStub(down: true);
        self::runStub(down: false);
        $this->previousDb = Hilos::$db;
        $this->previousSignalRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        Hilos::$db = new ProbeValuesTestDbContext();
        Hilos::$db->configure();

        TruthSourceRegistry::register(
            HilosDbContext::probeValues,
            TruthSourceKeys::set('node-a'),
            self::OWNER_AGENT_ID,
            new TruthSourceOperations(TruthSourceOperation::Add, TruthSourceOperation::Update),
        );
        TruthSourceRegistry::register(
            HilosDbContext::probeValues,
            TruthSourceKeys::set('node-b'),
            self::OTHER_AGENT_ID,
            new TruthSourceOperations(TruthSourceOperation::Add, TruthSourceOperation::Update),
        );
        ExecutionContext::setCurrentAgentId(self::OWNER_AGENT_ID);
    }

    protected function tearDown(): void
    {
        ExecutionContext::setCurrentAgentId(null);
        TruthSourceRegistry::unregisterAgent(self::OWNER_AGENT_ID);
        TruthSourceRegistry::unregisterAgent(self::OTHER_AGENT_ID);
        Hilos::$db = $this->previousDb;
        Hilos::$sr = $this->previousSignalRouter;
        self::runStub(down: true);
        parent::tearDown();
    }

    public function testASetOwnerCreatesAndUpdatesItsNodeRow(): void
    {
        $created = Hilos::$db->probeValues->actions->add('node-a', 'probe_key', 'first');
        self::assertIsInt($created->id);
        self::assertSame('node-a', $created->nodeId);
        self::assertSame('probe_key', $created->key);
        self::assertSame('first', $created->value);
        self::assertSame([
            ['id' => (string)$created->id, 'node_id' => 'node-a', 'key' => 'probe_key', 'value' => 'first'],
        ], self::rows());

        Hilos::$db->probeValues[$created->id]->actions->updateValue('second');

        self::assertSame('second', Hilos::$db->probeValues[$created->id]->value);
        self::assertSame([
            ['id' => (string)$created->id, 'node_id' => 'node-a', 'key' => 'probe_key', 'value' => 'second'],
        ], self::rows());
    }

    public function testKeyAccessLoadsACopyOnceAndReadsItWithoutFurtherSql(): void
    {
        $created = Hilos::$db->probeValues->actions->add('node-a', 'probe_key', 'copied');
        $numericKey = Hilos::$db->probeValues->actions->add('node-a', '123', 'numeric key');
        Hilos::$db = new ProbeValuesTestDbContext();
        Hilos::$db->configure();

        $before = self::selectCount();
        self::assertSame('copied', Hilos::$db->probeValues['probe_key']->value);
        self::assertSame(1, self::selectCount() - $before, 'The cold reader loads the complete table once');
        $before = self::selectCount();
        self::assertArrayHasKey('probe_key', Hilos::$db->probeValues);
        self::assertArrayHasKey($created->id, Hilos::$db->probeValues);
        self::assertSame(Hilos::$db->probeValues[$created->id], Hilos::$db->probeValues['probe_key']);
        self::assertSame($numericKey->id, Hilos::$db->probeValues['123']->id);
        self::assertSame('numeric key', Hilos::$db->probeValues['123']->value);
        self::assertArrayNotHasKey('missing', Hilos::$db->probeValues);
        self::assertNull(Hilos::$db->probeValues['missing'] ?? null);
        try {
            Hilos::$db->probeValues['missing'];
            self::fail('Required key access must refuse a missing probe row');
        } catch (ItemNotFoundException) {
            self::assertSame($before, self::selectCount(), 'Warm lookups, including absence, must use the local copy');
        }
    }

    public function testASetOwnerCannotCreateOrUpdateAnotherNodesRow(): void
    {
        ExecutionContext::setCurrentAgentId(self::OTHER_AGENT_ID);
        $foreign = Hilos::$db->probeValues->actions->add('node-b', 'foreign_key', 'unchanged');
        ExecutionContext::setCurrentAgentId(self::OWNER_AGENT_ID);

        try {
            Hilos::$db->probeValues[$foreign->id]->actions->updateValue('forbidden');
            self::fail('A node must not update another node\'s probe row');
        } catch (WriteNotAllowedException $failure) {
            self::assertStringContainsString("it holds set 'node-a'", $failure->getMessage());
        }
        try {
            Hilos::$db->probeValues->actions->add('node-b', 'forbidden_key', 'forbidden');
            self::fail('A node must not create a probe row in another node\'s set');
        } catch (CreateNotAllowedException $failure) {
            self::assertStringContainsString("it holds set 'node-a'", $failure->getMessage());
        }

        self::assertSame('unchanged', Hilos::$db->probeValues[$foreign->id]->value);
        self::assertSame([
            ['id' => (string)$foreign->id, 'node_id' => 'node-b', 'key' => 'foreign_key', 'value' => 'unchanged'],
        ], self::rows());
    }

    /** @return list<array<string, mixed>> Persisted values, independently read past the ORM */
    private static function rows(): array
    {
        return Database::sql('SELECT `id`, `node_id`, `key`, `value` FROM `hilos_probe_value` ORDER BY `id`')->rows();
    }

    /** @return int SELECT statements executed on the real test connection */
    private static function selectCount(): int
    {
        return (int)Database::sql("SHOW SESSION STATUS LIKE 'Com_select'")->firstRow()['Value'];
    }

    /** @param bool $down Drop the table when true, create it from the stub when false */
    private static function runStub(bool $down): void
    {
        $name = 'create_hilos_probe_value';
        if ($down) {
            $name .= '_down';
        }
        Database::sqlRun((string)file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/' . $name . '.sql'));
    }
}

/** Unextended framework collections, mounted exactly as in a project. */
final class ProbeValuesTestDbContext extends HilosDbContext
{
}
