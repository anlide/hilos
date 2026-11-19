<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users;

use Generator;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountOperationKind;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\AccountOperations as ObjectAccountOperations;
use Hilos\Database\Object\Item\AccountOperation as ObjectAccountOperation;
use Hilos\Database\View\Collection\AccountOperations;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Users\AccountMergeProgress;
use Hilos\Users\AccountMergeProgressResolver;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\View\Collection\Users;
use Hilos\Database\Entity\Item\User as EntityUser;
use Hilos\Hilos;
use Hilos\Pages\Users\AccountStandingAudience;
use Hilos\Users\AccountErasureProgress;
use Hilos\Users\AccountErasureProgressChangeSubscriber;
use Hilos\Users\AccountErasureProgressResolver;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Counts the production audience's account reads, including repeated misses, without a SQL meter. */
final class AccountStandingAudienceTest extends TestCase
{
    private ?HilosDbContext $previousDb;

    protected function setUp(): void
    {
        $this->previousDb = Hilos::$db;
        Hilos::$db = new StandingAudienceContext();
        Hilos::$db->configure();
        AccountStandingAudience::reset();
        AccountErasureProgressResolver::forgetAll();
        AccountMergeProgressResolver::forgetAll();
        AccountStandingAudience::addListSubscriber('list');
        $this->source(new AccountErasureProgress(1790416800123, [], [], []));
    }

    protected function tearDown(): void
    {
        AccountStandingAudience::reset();
        AccountErasureProgressResolver::forgetAll();
        AccountMergeProgressResolver::forgetAll();
        Hilos::$db = $this->previousDb;
    }

    public function testStableSourceAndUserUpdatesDoNotRepeatEvenAMissingAccountRead(): void
    {
        self::assertCount(1, AccountStandingAudience::erasureBars());
        self::assertSame(2, Hilos::$db->users->reads);
        $this->tick();
        $this->tick();
        $this->change(SourceChange::dbUpdated(HilosDbContext::users, '43', [EntityUser::last_activity => 1790416800223]));
        $this->tick();

        self::assertCount(1, AccountStandingAudience::erasureBars());
        self::assertSame(2, Hilos::$db->users->reads);
    }

    public function testDeleteAndClearDropOnlyTheAccountFilterAndEndBarsOnTheNextTick(): void
    {
        $source = AccountErasureProgressResolver::begun();
        self::assertCount(1, AccountStandingAudience::erasureBars());
        Hilos::$db->users->present = [];
        $this->change(SourceChange::dbDeleted(HilosDbContext::users, '43'));
        self::assertSame($source, AccountErasureProgressResolver::begun());
        $this->tick();
        self::assertSame([], AccountStandingAudience::erasureBars());
        self::assertSame(4, Hilos::$db->users->reads);
        $this->tick();
        self::assertSame(4, Hilos::$db->users->reads);

        Hilos::$db->users->present = [43, 44];
        $this->change(SourceChange::dbCleared(HilosDbContext::users));
        $this->tick();
        self::assertCount(2, AccountStandingAudience::erasureBars());
        self::assertSame($source, AccountErasureProgressResolver::begun());
        self::assertSame(6, Hilos::$db->users->reads);
    }

    public function testNewProgressObjectsLastListLeavingAndResetEachRequireAFreshFilter(): void
    {
        $first = AccountStandingAudience::erasureBars();
        $this->source(new AccountErasureProgress(1790416800123, [], [], []));
        $this->tick();
        self::assertEquals($first, AccountStandingAudience::erasureBars());
        self::assertSame(4, Hilos::$db->users->reads);

        AccountStandingAudience::removeSubscriber('list');
        AccountStandingAudience::addListSubscriber('next-list');
        AccountStandingAudience::erasureBars();
        self::assertSame(6, Hilos::$db->users->reads);
        AccountStandingAudience::reset();
        AccountStandingAudience::addListSubscriber('after-reset');
        AccountStandingAudience::erasureBars();
        self::assertSame(8, Hilos::$db->users->reads);
    }

