<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database;

use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Actions\Collection\ChangeLogReceiptsActions;
use Hilos\Database\Actions\Item\ChangeLogReceiptActions;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Object\Collection\ChangeLogReceipts as ObjectChangeLogReceipts;
use Hilos\Database\Object\Item\ChangeLogReceipt as ObjectChangeLogReceipt;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\ChangeLogReceipts as DbChangeLogReceipts;
use Hilos\Database\View\Item\ChangeLogReceipt as DbChangeLogReceipt;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/**
 * Receipt actions over a recording object seam: the grant is asked before the mutation,
 * and forMigration does not open a connection or initialize Hilos.
 */
final class JournalReceiptActionsTest extends TestCase
{
    private const string AGENT = 'journal-receipt-actions';

    private const string BUSINESS = 'business-writer';

    private const string FOREIGN = 'foreign-receipt-holder';

    private const string ACCEPT = 'accept-scope';

    private const string REQUEST = 'request-scope';

    private const string RUNNER_MARK = 'journal_receipt_scope:';

    /** @var array<string, mixed> Database configuration restored after each case */
    private array $previousConfiguration = [];

    private ?HilosDbContext $previousDb = null;

    private ?string $outerAgent = null;

    private ?string $outerAccept = null;

    private ?string $outerRequest = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = Hilos::$db;
        foreach (['configurations', 'databaseBindings', 'connections', 'resultSets', 'lastSql', 'closedByHand',
            'transactionIndex', 'currentIndex', 'journalReceiptId'] as $property) {
            $reflection = new ReflectionProperty(Database::class, $property);
            $this->previousConfiguration[$property] = $reflection->getValue();
        }
        Database::configure(database: 'journal_receipt_actions');
        Database::configure(index: 1, database: ChangeLogDatabase::configuredName());
    }

    protected function tearDown(): void
    {
        TruthSourceRegistry::unregister(HilosDbContext::changeLogReceipts, self::AGENT);
        TruthSourceRegistry::unregister(HilosDbContext::changeLogReceipts, self::FOREIGN);
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(self::FOREIGN));
        new ReflectionProperty(JournalReceiptScope::class, 'ids')->setValue(null, []);
        Hilos::$db = $this->previousDb;
        foreach ($this->previousConfiguration as $property => $value) {
            new ReflectionProperty(Database::class, $property)->setValue(null, $value);
        }
        parent::tearDown();
    }

    public function testForMigrationBuildsTheActionChainWithoutSqlOrHilosInit(): void
    {
        $db = Hilos::$db;
        $env = Hilos::$env;
        $receipts = DbChangeLogReceipts::forMigration();

        self::assertSame($db, Hilos::$db);
        self::assertSame($env, Hilos::$env);
        self::assertFalse(Database::isConnected(0));
        self::assertFalse(Database::isConnected(1));
        self::assertSame(ChangeLogReceiptsActions::class, $receipts->getActionsClass());
        self::assertSame(ChangeLogReceiptActions::class, $receipts->getItemActionsClass());
        $objects = $receipts->getObjectCollection();
        self::assertInstanceOf(ObjectChangeLogReceipts::class, $objects);
        self::assertSame(ObjectChangeLogReceipts::class, $objects::class);
        self::assertSame(Objects::LAZY_STRATEGY_KEY, $objects->getLazyStrategy());
        self::assertFalse($objects->cachesRows());

        try {
            $receipts->actions->createReceipt(JournalReceiptData::agent('tick', 'agent-1'));
            self::fail('create without a grant returned');
        } catch (CreateNotAllowedException) {
            self::assertFalse(Database::isConnected(0));
            self::assertFalse(Database::isConnected(1));
        }
    }

    public function testCreateRequiresAddAndRefusesBeforeTheInsert(): void
    {
        $objects = JournalReceiptSeamObjects::initDB(Objects::LAZY_STRATEGY_KEY);
        $receipts = $this->receiptsOver($objects);
        $data = JournalReceiptData::migration('up', 3, '/tmp/0003.sql');

        try {
            $receipts->actions->createReceipt($data);
            self::fail('create without a grant returned');
        } catch (CreateNotAllowedException) {
            self::assertFalse($objects->inserted);
        }

        $this->grant(TruthSourceOperation::Update);
        try {
            $receipts->actions->createReceipt($data);
            self::fail('create under an update grant returned');
        } catch (CreateNotAllowedException) {
            self::assertFalse($objects->inserted);
        }

        $this->grant(TruthSourceOperation::Add);
        $created = $receipts->actions->createReceipt($data, true);
        self::assertInstanceOf(DbChangeLogReceipt::class, $created);
        self::assertTrue($objects->inserted);
        self::assertTrue($objects->retainLocal);
        self::assertSame($data, $objects->data);
        self::assertFalse(Database::isConnected(0));
        self::assertFalse(Database::isConnected(1));
    }

    public function testHandoverChangesRequireUpdateAndRefuseBeforeTheMutation(): void
    {
        $objects = JournalReceiptSeamObjects::initDB(Objects::LAZY_STRATEGY_KEY);
        $receipts = $this->receiptsOver($objects);
        $item = $this->storedReceipt($receipts);
        $seam = $item->object;
        self::assertInstanceOf(JournalReceiptSeam::class, $seam);

        try {
            $item->actions->adjustHandovers(-1);
            self::fail('adjust without a grant returned');
        } catch (WriteNotAllowedException) {
            self::assertSame([], $seam->calls);
        }

        $this->grant(TruthSourceOperation::Add);
        try {
            $item->actions->concludePart(1, 4, 'writer');
            self::fail('conclude under an add grant returned');
        } catch (WriteNotAllowedException) {
            self::assertSame([], $seam->calls);
        }

        $this->grant(TruthSourceOperation::Update);
        $item->actions->adjustHandovers(-2);
        $item->actions->concludePart(1, 4, 'writer');
        self::assertSame(['adjustHandovers', 'concludePart'], $seam->calls);
        self::assertSame(-2, $seam->delta);
        self::assertSame(1, $seam->concludedDelta);
        self::assertSame(4, $seam->entriesBefore);
        self::assertSame('writer', $seam->agentId);
        self::assertSame(7, $item->countEntries());
        self::assertSame(['adjustHandovers', 'concludePart', 'countEntries'], $seam->calls);
        self::assertFalse(Database::isConnected(0));
        self::assertFalse(Database::isConnected(1));
    }

    public function testCleanupRequiresRemoveAndRefusesBeforeTheMutation(): void
    {
        $objects = JournalReceiptSeamObjects::initDB(Objects::LAZY_STRATEGY_KEY);
        $receipts = $this->receiptsOver($objects);
        $item = $this->storedReceipt($receipts);
        $seam = $item->object;
        self::assertInstanceOf(JournalReceiptSeam::class, $seam);

        try {
            $item->actions->deleteIfEmpty();
            self::fail('delete without a grant returned');
        } catch (WriteNotAllowedException) {
            self::assertSame([], $seam->calls);
        }

        $this->grant(TruthSourceOperation::Update);
        try {
            $item->actions->deleteEmptyMigrationReceipt();
            self::fail('migration cleanup under an update grant returned');
        } catch (WriteNotAllowedException) {
            self::assertSame([], $seam->calls);
        }

        $this->grant(TruthSourceOperation::Remove);
        $item->actions->deleteIfEmpty();
        $item->actions->deleteEmptyMigrationReceipt();
        self::assertSame(['deleteIfEmpty', 'deleteEmptyMigrationReceipt'], $seam->calls);
        self::assertFalse(Database::isConnected(0));
        self::assertFalse(Database::isConnected(1));
    }

    public function testCallbackKeepsTheCallerAndReleasesOnlyItsGrant(): void
    {
        $objects = $this->mountSeam();
        $during = null;
        $result = null;

        $this->underCaller(function () use ($objects, &$during, &$result): void {
            $result = JournalReceiptScope::run(
                JournalReceiptData::agent('scope-run', self::BUSINESS),
                function () use ($objects, &$during): string {
                    $during = ExecutionContext::currentAgentId();
                    self::assertSame(self::ACCEPT, ExecutionContext::currentAcceptKey());
                    self::assertSame(self::REQUEST, ExecutionContext::currentRequestId());
                    self::assertSame([], $this->scopeConsumers());
                    try {
                        $this->receiptsOver($objects)->actions->createReceipt(
                            JournalReceiptData::agent('inside', self::BUSINESS),
                        );
                        self::fail('callback held the framework grant');
                    } catch (CreateNotAllowedException) {
                        self::assertSame(self::BUSINESS, ExecutionContext::currentAgentId());
                    }

                    return 'kept';
                },
            );
            $this->assertCallerFrame();
        });

        self::assertSame('kept', $result);
        self::assertSame(self::BUSINESS, $during);
        $this->assertOuterFrame();
        self::assertCount(1, $objects->created);
        self::assertSame(['deleteIfEmpty'], $objects->created[0]->calls);
        $insertAgent = $objects->insertAgents[0] ?? null;
        self::assertIsString($insertAgent);
        $this->assertRunner($insertAgent);
        self::assertIsString($objects->created[0]->cleanupAgent);
        $this->assertRunner($objects->created[0]->cleanupAgent);
        self::assertNotSame($objects->insertAgents[0], $objects->created[0]->cleanupAgent);
        self::assertSame(self::ACCEPT, $objects->insertAccept);
        self::assertSame(self::REQUEST, $objects->insertRequest);
        self::assertSame(self::ACCEPT, $objects->created[0]->cleanupAccept);
        self::assertSame(self::REQUEST, $objects->created[0]->cleanupRequest);
        self::assertFalse(Database::isConnected(0));
        self::assertFalse(Database::isConnected(1));
    }

    public function testCallbackFailureReleasesTheGrantAndRestoresTheFrame(): void
    {
        $objects = $this->mountSeam();

        $this->underCaller(function () use ($objects): void {
            try {
                JournalReceiptScope::run(
                    JournalReceiptData::agent('scope-fail', self::BUSINESS),
                    static function (): void {
                        throw new RuntimeException('business failed');
                    },
                );
                self::fail('callback exception was swallowed');
            } catch (RuntimeException $failure) {
                self::assertSame('business failed', $failure->getMessage());
            }
            $this->assertCallerFrame();
        });

        $this->assertOuterFrame();
        self::assertCount(1, $objects->created);
        self::assertSame(['deleteIfEmpty'], $objects->created[0]->calls);
        $insertAgent = $objects->insertAgents[0] ?? null;
        self::assertIsString($insertAgent);
        $this->assertRunner($insertAgent);
        self::assertIsString($objects->created[0]->cleanupAgent);
        $this->assertRunner($objects->created[0]->cleanupAgent);
        self::assertFalse(Database::isConnected(0));
        self::assertFalse(Database::isConnected(1));
    }

    public function testNestedRunReleasesOnlyItsOwnGrant(): void
    {
        $objects = $this->mountSeam();
        $data = JournalReceiptData::agent('scope-outer', self::BUSINESS);
        $innerAgent = null;

        $this->underCaller(function () use ($data, &$innerAgent): void {
            JournalReceiptScope::run($data, function () use ($data, &$innerAgent): void {
                self::assertSame([], $this->scopeConsumers());
                self::assertSame(self::BUSINESS, ExecutionContext::currentAgentId());
                JournalReceiptScope::run($data, function () use (&$innerAgent): void {
                    $innerAgent = ExecutionContext::currentAgentId();
                    self::assertSame([], $this->scopeConsumers());
                    self::assertContains(
                        SourceConsumer::agent(self::FOREIGN),
                        SourceInterestRegistry::consumersOf(SourceChange::KIND_DB, HilosDbContext::changeLogReceipts),
                    );
                });
                self::assertSame([], $this->scopeConsumers());
                self::assertSame(self::BUSINESS, ExecutionContext::currentAgentId());
            });
            self::assertSame(self::BUSINESS, $innerAgent);
            $this->assertCallerFrame();
        });

        $this->assertOuterFrame();
        self::assertCount(2, $objects->created);
        self::assertCount(2, $objects->insertAgents);
        self::assertNotSame($objects->insertAgents[0], $objects->insertAgents[1]);
        foreach ($objects->created as $index => $receipt) {
            $insertAgent = $objects->insertAgents[$index] ?? null;
            self::assertIsString($insertAgent);
            $this->assertRunner($insertAgent);
            self::assertIsString($receipt->cleanupAgent);
            $this->assertRunner($receipt->cleanupAgent);
            self::assertNotSame($objects->insertAgents[$index], $receipt->cleanupAgent);
            self::assertSame(['deleteIfEmpty'], $receipt->calls);
        }
        self::assertFalse(Database::isConnected(0));
        self::assertFalse(Database::isConnected(1));
    }

    private function receiptsOver(JournalReceiptSeamObjects $objects): DbChangeLogReceipts
    {
        $receipts = DbChangeLogReceipts::forMigration();
        $receipts->setObjectCollection($objects);

        return $receipts;
    }

    private function storedReceipt(DbChangeLogReceipts $receipts): DbChangeLogReceipt
    {
        $object = JournalReceiptSeam::create();
        $object->standAsStored();
        $item = new DbChangeLogReceipt($object);
        $item->setCollection($receipts);
        $item->setActionsClass($receipts->getItemActionsClass());

        return $item;
    }

    private function grant(TruthSourceOperation ...$operations): void
    {
        TruthSourceRegistry::register(
            HilosDbContext::changeLogReceipts,
            TruthSourceKeys::all(),
            self::AGENT,
            TruthSourceOperations::of(...$operations),
        );
    }

    /**
     * The production context is abstract, so the seam sits on an empty fixture.
     * That fixture does not repeat the framework mount.
     */
    private function mountSeam(): JournalReceiptSeamObjects
    {
        $objects = JournalReceiptSeamObjects::initDB(Objects::LAZY_STRATEGY_KEY);
        $receipts = $this->receiptsOver($objects);
        $context = new JournalReceiptScopeContext();
        $context->mountReceipts($receipts);
        Hilos::$db = $context;

        return $objects;
    }

    /**
     * The request id has no ambient setter. The caller's frame is pushed whole, and the scope
     * has to put that same frame back when its own runner returns.
     *
     * @param callable(): void $work
     */
    private function underCaller(callable $work): void
    {
        $this->outerAgent = ExecutionContext::currentAgentId();
        $this->outerAccept = ExecutionContext::currentAcceptKey();
        $this->outerRequest = ExecutionContext::currentRequestId();
        TruthSourceRegistry::register(
            HilosDbContext::changeLogReceipts,
            TruthSourceKeys::all(),
            self::FOREIGN,
            TruthSourceOperations::of(
                TruthSourceOperation::Add,
                TruthSourceOperation::Update,
                TruthSourceOperation::Remove,
            ),
        );
        SourceInterestRegistry::register(
            SourceChange::KIND_DB,
            HilosDbContext::changeLogReceipts,
            SourceConsumer::agent(self::FOREIGN),
        );
        ExecutionContext::run(new ExecutionFrame(self::BUSINESS, self::ACCEPT, self::REQUEST), $work);
    }

    private function assertOuterFrame(): void
    {
        self::assertSame($this->outerAgent, ExecutionContext::currentAgentId());
        self::assertSame($this->outerAccept, ExecutionContext::currentAcceptKey());
        self::assertSame($this->outerRequest, ExecutionContext::currentRequestId());
        self::assertSame([], $this->scopeConsumers());
    }

    /** @return list<string> Runner consumers still registered for the receipt collection */
    private function scopeConsumers(): array
    {
        $found = [];
        foreach (
            SourceInterestRegistry::consumersOf(SourceChange::KIND_DB, HilosDbContext::changeLogReceipts) as $consumer
        ) {
            if (str_contains($consumer, self::RUNNER_MARK)) {
                $found[] = $consumer;
            }
        }

        return $found;
    }

    private function assertCallerFrame(): void
    {
        self::assertSame(self::BUSINESS, ExecutionContext::currentAgentId());
        self::assertSame(self::ACCEPT, ExecutionContext::currentAcceptKey());
        self::assertSame(self::REQUEST, ExecutionContext::currentRequestId());
        self::assertSame([], $this->scopeConsumers());
        self::assertContains(
            SourceConsumer::agent(self::FOREIGN),
            SourceInterestRegistry::consumersOf(SourceChange::KIND_DB, HilosDbContext::changeLogReceipts),
        );
        self::assertNull(JournalReceiptScope::currentId());
    }

    private function assertRunner(string $agent): void
    {
        self::assertNotSame(self::BUSINESS, $agent);
        self::assertStringStartsWith(self::RUNNER_MARK, $agent);
    }
}

