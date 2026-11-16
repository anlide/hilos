<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users;

use Hilos\Core\Page\PageAgentInterface;
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
        AccountStandingAudience::addListSubscriber('list');
        $this->source(new AccountErasureProgress(1790416800123, [], [], []));
    }

    protected function tearDown(): void
    {
        AccountStandingAudience::reset();
        AccountErasureProgressResolver::forgetAll();
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
