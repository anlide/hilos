<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Closure;
use Hilos\Core\Source\SourceChange;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Pages\AbstractHilosNotificationsPage;
use Hilos\Runtime\State\Collection\HilosConnections as StateHilosConnections;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\HilosConnection as StateHilosConnection;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Context\RtContext;
use PHPUnit\Framework\TestCase;

/**
 * What the framework declares it reads out of what the project mounted (HIL-717).
 *
 * The connections collection is the one whose key belongs to the project and whose readers
 * belong to the framework: the session host, the library commands and every agent asking which
 * session a frame came from read it wherever they run, and none of that is named by a page's
 * topology or by an agent's READS_RT. A worker hosting one monopolistic agent therefore held no
 * connections at all and answered "User session not found" to every sign-in - the defect these
 * cases stand on.
 *
 * The interest is also what keeps the rows when the agent that owns them stops (HIL-664): a
 * collection is dropped when its last reader lets go, and the framework never lets go.
 */
final class FrameworkReadDeclarationTest extends TestCase
{
    /** @var string Consumer standing in for an agent that reads the same collection */
    private const string AGENT_CONSUMER = 'framework_read_test_agent';

    protected function setUp(): void
    {
        // The declaration only decides anything where the copy is delivered, which is a worker;
        // elsewhere every mounted collection answers a read and there is nothing to prove.
        SourceInterestRegistry::readsWhatIsDelivered();
    }

    protected function tearDown(): void
    {
        SourceInterestRegistry::readsWhatItMounts();
        SourceInterestRegistry::releaseConsumer(SourceConsumer::feature(FrameworkReadRtContext::connections));
        SourceInterestRegistry::releaseConsumer(SourceConsumer::feature(FrameworkReadRtContext::rows));
        SourceInterestRegistry::releaseConsumer(SourceConsumer::feature(FrameworkReadRtContext::row));
        SourceInterestRegistry::releaseConsumer(SourceConsumer::feature(FrameworkReadRtContext::alias));
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(self::AGENT_CONSUMER));
        SourceInterestRegistry::releaseConsumer(SourceConsumer::feature(HilosDbContext::settings));
        SourceInterestRegistry::releaseConsumer(SourceConsumer::feature(HilosDbContext::identities));
        SourceInterestRegistry::releaseConsumer(SourceConsumer::feature(HilosDbContext::sessions));
        SourceInterestRegistry::releaseConsumer(SourceConsumer::feature(HilosDbContext::notifications));

        parent::tearDown();
    }

    public function testTheConnectionsCollectionIsReadHereWithNobodyHavingAskedForIt(): void
    {
        $rt = new FrameworkReadRtContext();
        $rt->configure();

        $rt->declareProcessWideReads();

        $this->assertTrue(SourceInterestRegistry::isDeclared(
            SourceChange::KIND_RT,
            FrameworkReadRtContext::connections,
        ));
    }

    public function testACollectionOfTheProjectsOwnIsLeftToItsOwnReaders(): void
    {
        $rt = new FrameworkReadRtContext();
        $rt->configure();

        $rt->declareProcessWideReads();

        $this->assertFalse(
            SourceInterestRegistry::isDeclared(SourceChange::KIND_RT, FrameworkReadRtContext::rows),
            'A project collection the framework does not read is the whole point of the mechanism',
        );
    }

    public function testTheRowsStayWhenTheAgentThatAlsoReadThemLetsGo(): void
    {
        $rt = new FrameworkReadRtContext();
        $rt->configure();
        $rt->declareProcessWideReads();
        SourceInterestRegistry::register(
            SourceChange::KIND_RT,
            FrameworkReadRtContext::connections,
            SourceConsumer::agent(self::AGENT_CONSUMER),
        );

        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(self::AGENT_CONSUMER));

        $this->assertTrue(
            SourceInterestRegistry::hasConsumers(SourceChange::KIND_RT, FrameworkReadRtContext::connections),
            'The list of live connections is the truth of the node holding the sockets, not of the agent',
        );
    }

    public function testAProjectWithNoConnectionsAndNoItemsDeclaresNothing(): void
    {
        $rt = new FrameworkReadEmptyRtContext();
        $rt->configure();
        // Compared against what the process already holds rather than against nothing: the
        // registry is process-wide, and the framework declares its own feature rows while
        // mounting - an empty list would only ever be true of a process that mounted nothing.
        $held = SourceInterestRegistry::collections(SourceChange::KIND_RT);

        $rt->declareProcessWideReads();

        $this->assertSame($held, SourceInterestRegistry::collections(SourceChange::KIND_RT));
    }

    public function testAMountedItemIsHeldHereBecauseNothingElseHoldsASingleton(): void
    {
        $rt = new FrameworkReadRtContext();
        $rt->configure();

        $rt->declareProcessWideReads();

        $this->assertTrue(
            SourceInterestRegistry::isDeclared(SourceChange::KIND_RT, FrameworkReadRtContext::row),
            'An item syncs under its own key, and a frame addressed to nobody reaches nobody',
        );
    }

    /**
     * The database half of the same mechanism (HIL-750). The seams that answer "whose session is
     * this" run in every process a frame arrives in - outside any agent, before any subscription
     * - so nothing else declares the rows they read, and a worker running no page and no agent of
     * its own would be refused them.
     */
    public function testTheFrameworksOwnSeamsAreDeclaredReadersOfWhatTheyRead(): void
    {
        $db = new FrameworkReadDbContext();
        $db->configure();

        $db->declareProcessWideReads();

        $this->assertTrue(SourceInterestRegistry::isDeclared(
            SourceChange::KIND_DB,
            HilosDbContext::sessions,
        ));
        $this->assertTrue(SourceInterestRegistry::isDeclared(
            SourceChange::KIND_DB,
            HilosDbContext::identities,
        ));
        $this->assertTrue(SourceInterestRegistry::isDeclared(
            SourceChange::KIND_DB,
            HilosDbContext::settings,
        ));
    }

    /**
     * The one whose reader is a page, and the case the e2e of all three demos found:
     * {@see AbstractHilosNotificationsPage} hosts the mark-read actions of the bell and has no
     * subscription of its own, so a list on the page is never taken up and every mark-read was
     * refused in whatever worker served the connection.
     */
    public function testTheBellsRowsAreDeclaredHereBecauseNoSubscriptionEverTakesThemUp(): void
    {
        $db = new FrameworkReadDbContext();
        $db->configure();

        $db->declareProcessWideReads();

        $this->assertTrue(SourceInterestRegistry::isDeclared(
            SourceChange::KIND_DB,
            HilosDbContext::notifications,
        ));
    }

    /**
     * And nothing beyond them. A framework collection nobody reads process-wide is declared by
     * the page or the agent that reads it - auth blocks by the throttle agent that judges them -
     * and declaring it here would put every worker of the node on the address list of a
     * collection almost none of them hold.
     */
    public function testAFrameworkCollectionReadByItsOwnReadersIsLeftToThem(): void
    {
        $db = new FrameworkReadDbContext();
        $db->configure();

        $db->declareProcessWideReads();

        $this->assertFalse(SourceInterestRegistry::isDeclared(
            SourceChange::KIND_DB,
            HilosDbContext::authBlocks,
        ));
    }

    /**
     * The two kinds are declared apart, so a collection read out of the database does not make
     * a runtime collection of that name readable and the other way round.
     */
    public function testTheDatabaseDeclarationSaysNothingAboutTheRuntimeOfThatName(): void
    {
        $db = new FrameworkReadDbContext();
        $db->configure();

        $db->declareProcessWideReads();

        $this->assertFalse(SourceInterestRegistry::isDeclared(
            SourceChange::KIND_RT,
            HilosDbContext::sessions,
        ));
    }

    public function testAResolverAliasIsNotAMountOfARow(): void
    {
        $rt = new FrameworkReadRtContext();
        $rt->configure();

        $rt->declareProcessWideReads();

        $this->assertFalse(
            SourceInterestRegistry::isDeclared(SourceChange::KIND_RT, FrameworkReadRtContext::alias),
            'What a resolver returns belongs to a collection, which answers for itself',
        );
    }
}