/**
 * Object item that records receipt mutations and does not touch SQL.
 */
final class JournalReceiptSeam extends ObjectChangeLogReceipt
{
    /** @var list<string> Mutation and read names that ran */
    public array $calls = [];

    public ?int $delta = null;

    public ?int $concludedDelta = null;

    public ?int $entriesBefore = null;

    public ?string $agentId = null;

    public ?string $cleanupAgent = null;

    public ?string $cleanupAccept = null;

    public ?string $cleanupRequest = null;

    /** Marks the row stored so an item action asks for update or remove, not create. */
    public function standAsStored(): void
    {
        $this->entity->id = 41;
        $this->entity->created_at = '2026-10-23 12:34:56.123456';
        $this->entity->channel = 'page';
        $this->entity->action = 'rename';
        $this->entity->flushRelated();
    }

    public function countEntries(): int
    {
        $this->calls[] = 'countEntries';

        return 7;
    }

    public function adjustHandovers(int $delta): void
    {
        $this->calls[] = 'adjustHandovers';
        $this->delta = $delta;
    }

    public function concludePart(int $delta, ?int $entriesBefore, string $agentId): void
    {
        $this->calls[] = 'concludePart';
        $this->concludedDelta = $delta;
        $this->entriesBefore = $entriesBefore;
        $this->agentId = $agentId;
    }