    public function testOpenPagesUseExactOperationsAndContinuePastThePageCeiling(): void
    {
        $progress = $this->merge(1);
        for ($id = 2; $id <= ObjectAccountOperations::PAGE_LIMIT_MAX + 1; ++$id) {
            $progress = $this->merge($id);
        }
        $open = AccountMergeProgressResolver::open();
        self::assertCount(ObjectAccountOperations::PAGE_LIMIT_MAX + 1, $open);
        self::assertSame($progress, $open[$progress->operationId]);
        self::assertSame([0, ObjectAccountOperations::PAGE_LIMIT_MAX], array_column(Hilos::$db->accountOperations->pages, 1));
        foreach (Hilos::$db->accountOperations->pages as [$kind, , $limit]) {
            self::assertSame(AccountOperationKind::Merge, $kind);
            self::assertSame(ObjectAccountOperations::PAGE_LIMIT_MAX, $limit);
        }
        Hilos::$db->accountOperations->rows = [];
        self::assertSame([], AccountMergeProgressResolver::open());
    }

    public function testAnOpenPageNeverProjectsAClosedRow(): void
    {
        $closed = $this->createStub(AccountOperation::class);
        $closed->method('__get')->willReturnMap([[ObjectAccountOperation::finishedAt, 1790416800123]]);
        Hilos::$db->accountOperations->rows[5] = $closed;
        self::assertSame([], AccountMergeProgressResolver::open());
        $progress = $this->merge(7);
        self::assertSame([7 => $progress], AccountMergeProgressResolver::open());
    }

    public function testComposedPictureUsesHighestOpenMergeAndErasurePriorityAndRechecksExistence(): void
    {
        Hilos::$db->users->present = [43, 44];
        $this->merge(7);
        $latest = $this->merge(9);
        self::assertCount(2, AccountStandingAudience::erasureBars());
        self::assertSame([], AccountStandingAudience::mergeBars());
        $this->clearErasures();
        $this->tick();
        $merge = AccountStandingAudience::mergeBars();
        self::assertSame(['43', '44'], array_column($merge, 'rowKey'));
        self::assertSame($latest->toArray(), $merge[0]->detail);
        Hilos::$db->users->present = [43];
        $this->tick();
        self::assertSame(['43'], array_column(AccountStandingAudience::mergeBars(), 'rowKey'));
        $this->source(new AccountErasureProgress(1790416800123, [], [], []));
        $this->tick();
        self::assertSame([], AccountStandingAudience::mergeBars());
        self::assertCount(1, AccountStandingAudience::erasureBars());
        $this->clearErasures();
        $this->tick();
        self::assertSame($latest->toArray(), AccountStandingAudience::mergeBars()[0]->detail);
    }

    public function testLateSubscriberSharesLastSentPictureAndUnsubscribeAndResetForgetIt(): void
    {
        $this->clearErasures();
        $first = $this->merge(7);
        self::assertSame($first->toArray(), AccountStandingAudience::mergeBars()[0]->detail);
        $next = $this->merge(8);
        AccountStandingAudience::addListSubscriber('late');
        self::assertSame($first->toArray(), AccountStandingAudience::mergeBars()[0]->detail);
        $this->tick();
        self::assertSame($next->toArray(), AccountStandingAudience::mergeBars()[0]->detail);
        AccountStandingAudience::removeSubscriber('list');
        AccountStandingAudience::removeSubscriber('late');
        Hilos::$db->accountOperations->rows = [];
        AccountStandingAudience::addListSubscriber('again');
        self::assertSame([], AccountStandingAudience::progressBars());
        $this->merge(9);
        AccountStandingAudience::reset();
        self::assertCount(1, AccountStandingAudience::mergeBars());
        Hilos::$db->accountOperations->rows = [];
        $this->tick();
        self::assertSame([], AccountStandingAudience::progressBars());
    }

    public function testTheFirstSnapshotIsHeldBeforeTheAfterResponseListenerArrives(): void
    {
        AccountStandingAudience::removeSubscriber('list');
        $this->clearErasures();
        $first = $this->merge(7);
        self::assertSame($first->toArray(), AccountStandingAudience::mergeBars()[0]->detail);
        $next = $this->merge(8);
        AccountStandingAudience::addListSubscriber('after-response');
        self::assertSame($first->toArray(), AccountStandingAudience::mergeBars()[0]->detail);
        $this->tick();
        self::assertSame($next->toArray(), AccountStandingAudience::mergeBars()[0]->detail);
    }