/**
 * Database context of a project that adds nothing to the framework's own collections.
 */
final class FrameworkReadDbContext extends HilosDbContext
{
}

/**
 * Presence-stage row with nothing of its own, as the two simple demos have.
 */
final class FrameworkReadConnection extends StateHilosConnection
{
    protected function initOwn(): void
    {
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row (nothing of its own to read)
     */
    protected function hydrateOwn(array $row): void
    {
    }

    /**
     * @return array<string, mixed> Always empty: the row is the framework base
     */
    protected function ownToArray(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $diff Partial update (nothing of its own to apply)
     */
    protected function applyOwnDiff(array $diff): void
    {
    }
}

/**
 * @extends StateHilosConnections<FrameworkReadConnection>
 */
final class FrameworkReadConnections extends StateHilosConnections
{
    public const string STATE_CLASS = FrameworkReadConnection::class;
}

/**
 * A row of the project's own, standing for everything the framework does not read.
 */
final class FrameworkReadRow extends RtState
{
    /**
     * @return string Runtime collection key
     */
    public static function getRtCollectionKey(): string
    {
        return FrameworkReadRtContext::rows;
    }

    /**
     * @return self Row instance, the way a mount builds one
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * @return string Row id
     */
    public function getId(): string
    {
        return '1';
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row (nothing to read: the row is a marker)
     * @return static Row instance
     */
    public static function fromRow(array $row): static
    {
        return new static();
    }

    /**
     * @return array<string, mixed> Serialized row
     */
    public function toArray(): array
    {
        return [];
    }
}

/**
 * @extends RtStates<FrameworkReadRow>
 */
final class FrameworkReadRows extends RtStates
{
    public const string STATE_CLASS = FrameworkReadRow::class;
}

/**
 * Runtime context of a project that mounts its connections beside a collection of its own.
 */
final class FrameworkReadRtContext extends RtContext
{
    public const string connections = 'frameworkReadTestConnections';

    public const string rows = 'frameworkReadTestRows';

    public const string row = 'frameworkReadTestSingleton';

    public const string alias = 'frameworkReadTestSelfRow';

    /**
     * Mounts one of each shape, so the cases can tell the declared ones from the untouched one.
     */
    public function configure(): void
    {
        $this->_stateCollections[self::connections] = FrameworkReadConnections::init();
        $this->_stateCollections[self::rows] = FrameworkReadRows::init();
        $this->_stateItems[self::row] = FrameworkReadRow::create();
        $this->_stateItems[self::alias] = static fn(): ?RtState => null;
    }
}

/**
 * Runtime context of a project that keeps no connections at all.
 */
final class FrameworkReadEmptyRtContext extends RtContext
{
    /**
     * Mounts nothing.
     */
    public function configure(): void
    {
    }
}
