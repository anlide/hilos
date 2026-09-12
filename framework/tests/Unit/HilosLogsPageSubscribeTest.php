<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Hilos;
use Hilos\Log\ClusterLogIndexMirror;
use Hilos\Log\ClusterLogNodeSlot;
use Hilos\Log\DTO\ClusterLogIndexPortionSignalData;
use Hilos\Log\LogBatchSummary;
use Hilos\Log\LogKeySummary;
use Hilos\Log\LogRecentEntry;
use Hilos\Log\LogSettingsCatalog;
use Hilos\Log\NodeLogIndex;
use Hilos\Pages\Logs\AbstractHilosLogsPage;
use Hilos\Pages\Logs\DTO\HilosLogsOverviewSignalData;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for how the logs page ends a subscription (HIL-702).
 *
 * The page carries an overview signal of its own, and until this leaf it sent that signal
 * INSTEAD of the page_response frame every other page closes a subscription with - so the
 * section arrived without the heading, lead and breadcrumb its catalog entry holds. What the
 * tests hold down is the order of the two answers, the identity riding the second one, and that
 * a connection joins the tick's push list only once the subscription has actually been answered.
 *
 * Since HIL-756 the figures come from the CLUSTER picture in {@see ClusterLogIndexMirror} rather
 * than from a walk of this node's own directory, so the cases move the mirror instead of writing
 * files - and one of them asks what the page answers when no picture has arrived at all, which is
 * a third state the payload gained with the same leaf.
 *
 * HIL-390 added the rest of what the overview screen draws - the day's growth, the batches awaiting
 * takeout, and a row per named node - so the cases below also hold down which of those are figures
 * and which are the absence of one, and that a change confined to a single node's row still wakes
 * the broadcast. That last case is the one nothing else would catch: a fingerprint blind to the
 * node rows leaves the screen holding a stale picture while still looking alive.
 */
final class HilosLogsPageSubscribeTest extends TestCase
{
    private const string ACCEPT_KEY = 'ak-logs-1';

    private const string REFUSED_ACCEPT_KEY = 'ak-logs-refused';

    /** Comfortably past the ~100ms throttle {@see AbstractHilosLogsPage::onAgentTick()} keeps. */
    private const int PAST_THE_TICK_THROTTLE_MICROSECONDS = 150_000;

    /** Any fixed instant, so a timestamp in a fixture means something to read. */
    private const int T0 = 1_800_000_000;

    /**
     * Retention is judged against the clock, so a batch that has to look old has to be old for
     * real - {@see self::T0} is a fixed instant and says nothing about how long ago it was.
     */
    private const int A_DAY_IN_SECONDS = 86_400;

    /** The short end of the same scale: a retention threshold every fixture batch is past. */
    private const int AN_HOUR_IN_SECONDS = 3_600;

    /** How many bytes the fixture node's one key holds; each move of it moves the fingerprint */
    private int $keyBytes = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Hilos::$sr = new SignalRouter();
        // Binds the base facade, whose page catalog answers for the framework admin pages. The
        // base creates no browser context, so this clears the browser in the same call.
        Hilos::initBrowser();