    public function standInserted(int $id): void
    {
        $this->standAsStored();
        $this->entity->id = $id;
        $this->entity->flushRelated();
    }

    public function deleteIfEmpty(): void
    {
        $this->calls[] = 'deleteIfEmpty';
        $this->cleanupAgent = ExecutionContext::currentAgentId();
        $this->cleanupAccept = ExecutionContext::currentAcceptKey();
        $this->cleanupRequest = ExecutionContext::currentRequestId();
    }

    public function deleteEmptyMigrationReceipt(): void
    {
        $this->calls[] = 'deleteEmptyMigrationReceipt';
    }
}

/**
 * Object collection that records an insert and does not touch SQL.
 */
final class JournalReceiptSeamObjects extends ObjectChangeLogReceipts
{
    public bool $inserted = false;

    public ?JournalReceiptData $data = null;

    public ?bool $retainLocal = null;

    /** @var list<JournalReceiptSeam> Receipts this collection handed to a scope */
    public array $created = [];

    /** @var list<?string> Agent current while each insert ran */
    public array $insertAgents = [];

    public ?string $insertAccept = null;

    public ?string $insertRequest = null;

    public int $nextId = 41;

    public function insertReceipt(JournalReceiptData $data, bool $retainLocal): ObjectChangeLogReceipt
    {
        $this->inserted = true;
        $this->data = $data;
        $this->retainLocal = $retainLocal;
        $this->insertAgents[] = ExecutionContext::currentAgentId();
        $this->insertAccept = ExecutionContext::currentAcceptKey();
        $this->insertRequest = ExecutionContext::currentRequestId();
        $receipt = JournalReceiptSeam::create();
        $receipt->standInserted($this->nextId);
        $this->nextId++;
        $this->created[] = $receipt;

        return $receipt;
    }
}

/** Empty context so a unit case can hand the seam to JournalReceiptScope. */
final class JournalReceiptScopeContext extends HilosDbContext
{
    public function configure(): void
    {
    }

    public function mountReceipts(DbChangeLogReceipts $receipts): void
    {
        $this->_dbItemCollections[self::changeLogReceipts] = $receipts;
    }
}