    public function testSourceFailureClearsOnlyProgressAndRecoveryRestoresIt(): void
    {
        $this->clearErasures();
        $progress = $this->merge(7);
        self::assertCount(1, AccountStandingAudience::mergeBars());
        Hilos::$db->accountOperations->fail = true;
        $this->tick();
        self::assertSame([], AccountStandingAudience::progressBars());
        Hilos::$db->accountOperations->fail = false;
        $this->tick();
        self::assertSame($progress->toArray(), AccountStandingAudience::mergeBars()[0]->detail);
    }

    /**
     * @param int $operationId Open id with a separately supplied strict projection
     * @return AccountMergeProgress Resolver answer, independent of the list picture
     */
    private function merge(int $operationId): AccountMergeProgress
    {
        $operation = $this->createStub(AccountOperation::class);
        $operation->method('__get')->willReturnMap([[ObjectAccountOperation::finishedAt, null]]);
        Hilos::$db->accountOperations->rows[$operationId] = $operation;
        $progress = new AccountMergeProgress($operationId, 43, 44, 'preparing', 'preparing', null, false, false,
            new AccountMergeChoice(null, null, null, null), null, [], []);
        $answers = new ReflectionProperty(AccountMergeProgressResolver::class, 'answers');
        $answers->setValue(null, [...$answers->getValue(), 'operation:' . $operationId => $progress]);

        return $progress;
    }

    private function clearErasures(): void
    {
        new ReflectionProperty(AccountErasureProgressResolver::class, 'begun')->setValue(null, []);
    }

    /** @param AccountErasureProgress $progress Resolver fixture, shared by an existing and a missing account */
    private function source(AccountErasureProgress $progress): void
    {
        new ReflectionProperty(AccountErasureProgressResolver::class, 'begun')->setValue(null, [43 => $progress, 44 => $progress]);
    }

    private function tick(): void
    {
        AccountStandingAudience::onAgentTick($this->createStub(PageAgentInterface::class));
    }

    /** @param SourceChange $change Real source invalidation delivered to the production subscriber */
    private function change(SourceChange $change): void
    {
        new AccountErasureProgressChangeSubscriber()->onSourceChange($change, SourceChangeProvenance::AppliedRemote);
    }
}

/** Mounts only the counting test collection; the resolver picture is supplied independently of its DB reader. */
final class StandingAudienceContext extends HilosDbContext
{
    public function configure(): void
    {
        $this->_dbItemCollections[self::users] = StandingAudienceUsers::initEmpty();
        $this->_dbItemCollections[self::accountOperations] = StandingAudienceOperations::initEmpty();
    }
}

/** Existence reads count both hits and misses, unlike the ORM's SQL counter for warmed rows. */
final class StandingAudienceUsers extends Users
{
    public int $reads = 0;

    /** @var list<int> Accounts still present in the test data set */
    public array $present = [43];

    /**
     * @param mixed $offset Account key
     * @return bool Whether the account remains in the data set
     */
    public function offsetExists(mixed $offset): bool
    {
        ++$this->reads;

        return in_array($offset, $this->present, true);
    }
}

/** Controlled mounted pages; projection validation stays covered by real owner integration cases. */
final class StandingAudienceOperations extends AccountOperations
{
    /** @var array<int, AccountOperation> Current durable-page fixture */
    public array $rows = [];

    /** @var list<array{?AccountOperationKind, int, int}> Observed page requests */
    public array $pages = [];

    public bool $fail = false;

    /**
     * @param ?AccountOperationKind $kind Requested kind
     * @param int $afterId Exclusive cursor
     * @param int $limit Page ceiling
     * @return static Page from the controlled source
     */
    public function openPage(?AccountOperationKind $kind, int $afterId, int $limit): static
    {
        $this->pages[] = [$kind, $afterId, $limit];
        if ($this->fail) {
            throw new DatabaseException('Unavailable progress source.');
        }
        $page = static::initEmpty();
        $page->rows = array_slice(array_filter($this->rows,
            static fn (int $id): bool => $id > $afterId, ARRAY_FILTER_USE_KEY), 0, $limit, true);

        return $page;
    }

    /** @return Generator<int, AccountOperation> Controlled page rows */
    public function getIterator(): Generator
    {
        yield from $this->rows;
    }

    /** @return int Number of controlled rows */
    public function count(): int
    {
        return count($this->rows);
    }
}