        $this->emptyTheMirror();
        $this->growTheClusterPicture();
    }

    protected function tearDown(): void
    {
        // The subscriber set is static and outlives the test; a key left in it would push at
        // nobody for the rest of the suite. The mirror is static for the same reason.
        AbstractHilosLogsPage::removeSubscriber(self::ACCEPT_KEY);
        AbstractHilosLogsPage::removeSubscriber(self::REFUSED_ACCEPT_KEY);
        $this->emptyTheMirror();

        Hilos::$sr = null;
        Hilos::$browser = null;

        parent::tearDown();
    }

    /**
     * Two answers, in this order and no other: the page's own overview first, because the frame
     * behind it means the subscription is answered in full.
     */
    public function testTheSubscriptionAnswersWithTheOverviewAndThenTheFrame(): void
    {
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $this->assertSame(
            [
                HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS,
                SignalTypeConstants::PAGE_RESPONSE,
            ],
            $this->queuedSignalNames(),
        );
    }

    /**
     * The overview is the CLUSTER's, projected out of the mirror: the page walks no directory, so
     * these are figures every node reported and not the ones lying on whichever node answered.
     */
    public function testTheOverviewIsProjectedFromTheClusterPicture(): void
    {
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $overview = $this->overview();
        $this->assertTrue($overview->available);
        $this->assertSame(1, $overview->totalRotationsAllTime);
        $this->assertSame(1, $overview->logKeysPerAgent);
        $this->assertSame(100, $overview->totalWeightAgentKeysBytes);
        $this->assertSame(1, $overview->logKeysPerWorker);
        $this->assertSame(300, $overview->totalWeightWorkerKeysBytes);
    }

    /**
     * Opening the page before the aggregator has answered gives the third state and not zeros: the
     * figures are UNKNOWN, where false would report every log store in the cluster as unreadable
     * and a zero would claim a measurement nobody took.
     */
    public function testAnEmptyMirrorAnswersWithTheThirdState(): void
    {
        $this->emptyTheMirror();
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $overview = $this->overview();
        $this->assertNull($overview->available);
        $this->assertNull($overview->totalRotationsAllTime);
        $this->assertNull($overview->logKeysPerAgent);
        $this->assertNull($overview->totalWeightWorkerKeysBytes);
    }

    /**
     * The day's growth is the cluster's sum, and the streams that have not been watched for a day
     * yet are counted apart instead of contributing a zero to it.
     */
    public function testTheGrowthTileGetsBothTheSumAndTheStreamsStillBeingMeasured(): void
    {
        $this->fileThePicture(self::nodeSlot('node-1', growthBytesPerDay: ['agent-a.log' => 400, 'worker-0.log' => null]));
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $overview = $this->overview();
        $this->assertSame(400, $overview->growthBytesPerDay);
        $this->assertSame(1, $overview->keysWithoutGrowthWindow);
    }

    /**
     * With no stream watched for a whole day yet, the growth is null and NOT zero: zero is the
     * claim that nothing was written, and the truth is that nobody has measured long enough.
     */
    public function testGrowthNobodyHasMeasuredYetIsNullRatherThanZero(): void
    {
        $this->fileThePicture(self::nodeSlot('node-1', growthBytesPerDay: ['agent-a.log' => null]));
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $overview = $this->overview();
        $this->assertNull($overview->growthBytesPerDay);
        $this->assertSame(1, $overview->keysWithoutGrowthWindow);
    }

    /**
     * The verdict arrives with each node's own index and is only added up here (HIL-871): archives
     * do not travel, so a batch ages out where it lies, and the machine holding the directory is
     * the one that judged it. The counter is the length of the list that came, and the banner is
     * the sum of those lengths.
     */
    public function testBatchesDueForTakeoutAreCountedNodeByNodeAndThenSummed(): void
    {
        $old = time() - 10 * self::A_DAY_IN_SECONDS;
        $this->fileThePicture(
            self::nodeSlot(
                'node-1',
                batches: [self::batch($old), self::batch($old + 60), self::batch($old + 120)],
                due: [$old, $old + 60],
            ),
            self::nodeSlot('node-2', batches: [self::batch($old)]),
        );
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        // node-1 reported two of its three as recommended; node-2 reported none of its one.
        $overview = $this->overview();
        $this->assertSame(2, $overview->batchesDueForTakeout);
        $this->assertSame(2, $overview->nodes[0][HilosLogsOverviewSignalData::batchesDueForTakeout]);
        $this->assertSame(0, $overview->nodes[1][HilosLogsOverviewSignalData::batchesDueForTakeout]);
    }

    /**
     * The counter follows the list that arrived and not a rule read here: this node names its
     * NEWEST batch, which any threshold this process could read would have protected. The column
     * is drawn from the answer of the machine that owns the files.
     */
    public function testTheCounterFollowsTheVerdictThatArrivedAndNotARuleReadHere(): void
    {
        $old = time() - 10 * self::A_DAY_IN_SECONDS;
        $this->fileThePicture(
            self::nodeSlot('node-1', batches: [self::batch($old), self::batch($old + 60)], due: [$old + 60]),
        );
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $this->assertSame(1, $this->overview()->batchesDueForTakeout);
    }

    /**
     * Only a node with a name of its own gets a row. The installation that runs on one node
     * reports under no name, and the table it would head is a table of one row about "here".
     */
    public function testOnlyNamedNodesGetARow(): void
    {
        $this->fileThePicture(self::nodeSlot('node-1'), self::nodeSlot(null));
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $overview = $this->overview();
        $this->assertCount(1, $overview->nodes);
        $this->assertSame('node-1', $overview->nodes[0][HilosLogsOverviewSignalData::nodeId]);
    }

    /**
     * A single-node installation sends no rows at all, and that empty list is what tells the
     * screen to drop the per-node table rather than draw one row saying "this machine".
     */
    public function testASingleNodeInstallationSendsNoRows(): void
    {
        $this->fileThePicture(self::nodeSlot(null));
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $overview = $this->overview();
        $this->assertTrue($overview->available, 'The node answered for itself, it just has no name');
        $this->assertSame([], $overview->nodes);
    }

    /**
     * A node whose store could not be read keeps its row and carries null in every figure. Zeros
     * there would be measurements nobody took, and dropping the row would read as a node that
     * never reported - while the nodes beside it go on answering for themselves.
     */
    public function testANodeThatCouldNotBeReadCarriesNullInEveryFigure(): void
    {
        $this->fileThePicture(
            self::nodeSlot('node-1', keys: [new LogKeySummary('agent-a.log', LogKeySummary::CLASS_AGENT, true, [], 100)]),
            self::nodeSlot('node-2', available: false),
        );
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $overview = $this->overview();
        $this->assertTrue($overview->available, 'One unreadable node does not make the cluster unreadable');
        $this->assertSame(
            [
                HilosLogsOverviewSignalData::nodeId => 'node-2',
                HilosLogsOverviewSignalData::available => false,
                HilosLogsOverviewSignalData::lastRotationAt => null,
                HilosLogsOverviewSignalData::liveBytes => null,
                HilosLogsOverviewSignalData::archiveBytes => null,
                HilosLogsOverviewSignalData::growthBytesPerDay => null,
                HilosLogsOverviewSignalData::batchesDueForTakeout => null,
                HilosLogsOverviewSignalData::filesystemFreeBytes => null,
                HilosLogsOverviewSignalData::filesystemTotalBytes => null,
                HilosLogsOverviewSignalData::freeSpaceThresholdPercent => null,
            ],
            $overview->nodes[1],
        );
    }

    /**
     * A single-node installation has no row to put its disk in, so the header carries it (HIL-869).
     * Which half is filled follows the node list itself: empty there, header here.
     */
    public function testASingleNodeInstallationCarriesItsDiskInTheHeader(): void
    {
        $this->fileThePicture(self::nodeSlot(
            null,
            filesystemFreeBytes: 12_884_901_888,
            filesystemTotalBytes: 107_374_182_400,
            freeSpaceThresholdPercent: 35,
        ));
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $overview = $this->overview();
        $this->assertSame(12_884_901_888, $overview->filesystemFreeBytes);
        $this->assertSame(107_374_182_400, $overview->filesystemTotalBytes);
        $this->assertSame(35, $overview->freeSpaceThresholdPercent);
    }

    /**
     * A cluster puts every disk in its own row and leaves the header empty: free space is never
     * summed, because each node has a filesystem of its own and the sum answers no question.
     */
    public function testInAClusterEveryDiskTravelsInItsOwnRow(): void
    {
        $this->fileThePicture(
            self::nodeSlot('node-1', filesystemFreeBytes: 500, filesystemTotalBytes: 1000, freeSpaceThresholdPercent: 20),
            self::nodeSlot('node-2', filesystemFreeBytes: 100, filesystemTotalBytes: 4000, freeSpaceThresholdPercent: 10),
        );
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $overview = $this->overview();
        $this->assertNull($overview->filesystemFreeBytes);
        $this->assertNull($overview->filesystemTotalBytes);
        $this->assertNull($overview->freeSpaceThresholdPercent);
        $this->assertSame(500, $overview->nodes[0][HilosLogsOverviewSignalData::filesystemFreeBytes]);
        $this->assertSame(1000, $overview->nodes[0][HilosLogsOverviewSignalData::filesystemTotalBytes]);
        $this->assertSame(20, $overview->nodes[0][HilosLogsOverviewSignalData::freeSpaceThresholdPercent]);
        $this->assertSame(100, $overview->nodes[1][HilosLogsOverviewSignalData::filesystemFreeBytes]);
        $this->assertSame(4000, $overview->nodes[1][HilosLogsOverviewSignalData::filesystemTotalBytes]);
        $this->assertSame(10, $overview->nodes[1][HilosLogsOverviewSignalData::freeSpaceThresholdPercent]);
    }

    /**
     * An unreadable node reports no disk even though it would have resolved a threshold: a
     * threshold is something a measurement is judged against, and there is no measurement.
     */
    public function testAnUnreadableSingleNodeLeavesTheHeaderEmpty(): void
    {
        $this->fileThePicture(
            self::nodeSlot('node-1', keys: [new LogKeySummary('agent-a.log', LogKeySummary::CLASS_AGENT, true, [], 100)]),
            self::nodeSlot(null, available: false, filesystemFreeBytes: 500, filesystemTotalBytes: 1000),
        );
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $overview = $this->overview();
        $this->assertNull($overview->filesystemFreeBytes);
        $this->assertNull($overview->filesystemTotalBytes);
        $this->assertNull($overview->freeSpaceThresholdPercent);
    }

    /**
     * Nothing measures the live weight, so the row derives it: a key's weight already holds the
     * live file AND every batch that key occurs in, which makes the live weight that sum with the
     * archive taken back out.
     */
    public function testTheLiveWeightIsTheKeysWithTheArchiveTakenBackOut(): void
    {
        $this->fileThePicture(self::nodeSlot(
            'node-1',
            batches: [self::batch(time() - self::AN_HOUR_IN_SECONDS, 300, 100, 50, 50)],
            keys: [new LogKeySummary('agent-a.log', LogKeySummary::CLASS_AGENT, true, [], 1_000)],
        ));
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $row = $this->overview()->nodes[0];
        $this->assertSame(500, $row[HilosLogsOverviewSignalData::archiveBytes], 'All four classes of the batch');
        $this->assertSame(500, $row[HilosLogsOverviewSignalData::liveBytes]);
    }

    /**
     * The one case nothing else would catch. A picture in which ONLY a node's own weight moved
     * leaves all seven of the older scalars where they were, so a fingerprint that does not reach
     * into the rows would call the picture unchanged - and the table would sit there stale while
     * the screen went on looking alive, with nothing failing anywhere.
     */
    public function testATickPushesWhenOnlyANumberInsideANodeRowChanged(): void
    {
        $keys = [new LogKeySummary('agent-a.log', LogKeySummary::CLASS_AGENT, true, [], 1_000)];
        $batchAt = time() - self::AN_HOUR_IN_SECONDS;
        $this->fileThePicture(self::nodeSlot('node-1', batches: [self::batch($batchAt, 300)], keys: $keys));
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());
        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
        $before = $this->overview();
        $this->drainTheQueue();

        $this->fileThePicture(self::nodeSlot('node-1', batches: [self::batch($batchAt, 700)], keys: $keys));
        usleep(self::PAST_THE_TICK_THROTTLE_MICROSECONDS);
        AbstractHilosLogsPage::onAgentTick(new LogsPageSubscribeTestAgent());

        $after = $this->overview();
        $this->assertSame($before->totalRotationsAllTime, $after->totalRotationsAllTime);
        $this->assertSame($before->totalWeightAgentKeysBytes, $after->totalWeightAgentKeysBytes);
        $this->assertSame($before->logKeysPerAgent, $after->logKeysPerAgent);
        $this->assertSame(300, $before->nodes[0][HilosLogsOverviewSignalData::archiveBytes]);
        $this->assertSame(700, $after->nodes[0][HilosLogsOverviewSignalData::archiveBytes]);
    }

    /**
     * The panel asks one question of the whole installation, so the tails of every node are one
     * list here, ordered by when the failures happened and not by whose frame arrived last.
     */
    public function testTheRecentErrorsOfEveryNodeAreOneListNewestFirst(): void
    {
        $this->fileThePicture(
            self::nodeSlot('node-1', recentErrors: [self::failure(120, 'worker-0.error.log', message: 'the older one')]),
            self::nodeSlot('node-2', recentErrors: [self::failure(30, 'daemon-error.log', message: 'the newer one')]),
        );
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $overview = $this->overview();
        $this->assertSame(
            ['the newer one', 'the older one'],
            array_column($overview->recentErrors, HilosLogsOverviewSignalData::message),
        );
        $this->assertSame('node-2', $overview->recentErrors[0][HilosLogsOverviewSignalData::nodeId]);
        $this->assertFalse($overview->recentErrorsCapped);
    }

    /**
     * The window is the page's, not the nodes': they report what they hold, stamped, and three
     * clocks cutting three windows would show a different panel depending on whose frame came last.
     */
    public function testAFailureOlderThanTheWindowIsCutOff(): void
    {
        $this->fileThePicture(self::nodeSlot('node-1', recentErrors: [
            self::failure(60, 'worker-0.error.log', message: 'inside the window'),
            self::failure(self::AN_HOUR_IN_SECONDS + 60, 'worker-0.error.log', message: 'older than the window'),
        ]));
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $overview = $this->overview();
        $this->assertSame(
            ['inside the window'],
            array_column($overview->recentErrors, HilosLogsOverviewSignalData::message),
        );
    }

    /**
     * Cut at the limit, the list says so: the counter beside the heading then reads "10+" instead
     * of claiming the installation had exactly ten failures this hour.
     */
    public function testAListCutAtTheLimitSaysItWasCut(): void
    {
        $failures = [];
        for ($index = 0; $index < 12; $index++) {
            $failures[] = self::failure($index + 1, 'worker-0.error.log', message: "failure {$index}");
        }
        $this->fileThePicture(self::nodeSlot('node-1', recentErrors: $failures));
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $overview = $this->overview();
        $this->assertCount(10, $overview->recentErrors);
        $this->assertTrue($overview->recentErrorsCapped);
        $this->assertSame('failure 0', $overview->recentErrors[0][HilosLogsOverviewSignalData::message], 'The newest survives the cut');
    }

    /**
     * A single-node installation draws no per-node table and still has failures to show, so the
     * row keeps its place in this list under the empty name the viewer address reads as "here".
     */
    public function testAFailureOnAnUnnamedNodeCarriesTheEmptyNodeId(): void
    {
        $this->fileThePicture(self::nodeSlot(null, recentErrors: [self::failure(30, 'daemon-error.log', 4)]));
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $overview = $this->overview();
        $this->assertSame([], $overview->nodes, 'No table, and the panel still has the row');
        $this->assertCount(1, $overview->recentErrors);
        $this->assertSame(
            HilosLogsOverviewSignalData::SELF_NODE_ID,
            $overview->recentErrors[0][HilosLogsOverviewSignalData::nodeId],
        );
        $this->assertSame('daemon-error.log', $overview->recentErrors[0][HilosLogsOverviewSignalData::stream]);
        $this->assertSame(4, $overview->recentErrors[0][HilosLogsOverviewSignalData::traceFrames]);
    }

    /**
     * A node that could not read its directory knows nothing about failures — which is not the
     * same as knowing there were none — and it adds nothing here while its neighbours still speak.
     */
    public function testAnUnreadableNodeAddsNothingToThePanel(): void
    {
        $this->fileThePicture(
            self::nodeSlot('node-1', recentErrors: [self::failure(30, 'worker-0.error.log', message: 'a real failure')]),
            self::nodeSlot('node-2', available: false),
        );
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $overview = $this->overview();
        $this->assertSame(
            ['a real failure'],
            array_column($overview->recentErrors, HilosLogsOverviewSignalData::message),
        );
    }

    /**
     * The same trap the node rows fall into, one field further along: a picture in which ONLY a
     * failure arrived leaves every scalar where it was, so a fingerprint blind to the panel would
     * call the picture unchanged and leave the screen looking alive over a stale list.
     */
    public function testATickPushesWhenOnlyTheRecentErrorsChanged(): void
    {
        $this->fileThePicture(self::nodeSlot('node-1', recentErrors: [self::failure(120, 'worker-0.error.log', message: 'the first one')]));
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());
        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
        $this->drainTheQueue();

        $this->fileThePicture(self::nodeSlot('node-1', recentErrors: [
            self::failure(30, 'worker-0.error.log', message: 'and then another'),
            self::failure(120, 'worker-0.error.log', message: 'the first one'),
        ]));
        usleep(self::PAST_THE_TICK_THROTTLE_MICROSECONDS);
        AbstractHilosLogsPage::onAgentTick(new LogsPageSubscribeTestAgent());

        $overview = $this->overview();
        $this->assertSame(
            ['and then another', 'the first one'],
            array_column($overview->recentErrors, HilosLogsOverviewSignalData::message),
        );
    }

    /**
     * The warnings tab asks the same question of the whole installation, so every node's ring is one
     * list, ordered by time, and it does not spill into the errors tab beside it (HIL-868).
     */
    public function testTheRecentWarningsOfEveryNodeAreOneListNewestFirst(): void
    {
        $this->fileThePicture(
            self::nodeSlot('node-1', recentWarnings: [self::failure(120, 'worker-0.log', message: 'the older one')]),
            self::nodeSlot('node-2', recentWarnings: [self::failure(30, 'daemon.log', message: 'the newer one')]),
        );
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $overview = $this->overview();
        $this->assertSame(
            ['the newer one', 'the older one'],
            array_column($overview->recentWarnings, HilosLogsOverviewSignalData::message),
        );
        $this->assertSame('node-2', $overview->recentWarnings[0][HilosLogsOverviewSignalData::nodeId]);
        $this->assertFalse($overview->recentWarningsCapped);
        $this->assertSame([], $overview->recentErrors);
    }

    /**
     * The hour is the page's for warnings too: one clock cuts both tabs.
     */
    public function testAWarningOlderThanTheWindowIsCutOff(): void
    {
        $this->fileThePicture(self::nodeSlot('node-1', recentWarnings: [
            self::failure(60, 'worker-0.log', message: 'inside the window'),
            self::failure(self::AN_HOUR_IN_SECONDS + 60, 'worker-0.log', message: 'older than the window'),
        ]));
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $this->assertSame(
            ['inside the window'],
            array_column($this->overview()->recentWarnings, HilosLogsOverviewSignalData::message),
        );
    }

    /**
     * Each tab counts what it shows: warnings past the limit raise the warnings flag, and the errors
     * tab, holding one row, still says one.
     */
    public function testAWarningListCutAtTheLimitRaisesItsOwnFlagAlone(): void
    {
        $warnings = [];
        for ($index = 0; $index < 12; $index++) {
            $warnings[] = self::failure($index + 1, 'worker-0.log', message: "warning {$index}");
        }
        $this->fileThePicture(self::nodeSlot(
            'node-1',
            recentErrors: [self::failure(30, 'worker-0.error.log', message: 'the only failure')],
            recentWarnings: $warnings,
        ));
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $overview = $this->overview();
        $this->assertCount(10, $overview->recentWarnings);
        $this->assertTrue($overview->recentWarningsCapped);
        $this->assertSame('warning 0', $overview->recentWarnings[0][HilosLogsOverviewSignalData::message]);
        $this->assertCount(1, $overview->recentErrors);
        $this->assertFalse($overview->recentErrorsCapped);
    }

    /**
     * A single-node installation has warnings to show as well, under the empty name the viewer
     * address reads as "here".
     */
    public function testAWarningOnAnUnnamedNodeCarriesTheEmptyNodeId(): void
    {
        $this->fileThePicture(self::nodeSlot(null, recentWarnings: [self::failure(30, 'daemon.log', message: 'slow')]));
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $overview = $this->overview();
        $this->assertCount(1, $overview->recentWarnings);
        $this->assertSame(
            HilosLogsOverviewSignalData::SELF_NODE_ID,
            $overview->recentWarnings[0][HilosLogsOverviewSignalData::nodeId],
        );
    }

    /**
     * The fingerprint is built from the payload, so a picture in which ONLY a warning arrived is a
     * changed picture and the tick sends it.
     */
    public function testATickPushesWhenOnlyTheRecentWarningsChanged(): void
    {
        $this->fileThePicture(self::nodeSlot('node-1', recentWarnings: [
            self::failure(120, 'worker-0.log', message: 'the first one'),
        ]));
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());
        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
        $this->drainTheQueue();

        $this->fileThePicture(self::nodeSlot('node-1', recentWarnings: [
            self::failure(30, 'worker-0.log', message: 'and then another'),
            self::failure(120, 'worker-0.log', message: 'the first one'),
        ]));
        usleep(self::PAST_THE_TICK_THROTTLE_MICROSECONDS);
        AbstractHilosLogsPage::onAgentTick(new LogsPageSubscribeTestAgent());

        $this->assertSame(
            ['and then another', 'the first one'],
            array_column($this->overview()->recentWarnings, HilosLogsOverviewSignalData::message),
        );
    }

    /**
     * Subscribing counts the connection as a viewer of the section, which is the only thing that
     * makes the aggregator send anything: without it the mirror would stay empty for good.
     */
    public function testSubscribingCountsTheConnectionAsAViewerOfTheSection(): void
    {
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        $this->assertSame([self::ACCEPT_KEY], ClusterLogIndexMirror::viewerKeys());

        $page->onUnsubscribe(self::ACCEPT_KEY);

        $this->assertSame(0, ClusterLogIndexMirror::viewerCount());
    }

    /**
     * The frame the page gained carries the identity the catalog holds for it, which is the whole
     * point of answering with it: the page itself writes none of these four keys.
     */
    public function testTheFrameCarriesTheCatalogIdentityOfTheLogsPage(): void
    {
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));

        Hilos::$sr?->getNextQueuedSignal();
        $signal = Hilos::$sr?->getNextQueuedSignal();

        $this->assertNotNull($signal);
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame(self::ACCEPT_KEY, $signal->data->targetAcceptKey);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
        $this->assertSame(
            [
                PageResponseSignalData::page => HilosPageConstants::HILOS_LOGS,
                PageResponseSignalData::payload => [
                    PagePayload::data => [
                        PageCatalogConstants::WIRE_PAGE_LABEL => 'Logs',
                        PageCatalogConstants::WIRE_PAGE_LEAD =>
                            'Rotation stats, log keys, workers, and the viewer.',
                        PageCatalogConstants::WIRE_PAGE_BREADCRUMB => [
                            [
                                PageCatalogConstants::WIRE_CRUMB_PAGE => HilosPageConstants::HILOS_DASHBOARD,
                                PageCatalogConstants::WIRE_CRUMB_LABEL => 'Hilos',
                            ],
                            [
                                PageCatalogConstants::WIRE_CRUMB_PAGE => HilosPageConstants::HILOS_LOGS,
                                PageCatalogConstants::WIRE_CRUMB_LABEL => 'Logs',
                            ],
                        ],
                        PageCatalogConstants::WIRE_PAGE_CHILDREN => [
                            [
                                PageCatalogConstants::WIRE_CHILD_PAGE => HilosPageConstants::HILOS_LOGS_KEYS,
                                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'By key',
                                PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Log volume grouped by log key.',
                            ],
                            [
                                PageCatalogConstants::WIRE_CHILD_PAGE => HilosPageConstants::HILOS_LOGS_WORKERS,
                                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'By worker',
                                PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Log volume grouped by worker.',
                            ],
                            [
                                PageCatalogConstants::WIRE_CHILD_PAGE => HilosPageConstants::HILOS_LOGS_ROTATIONS,
                                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Rotations',
                                PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Log rotation history and retention.',
                            ],
                            [
                                PageCatalogConstants::WIRE_CHILD_PAGE => HilosPageConstants::HILOS_LOGS_SETTINGS,
                                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Settings',
                                PageCatalogConstants::CATALOG_ENTRY_LEAD =>
                                    'Logging modes and what differs from the chosen one.',
                            ],
                            [
                                PageCatalogConstants::WIRE_CHILD_PAGE => HilosPageConstants::HILOS_LOGS_VIEW,
                                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Viewer',
                                PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Stream and filter log lines.',
                            ],
                        ],
                    ],
                ],
            ],
            $signal->data->data->toArray(),
        );
    }

    /**
     * A subscription that never got its answer leaves no subscriber: the overview went out ahead
     * of the refusal, the frame did not, and the connection stays off the tick's push list -
     * which is why the registration sits in the after-response hook and not beside the snapshot.
     */
    public function testARefusedSubscriptionLeavesNoSubscriberBehind(): void
    {
        Hilos::$browser = new LogsPageSubscribeTestRefusingBrowser();
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());

        try {
            $page->onSubscribe(self::REFUSED_ACCEPT_KEY, new PageRouteParams([]));
            $this->fail('The refusing browser must not let the subscription through.');
        } catch (PageInternalErrorException $exception) {
            $this->assertSame('Refused before the answer', $exception->getMessage());
        }

        $this->assertSame([HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS], $this->queuedSignalNames());
        $this->assertSame([], $this->tickRecipients());
        $this->assertSame(0, ClusterLogIndexMirror::viewerCount(), 'And no viewer either');
    }

    /**
     * Leaving the page takes the connection off the push list the tick reads.
     */
    public function testUnsubscribeTakesTheConnectionOffThePushList(): void
    {
        $page = new LogsPageSubscribeTestPage(new LogsPageSubscribeTestAgent());
        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
        $this->queuedSignalNames();

        $this->assertSame([self::ACCEPT_KEY], $this->tickRecipients());

        $page->onUnsubscribe(self::ACCEPT_KEY);

        $this->assertSame([], $this->tickRecipients());
    }

    /**
     * Runs one tick past its throttle, with the log store changed underneath so the overview
     * fingerprint really differs and a push has a reason to go out. An empty result therefore
     * means "nobody is registered" rather than "nothing happened".
     *
     * @return list<string> Accept keys the tick pushed the overview to
     */
    private function tickRecipients(): array
    {
        $this->growTheClusterPicture();
        usleep(self::PAST_THE_TICK_THROTTLE_MICROSECONDS);
        AbstractHilosLogsPage::onAgentTick(new LogsPageSubscribeTestAgent());

        $keys = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
            $this->assertSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS, $signal->signalName->getName());
            $keys[] = (string)$signal->data->targetAcceptKey;
        }

        return $keys;
    }

    /**
     * Drains the queue into the signal names it holds.
     *
     * @return list<string> Queued signal names, oldest first
     */
    private function queuedSignalNames(): array
    {
        $names = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $names[] = $signal->signalName->getName();
        }

        return $names;
    }

    /**
     * Files one more frame of the cluster picture, heavier than the last, so the overview scalars -
     * and with them the fingerprint the page compares against - are not what they were a moment ago.
     */
    private function growTheClusterPicture(): void
    {
        $this->keyBytes += 100;
        ClusterLogIndexMirror::applyPortion(ClusterLogIndexPortionSignalData::ofSlots(
            [
                new ClusterLogNodeSlot(
                    nodeId: 'node-1',
                    index: new NodeLogIndex(
                        nodeId: 'node-1',
                        available: true,
                        sampledAt: self::T0,
                        batches: [new LogBatchSummary(self::T0 - 3600, 1, 10, 0, 0, 0, 0, 0, 0)],
                        keys: [
                            new LogKeySummary('agent-a.log', LogKeySummary::CLASS_AGENT, true, [], $this->keyBytes),
                            new LogKeySummary('worker-0.log', LogKeySummary::CLASS_WORKER, true, [], 300),
                        ],
                        workers: [],
                        growthBytesPerDay: [],
                    ),
                    receivedAt: self::T0,
                ),
            ],
            true,
        ));
    }

    /**
     * Replaces the cluster picture with exactly the nodes a case cares about.
     *
     * A snapshot rather than a portion, so what was filed a moment ago is gone rather than laid
     * under it - a case that meant to describe two nodes would otherwise be describing three.
     *
     * @param ClusterLogNodeSlot ...$slots Nodes the picture is to hold
     */
    private function fileThePicture(ClusterLogNodeSlot ...$slots): void
    {
        ClusterLogIndexMirror::applyPortion(ClusterLogIndexPortionSignalData::ofSlots(array_values($slots), true));
    }

    /**
     * One node's slot, with everything a case does not name left empty.
     *
     * @param ?string $nodeId Node name, null for the installation that runs on one node
     * @param bool $available Whether that node could read its log store
     * @param list<LogBatchSummary> $batches Rotation batches the node holds
     * @param list<LogKeySummary> $keys Streams the node holds, live and archived together
     * @param array<string, ?int> $growthBytesPerDay Stream → bytes over the last day, null until its window fills
     * @param list<int> $due Batches this node's own retention rule recommends carrying off
     * @param list<LogRecentEntry> $recentErrors Failures the node read off the tails of its live error streams
     * @param list<LogRecentEntry> $recentWarnings Warnings the node found in its live streams
     * @param ?int $filesystemFreeBytes Free bytes this node measured on its log filesystem
     * @param ?int $filesystemTotalBytes Whole size of that filesystem as this node measured it
     * @param int $freeSpaceThresholdPercent Share of the volume this node resolved as its threshold
     * @return ClusterLogNodeSlot Slot as the aggregator would hold it
     */
    private static function nodeSlot(
        ?string $nodeId,
        bool $available = true,
        array $batches = [],
        array $keys = [],
        array $growthBytesPerDay = [],
        array $due = [],
        array $recentErrors = [],
        array $recentWarnings = [],
        ?int $filesystemFreeBytes = null,
        ?int $filesystemTotalBytes = null,
        int $freeSpaceThresholdPercent = LogSettingsCatalog::FREE_SPACE_THRESHOLD_FALLBACK_PERCENT,
    ): ClusterLogNodeSlot {
        return new ClusterLogNodeSlot(
            nodeId: $nodeId,
            index: new NodeLogIndex(
                nodeId: $nodeId,
                available: $available,
                sampledAt: self::T0,
                batches: $batches,
                keys: $keys,
                workers: [],
                growthBytesPerDay: $growthBytesPerDay,
                dueBatchTimestamps: $due,
                recentErrors: $recentErrors,
                recentWarnings: $recentWarnings,
                filesystemFreeBytes: $filesystemFreeBytes,
                filesystemTotalBytes: $filesystemTotalBytes,
                freeSpaceThresholdPercent: $freeSpaceThresholdPercent,
            ),
            receivedAt: self::T0,
        );
    }

    /**
     * One failure a node read off a live error stream, placed relative to now.
     *
     * The page cuts its window against the real clock, so a fixture failure has to be as recent
     * as it claims to be — {@see self::T0} is a fixed instant and says nothing about how long ago.
     *
     * @param int $secondsAgo How long before now the line was written
     * @param string $stream Basename of the stream it was written to
     * @param ?int $traceFrames Frames in its stack trace, null when it carries none
     * @param string $message Line text as the node cut it
     * @return LogRecentEntry Failure as the node reported it
     */
    private static function failure(
        int $secondsAgo,
        string $stream,
        ?int $traceFrames = null,
        string $message = 'something went wrong',
    ): LogRecentEntry {
        return new LogRecentEntry((time() - $secondsAgo) * TimeConstants::MS_PER_SECOND, $stream, $message, $traceFrames);
    }

    /**
     * One rotation batch, weighed class by class.
     *
     * @param int $timestamp When the batch was rotated, in Unix seconds
     * @param int $agentBytes What the agent files in it weigh
     * @param int $workerBytes What the worker files in it weigh
     * @param int $workerMonopolisticBytes What the monopolistic worker files in it weigh
     * @param int $daemonBytes What the daemon's own files in it weigh
     * @return LogBatchSummary Batch as the node reported it
     */
    private static function batch(
        int $timestamp,
        int $agentBytes = 0,
        int $workerBytes = 0,
        int $workerMonopolisticBytes = 0,
        int $daemonBytes = 0,
    ): LogBatchSummary {
        return new LogBatchSummary($timestamp, 1, $agentBytes, 1, $workerBytes, 1, $workerMonopolisticBytes, 1, $daemonBytes);
    }

    /**
     * Throws away whatever is queued, so what a case reads next is what a case caused.
     */
    private function drainTheQueue(): void
    {
        while (Hilos::$sr?->getNextQueuedSignal() !== null) {
            continue;
        }
    }

    /**
     * The mirror belongs to the worker process, so a case leaves it as it found it.
     */
    private function emptyTheMirror(): void
    {
        ClusterLogIndexMirror::forgetPicture();
        foreach (ClusterLogIndexMirror::viewerKeys() as $acceptKey) {
            ClusterLogIndexMirror::removeViewer($acceptKey);
        }
    }

    /**
     * Takes the overview the page answered a subscription with.
     *
     * @return HilosLogsOverviewSignalData Payload of the first queued signal
     */
    private function overview(): HilosLogsOverviewSignalData
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();

        $this->assertNotNull($signal, 'The subscription answers with an overview');
        $this->assertSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(HilosLogsOverviewSignalData::class, $signal->data->data);

        return $signal->data->data;
    }
}

/**
 * Concrete stand-in for a project's logs page, which adds nothing of its own.
 */
final class LogsPageSubscribeTestPage extends AbstractHilosLogsPage
{
}

/**
 * Browser refusing the snapshot the way a malformed page declaration does, so the subscription
 * fails between the overview and the frame.
 */
final class LogsPageSubscribeTestRefusingBrowser extends BrowserContext
{
    /**
     * @param string $page Page name from the subscription request
     * @param string $acceptKey Subscribing WebSocket accept key
     * @param PageRouteParams $params Route params for this page subscription
     * @throws PageInternalErrorException Always; the fixture exists to refuse
     */
    public function subscribeSnapshot(string $page, string $acceptKey, PageRouteParams $params): void
    {
        throw new PageInternalErrorException('Refused before the answer');
    }
}

/**
 * Minimal page agent providing a signal source for sendToUser and for the tick broadcast.
 */
final class LogsPageSubscribeTestAgent implements PageAgentInterface
{
    public function getId(): string
    {
        return 'hilos_logs';
    }

    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'hilos_logs');
    }
}
