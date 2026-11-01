<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\AdminViewMode\WireField;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserSourceConfig;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserFieldKey;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserGuardKey;
use Hilos\Core\Browser\Config\BrowserGuardType;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageResender;
use Hilos\Core\Page\PageResendOutcome;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Hilos;
use Hilos\Core\Agent\Hilos\DaemonCollectorAgent;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Pages\Daemon\AbstractHilosDaemonEnvMismatchPage;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use RuntimeException;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\API\Router\HttpRouteTally;
use Hilos\Cluster\ClusterContext;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Runtime\State\Item\HilosClusterNode;
use Hilos\DaemonSection\DaemonAgentPicture;
use Hilos\DaemonSection\DaemonCronPicture;
use Hilos\DaemonSection\DaemonCronRulePicture;
use Hilos\DaemonSection\DaemonHttpPicture;
use Hilos\DaemonSection\DaemonHttpRoutePicture;
use Hilos\DaemonSection\NodeEnvironmentFingerprint;
use Hilos\DaemonSection\NodeEnvironmentSummary;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvSource;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Tables\Daemon\HilosDaemonPlacementTable;
use Hilos\Tables\Daemon\HilosDaemonAgentsTable;
use Hilos\Tables\Daemon\HilosDaemonCronTable;
use Hilos\Tables\Daemon\HilosDaemonHttpRoutesTable;
use Hilos\Tables\Daemon\HilosDaemonEnvMismatchTable;
use ReflectionProperty;
use Hilos\Cluster\NodeRole;
use Hilos\Constants\WorkerConstants;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\GenericTableRow;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\ClusterDaemonPicture;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\DaemonPictureRowChanges;
use Hilos\DaemonSection\DaemonProcessRoster;
use Hilos\DaemonSection\DaemonWorkerPicture;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\Pages\Daemon\AbstractHilosDaemonCronPage;
use Hilos\Pages\Daemon\AbstractHilosDaemonWorkersPage;
use Hilos\Tables\Daemon\HilosDaemonWorkersTable;
use PHPUnit\Framework\TestCase;

/** Picture publication compares subject sets, independently of a delivered window. */
final class DaemonPictureLivePublicationTest extends TestCase
{
    private ?SignalRouter $previousRouter;
    private ?BrowserContext $previousBrowser;
    private ?TableContext $previousTables;
    private ?ClusterContext $previousCluster;
    private ?EnvAccessor $previousEnv;
    private string $previousAppClass;
    private ?HilosDbContext $previousDb;
    private array $previousDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRouter = Hilos::$sr;
        $this->previousBrowser = Hilos::$browser;
        $this->previousTables = Hilos::$table;
        $this->previousDeclarations = new ReflectionProperty(HilosDaemonAgentsTable::class, 'declarations')->getValue();
        $this->previousDb = Hilos::$db;
        Hilos::$db = null;
        $this->previousCluster = Hilos::$cluster;
        $this->previousEnv = Hilos::$env;
        $this->previousAppClass = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, LivePublicationTestHilos::class);
        Hilos::$cluster = null;
        Hilos::$env = new EnvAccessor();
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new LivePublicationTestTables();
        Hilos::$table->configure();
        Hilos::$browser = new LivePublicationTestBrowser();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = $this->previousRouter;
        Hilos::$browser = $this->previousBrowser;
        Hilos::$table = $this->previousTables;
        new ReflectionProperty(HilosDaemonAgentsTable::class, 'declarations')->setValue(null, $this->previousDeclarations);
        Hilos::$db = $this->previousDb;
        Hilos::$cluster = $this->previousCluster;
        Hilos::$env = $this->previousEnv;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousAppClass);
        ClusterDaemonPictureMirror::forgetPicture();
        ClusterDaemonPictureMirror::removeViewer('ak');
        AbstractHilosDaemonEnvMismatchPage::onPictureForgotten();
        AbstractHilosDaemonCronPage::onPictureForgotten();
        AbstractHilosDaemonWorkersPage::onPictureForgotten();
        parent::tearDown();
    }

    public function testAllSixMirrorTablesPublishNativeDeltasFromSerializedPortions(): void
    {
        $before = $this->completePicture(1);
        $current = $this->completePicture(2);
        foreach ([
            HilosDaemonPlacementTable::TABLE => ['x'],
            HilosDaemonWorkersTable::TABLE => ['regular:1'],
            HilosDaemonAgentsTable::TABLE => ['x:1'],
            HilosDaemonCronTable::TABLE => ['/cleanup'],
            HilosDaemonHttpRoutesTable::TABLE => ['GET/%2Fhealth'],
            HilosDaemonEnvMismatchTable::TABLE => ['DIVERGED', 'SOURCE'],
        ] as $tableKey => $keys) {
            ClusterDaemonPictureMirror::applyPortion(DaemonClusterPicturePortionSignalData::fromArray(
                new DaemonClusterPicturePortionSignalData(true, $before->nodes())->toArray(),
            ));
            $context = $this->subscribe(tableKey: $tableKey);
            ClusterDaemonPictureMirror::applyPortion(DaemonClusterPicturePortionSignalData::fromArray(
                new DaemonClusterPicturePortionSignalData(false, $current->nodes())->toArray(),
            ));
            $context->publishTableMutations('publication', 'ak', $tableKey,
                static fn (ViewportTable $table, TableQueryDTO $query): array => $table->pictureMutations($before, $query));
            $context->flushToSignalRouter();
            $frames = $this->frames();
            $deltas = [];
            foreach ($frames as [$name, $payload]) {
                self::assertNotSame(SignalTypeConstants::TABLE_WINDOW, $name);
                self::assertSame($tableKey, $payload->tableKey);
                if ($name === SignalTypeConstants::TABLE_VIEWPORT_DELTA) {
                    self::assertSame('row_updated', $payload->kind);
                    self::assertFalse($payload->own);
                    self::assertArrayNotHasKey('requestId', $payload->toArray());
                    $deltas[] = $payload->rowKey;
                }
            }
            self::assertSame($keys, $deltas, $tableKey);
            $context->publishTableMutations('publication', 'ak', $tableKey,
                static fn (ViewportTable $table, TableQueryDTO $query): array => $table->pictureMutations($current, $query));
            $context->flushToSignalRouter();
            self::assertSame([], $this->frames(), $tableKey . ' repeated an identical picture');
            ClusterDaemonPictureMirror::forgetPicture();
            ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, $current->nodes()));
            $context->publishTableMutations('publication', 'ak', $tableKey,
                static fn (ViewportTable $table, TableQueryDTO $query): array => $table->pictureMutations(null, $query));
            $context->flushToSignalRouter();
            self::assertSame([], $this->frames(), $tableKey . ' counted held rows twice after mirror restart');
        }
    }

    public function testUnknownMismatchSubscriptionRecoversBothBucketsWithBodiesThenUsesNativeUpdates(): void
    {
        ClusterDaemonPictureMirror::forgetPicture();
        $context = $this->subscribeMismatch();
        $initial = $this->frames();
        self::assertSame([SignalTypeConstants::PAGE_RESPONSE], array_column($initial, 0));
        self::assertNull($initial[0][1]->payload->data['envMismatch']['comparison']);
        AbstractHilosDaemonEnvMismatchPage::onPictureChanged(null);
        self::assertSame([], $this->frames());
        self::assertTrue(Hilos::$sr->isTableWindowOwed('ak', HilosDaemonEnvMismatchTable::TABLE));
        $context->flushToSignalRouter();

        $known = $this->completePicture(1);
        ClusterDaemonPictureMirror::applyPortion(DaemonClusterPicturePortionSignalData::fromArray(
            new DaemonClusterPicturePortionSignalData(true, $known->nodes())->toArray(),
        ));
        AbstractHilosDaemonEnvMismatchPage::onPictureChanged(null);
        $context->flushToSignalRouter();
        $recovery = array_values(array_filter($this->frames(),
            static fn (array $frame): bool => $frame[0] !== SignalTypeConstants::PAGE_RESPONSE));
        self::assertSame([SignalTypeConstants::TABLE_WINDOW], array_column($recovery, 0));
        $window = $recovery[0][1];
        self::assertSame(2, $window->totalCount);
        self::assertTrue($window->totalExact);
        self::assertSame(['DIVERGED', 'SOURCE'], array_column($window->rows, 'rowKey'));
        foreach ($window->rows as $row) {
            self::assertSame('aaaaaaaaaaaaaaaa', $row['slots']['key']['cells'][0]['digest']);
            self::assertSame('known', $row['slots']['key']['cells'][0]['state']);
        }
        self::assertSame([['field' => 'position', 'direction' => 'asc']], Hilos::$sr->getTableViewport('ak', $window->tableKey)->sort->toArray());
        self::assertSame(['position' => 0, 'rowKey' => 'DIVERGED'], $window->firstAnchor->toArray());
        self::assertSame(['position' => 1, 'rowKey' => 'SOURCE'], $window->lastAnchor->toArray());
        self::assertArrayNotHasKey('requestId', $window->toArray());
        self::assertFalse(Hilos::$sr->isTableWindowOwed('ak', $window->tableKey));
        self::assertArrayHasKey($window->tableKey, $initial[0][1]->payload->refusedWindows);
        self::assertSame([], $initial[0][1]->payload->windows);

        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(false, $this->completePicture(2)->nodes()));
        AbstractHilosDaemonEnvMismatchPage::onPictureChanged($known);
        $context->flushToSignalRouter();
        $updates = array_values(array_filter($this->frames(),
            static fn (array $frame): bool => $frame[0] !== SignalTypeConstants::PAGE_RESPONSE));
        self::assertSame([SignalTypeConstants::TABLE_VIEWPORT_DELTA, SignalTypeConstants::TABLE_VIEWPORT_DELTA], array_column($updates, 0));
        self::assertSame('cccccccccccccccc', $updates[0][1]->row['slots']['key']['cells'][1]['digest']);
        self::assertSame('example', $updates[1][1]->row['slots']['key']['cells'][1]['source']);
    }

    public function testKnownEmptyMismatchSubscriptionUsesOrderedArrivals(): void
    {
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, []));
        $context = $this->subscribeMismatch();
        $initial = $this->frames();
        self::assertCount(1, $initial);
        self::assertSame([], $initial[0][1]->payload->refusedWindows);
        foreach ($initial[0][1]->payload->windows as $tableKey => $window) {
            self::assertSame([], $window['rows']);
            self::assertSame(0, $window['totalCount']);
            self::assertFalse(Hilos::$sr->isTableWindowOwed('ak', $tableKey));
        }
        $before = ClusterDaemonPictureMirror::picture();
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(false, $this->completePicture(1)->nodes()));
        AbstractHilosDaemonEnvMismatchPage::onPictureChanged($before);
        $context->flushToSignalRouter();
        $arrivals = array_values(array_filter($this->frames(),
            static fn (array $frame): bool => $frame[0] !== SignalTypeConstants::PAGE_RESPONSE));
        self::assertSame([
            SignalTypeConstants::TABLE_VIEWPORT_APPEND,
            SignalTypeConstants::TABLE_VIEWPORT_APPEND,
            SignalTypeConstants::TABLE_VIEWPORT_COUNT,
        ], array_column($arrivals, 0));
        self::assertSame('DIVERGED', $arrivals[0][1]->row['rowKey']);
        self::assertSame('SOURCE', $arrivals[1][1]->row['rowKey']);
        self::assertSame(2, $arrivals[2][1]->totalCount);
        $held = Hilos::$sr->getTableViewport('ak', HilosDaemonEnvMismatchTable::TABLE);
        self::assertTrue($held->hasRow('DIVERGED'));
        self::assertTrue($held->hasRow('SOURCE'));
    }

    public function testMismatchBucketChangeMovesOneStableKeyAndRemovalLeavesTheSet(): void
    {
        foreach ([false, true] as $viewer) {
            $before = $this->mismatchPicture(false);
            ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, $before->nodes()));
            $context = $this->subscribeMismatch($viewer);
            $initial = $this->frames()[0][1]->payload->windows[HilosDaemonEnvMismatchTable::TABLE];
            self::assertSame(['A', 'B', 'C'], array_column($initial['rows'], 'rowKey'));
            self::assertSame([['field' => 'position', 'direction' => 'asc']], $initial['sort']);
            self::assertSame(3, $initial['totalCount']);
            $current = $this->mismatchPicture(true);
            ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, $current->nodes()));
            AbstractHilosDaemonEnvMismatchPage::onPictureChanged($before);
            $context->flushToSignalRouter();
            $deltas = array_values(array_filter($this->frames(),
                static fn (array $frame): bool => $frame[0] === SignalTypeConstants::TABLE_VIEWPORT_DELTA));
            self::assertSame(['B', 'A'], array_map(static fn (array $frame): string => $frame[1]->rowKey, $deltas));
            self::assertSame('row_updated', $deltas[0][1]->kind);
            self::assertSame('row_moved', $deltas[1][1]->kind);
            foreach ($deltas as [$name, $delta]) {
                self::assertFalse($delta->own);
            }
            self::assertSame('A', $deltas[1][1]->row['rowKey']);
            self::assertSame('sourcesDiffer', $deltas[1][1]->row['slots']['key']['kind']);
            self::assertSame(1, $deltas[1][1]->row['slots']['key']['position']);
            $held = Hilos::$sr->getTableViewport('ak', HilosDaemonEnvMismatchTable::TABLE);
            self::assertSame(3, $held->totalCount());
            self::assertTrue($held->hasRow('A'));
            $window = new HilosDaemonEnvMismatchTable()->getPage(new TableQueryDTO(sort: $held->sort));
            self::assertSame(['B', 'A', 'C'], array_column($window->rows, 'rowKey'));
            ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, []));
            AbstractHilosDaemonEnvMismatchPage::onPictureChanged($current);
            $context->flushToSignalRouter();
            $removed = array_values(array_filter($this->frames(),
                static fn (array $frame): bool => $frame[0] === SignalTypeConstants::TABLE_VIEWPORT_DELTA));
            self::assertCount(3, $removed);
            foreach ($removed as [$name, $delta]) {
                self::assertSame('row_removed', $delta->kind);
                self::assertSame('deleted', $delta->reason);
            }
        }
    }

    /** @return ClusterDaemonPicture Two verdict groups with a key that can change groups */
    private function mismatchPicture(bool $moved): ClusterDaemonPicture
    {
        $picture = ClusterDaemonPicture::empty();
        foreach (['n1', 'n2'] as $nodeId) {
            $fingerprints = [
                new NodeEnvironmentFingerprint('A', 'string', $nodeId === 'n1' ? EnvSource::PROCESS : EnvSource::ENV_FILE,
                    false, $nodeId === 'n1' || $moved ? 'a' : 'b'),
                new NodeEnvironmentFingerprint('B', 'string', EnvSource::PROCESS, false, $nodeId === 'n1' ? 'a' : 'b'),
                new NodeEnvironmentFingerprint('C', 'string', $nodeId === 'n1' ? EnvSource::PROCESS : EnvSource::EXAMPLE, false, 'a'),
            ];
            $report = new NodeDaemonPicture($nodeId, NodeRole::Master, 100,
                environment: new NodeEnvironmentSummary(3, 0, 0, 0, 0, $fingerprints));
            $picture = $picture->withNode(new ClusterDaemonNodeView($nodeId, true, new ClusterDaemonNodeSlot($nodeId, $report, 100)));
        }
        return $picture;
    }

    public function testEachRecipientBuildsItsOwnSubjectAndOnlyTheChangedNodeGetsADelta(): void
    {
        $before = $this->workers('n1', [1 => 100])->withNode($this->workers('n2', [1 => 100])->nodes()[0]);
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, $before->nodes()));
        $context = $this->subscribe();
        Hilos::$sr->subscribeToPage('publication', new WebSocketPageSubscribeSignalDTO('other', 'publication'));
        $other = new TableViewportSubscription(HilosDaemonWorkersTable::TABLE, filter: ['node' => 'n2']);
        Hilos::$sr->setTableViewport('other', $other);
        self::assertTrue($context->sendTableWindow('publication', 'other', $other));
        $frame = Hilos::$sr->getNextQueuedSignal();
        self::assertSame('other', $frame->data->targetAcceptKey);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
        $current = $before->withNode($this->workers('n1', [1 => 200])->nodes()[0]);
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(false, $current->nodes()));
        $subjects = [];
        foreach (['ak', 'other'] as $acceptKey) {
            $context->publishTableMutations('publication', $acceptKey, HilosDaemonWorkersTable::TABLE,
                static function (ViewportTable $table, TableQueryDTO $query) use ($before, &$subjects): array {
                    $subjects[] = $query->filter['node'];
                    return $table->pictureMutations($before, $query);
                });
        }
        $context->flushToSignalRouter();
        self::assertSame(['n1', 'n2'], $subjects);
        $frames = $this->frames();
        self::assertSame([SignalTypeConstants::TABLE_VIEWPORT_DELTA], array_column($frames, 0));
        self::assertSame(200, $frames[0][1]->row['slots']['worker']['memoryBytes']);
        self::assertSame($other, Hilos::$sr->getTableViewport('other', HilosDaemonWorkersTable::TABLE));
    }

    public function testViewerPictureUpdatesKeepTheDeliveredWindowAcrossPublications(): void
    {
        $before = $this->workers('n1', [1 => 100]);
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, $before->nodes()));
        $context = $this->subscribe(userId: null);
        $context->viewer = true;
        $held = $context->viewportForViewer('publication', 'ak', Hilos::$sr->getTableViewport('ak', HilosDaemonWorkersTable::TABLE));
        Hilos::$sr->setTableViewport('ak', $held);
        self::assertTrue($context->sendTableWindow('publication', 'ak', $held));
        $this->frames();
        self::assertTrue($held->hasRow('regular:1'));

        foreach ([200, 300] as $memory) {
            $current = $this->workers('n1', [1 => $memory]);
            ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(false, $current->nodes()));
            $context->publishTableMutations('publication', 'ak', HilosDaemonWorkersTable::TABLE,
                static fn (ViewportTable $table, TableQueryDTO $query): array => $table->pictureMutations($before, $query));
            $context->flushToSignalRouter();
            $deltas = array_values(array_filter($this->frames(),
                static fn (array $frame): bool => $frame[0] === SignalTypeConstants::TABLE_VIEWPORT_DELTA));
            self::assertCount(1, $deltas);
            self::assertSame('row_updated', $deltas[0][1]->kind);
            self::assertSame('regular:1', $deltas[0][1]->rowKey);
            self::assertSame($memory, $deltas[0][1]->row['slots']['worker']['memoryBytes']);
            self::assertFalse($deltas[0][1]->own);
            self::assertSame($held, Hilos::$sr->getTableViewport('ak', HilosDaemonWorkersTable::TABLE));
            self::assertTrue($held->matchesRow('regular:1', $deltas[0][1]->row));
            $before = $current;
        }
    }

    public function testViewerPageDataHidesUndeclaredValuesBeforeSerialization(): void
    {
        $context = $this->subscribe();
        $context->viewer = true;
        $context->publishPageData('publication', 'ak', static fn (): PagePayload => new PagePayload(data: [
            'shown' => 2, 'private' => 'secret-value',
        ]), ['shown' => WireField::notPersonal()]);
        $frames = $this->frames();
        self::assertCount(1, $frames);
        self::assertSame(['shown' => 2, 'private' => HiddenValue::mark()], $frames[0][1]->payload->data);
        self::assertStringNotContainsString('secret-value', json_encode($frames[0][1]->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testLateUnsubscribeDuringBuildReleasesTheCallbackWithoutDelivery(): void
    {
        $context = $this->subscribe();
        $context->publishPageData('publication', 'ak', static function (): PagePayload {
            Hilos::$sr->unsubscribeFromPage('publication', new WebSocketPageUnsubscribeSignalDTO('ak', 'publication'));
            return new PagePayload(data: ['value' => 2]);
        }, ['value' => WireField::notPersonal()]);
        self::assertSame([], $this->frames());
        $context->publishTableMutations('publication', 'ak', HilosDaemonWorkersTable::TABLE,
            static function (): array { self::fail('A departed subscription built mutations'); });
        self::assertSame([], $context->flushToSignalRouter());
    }

    public function testInvalidPartialFailsThePageOnceAndOnlyAFullVerdictCanRecoverIt(): void
    {
        $context = $this->subscribe();
        $bad = static fn (): PagePayload => new PagePayload(windows: ['unexpected' => []]);
        $context->publishPageData('publication', 'ak', $bad, []);
        $context->publishPageData('publication', 'ak', $bad, []);
        self::assertSame(['subscription_page_error'], array_column($this->frames(), 0));
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure('ak'));
        $context->flushToSignalRouter();
        $resender = new LivePublicationTestResender();
        $resender->outcome = PageResendOutcome::Refused;
        $context->bindPageResender($resender);
        foreach ([1, 2] as $attempt) {
            $context->publishPageData('publication', 'ak', $bad, []);
            $context->publishTableMutations('publication', 'ak', HilosDaemonWorkersTable::TABLE,
                static function (): array { self::fail('A whole-page debt allowed a table partial'); });
            self::assertSame($attempt, $resender->attempts);
            self::assertTrue(Hilos::$sr->hasPageDeliveryFailure('ak'));
            self::assertSame([], $this->frames());
            $context->flushToSignalRouter();
        }
        $resender->outcome = PageResendOutcome::Answered;
        $context->publishPageData('publication', 'ak', $bad, []);
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure('ak'));
        self::assertSame(3, $resender->attempts);
        self::assertSame([], $this->frames());
    }

    public function testFocusedWorkerOutsideTheWindowGetsItsFreshBodyWithoutReplacingTheWindow(): void
    {
        $before = $this->workers('n1', [1 => 100, 2 => 200]);
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, $before->nodes()));
        $context = $this->subscribe();
        $viewport = new TableViewportSubscription(HilosDaemonWorkersTable::TABLE, filter: ['node' => 'n1'], limit: 1);
        Hilos::$sr->setTableViewport('ak', $viewport);
        $context->sendTableWindow('publication', 'ak', $viewport);
        $this->frames();
        Hilos::$sr->setTableFocus('ak', HilosDaemonWorkersTable::TABLE, 'regular:2');
        $current = $this->workers('n1', [1 => 100, 2 => 300]);
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, $current->nodes()));
        $context->publishTableMutations('publication', 'ak', HilosDaemonWorkersTable::TABLE,
            static fn (ViewportTable $table, TableQueryDTO $query): array => $table->pictureMutations($before, $query));
        $context->flushToSignalRouter();
        $frames = $this->frames();
        self::assertSame([SignalTypeConstants::TABLE_VIEWPORT_DELTA], array_column($frames, 0));
        self::assertSame('moved_out', $frames[0][1]->reason);
        self::assertSame(300, $frames[0][1]->row['slots']['worker']['memoryBytes']);
        self::assertSame($viewport, Hilos::$sr->getTableViewport('ak', HilosDaemonWorkersTable::TABLE));
        self::assertTrue($viewport->hasRow('regular:1'));
        self::assertFalse($viewport->hasRow('regular:2'));
    }

    /**
     * @param int $version Source version, changing one shown row of every mirror table
     * @return ClusterDaemonPicture Two nodes with both environment verdict buckets
     */
    private function completePicture(int $version): ClusterDaemonPicture
    {
        $picture = ClusterDaemonPicture::empty();
        foreach (['n1', 'n2'] as $nodeId) {
            $agents = [new DaemonAgentPicture('x:1', $version === 1 ? DaemonAgentPicture::SCOPE_CLUSTER : DaemonAgentPicture::SCOPE_NODE,
                $version === 1 ? DaemonAgentPicture::PLACEMENT_POLICY : DaemonAgentPicture::PLACEMENT_NODE)];
            if ($version === 2) {
                $agents[] = new DaemonAgentPicture('x:2', DaemonAgentPicture::SCOPE_CLUSTER, DaemonAgentPicture::PLACEMENT_POLICY);
            }
            $report = new NodeDaemonPicture($nodeId, NodeRole::Master, 100,
                new DaemonProcessRoster([new DaemonWorkerPicture(1, 'regular', 123, $version * 100, $agents)], [], 0),
                new DaemonCronPicture(null, [new DaemonCronRulePicture(null, 'cleanup', '* * * * *', null, $version * 120000)]),
                new NodeEnvironmentSummary(2, 0, 0, 0, 0, [
                    new NodeEnvironmentFingerprint('DIVERGED', 'string', EnvSource::PROCESS, false,
                        $nodeId === 'n1' ? 'aaaaaaaaaaaaaaaa' : ($version === 1 ? 'bbbbbbbbbbbbbbbb' : 'cccccccccccccccc')),
                    new NodeEnvironmentFingerprint('SOURCE', 'string', $nodeId === 'n1' ? EnvSource::PROCESS
                        : ($version === 1 ? EnvSource::ENV_FILE : EnvSource::EXAMPLE), false, 'aaaaaaaaaaaaaaaa'),
                ]),
                http: new DaemonHttpPicture('localhost', 80, 0, [
                    new DaemonHttpRoutePicture('GET', '/health', null, new HttpRouteTally($version, 0, 0, 10)),
                ], new HttpRouteTally(0, 0, 0, null)),
            );
            $picture = $picture->withNode(new ClusterDaemonNodeView($nodeId, true, new ClusterDaemonNodeSlot($nodeId, $report, 100)));
        }
        return $picture;
    }

    public function testSourceReadinessAndBrokenDeclarationAreContainedBeforeAnyBuild(): void
    {
        $context = $this->subscribe();
        $build = static function (): PagePayload { self::fail('An unready or broken page built its data'); };
        $context->readClusterSource = true;
        $states = new ReflectionProperty(SourceInterestRegistry::class, 'states');
        $previousStates = $states->getValue();
        $states->setValue(null, []);
        SourceInterestRegistry::readsWhatIsDelivered();
        try {
            $context->publishPageData('publication', 'ak', $build, []);
            $context->publishTableMutations('publication', 'ak', HilosDaemonWorkersTable::TABLE,
                static function (): array { self::fail('An unready source built table mutations'); });
            self::assertSame([], $this->frames());
            self::assertFalse(Hilos::$sr->hasPageDeliveryFailure('ak'));
        } finally {
            SourceInterestRegistry::readsWhatItMounts();
            $states->setValue(null, $previousStates);
            $context->readClusterSource = false;
        }
        $context->broken = true;
        $context->publishPageData('publication', 'ak', $build, []);
        $context->publishPageData('publication', 'ak', $build, []);
        self::assertSame(['subscription_page_error'], array_column($this->frames(), 0));
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure('ak'));
    }

    public function testThrowAfterTableUnsubscribeDoesNotFreezeTheDepartedScope(): void
    {
        $context = $this->subscribe();
        $context->publishTableMutations('publication', 'ak', HilosDaemonWorkersTable::TABLE,
            static function (): array {
                Hilos::$sr->unsubscribeFromPage('publication', new WebSocketPageUnsubscribeSignalDTO('ak', 'publication'));
                throw new RuntimeException('source failed after unsubscribe');
            });
        self::assertSame([], $this->frames());
        self::assertFalse(Hilos::$sr->isTableWindowOwed('ak', HilosDaemonWorkersTable::TABLE));
    }

    public function testReportedEmptyCanCatchUpAnOwedWindowWithoutAMutation(): void
    {
        $context = $this->subscribe();
        Hilos::$sr->oweTableWindow('ak', HilosDaemonWorkersTable::TABLE);
        $context->publishTableMutations('publication', 'ak', HilosDaemonWorkersTable::TABLE, static fn (): array => []);
        $context->flushToSignalRouter();
        $frames = $this->frames();
        self::assertSame([SignalTypeConstants::TABLE_WINDOW], array_column($frames, 0));
        self::assertSame(0, $frames[0][1]->totalCount);
        self::assertSame([], $frames[0][1]->rows);
        self::assertArrayNotHasKey('requestId', $frames[0][1]->toArray());
        self::assertFalse(Hilos::$sr->isTableWindowOwed('ak', HilosDaemonWorkersTable::TABLE));
    }

    /** A frozen Workers window catches up once, after every picture portion of the tick. */
    public function testOwedWorkersWindowCatchesUpLatestPictureOnlyAtFlush(): void
    {
        $before = $this->workers('n1', [1 => 100]);
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, $before->nodes()));
        $context = $this->subscribe();
        $context->publishTableMutations('publication', 'ak', HilosDaemonWorkersTable::TABLE,
            static function (): array { throw new RuntimeException('broken projection'); });
        self::assertSame([SignalTypeConstants::TABLE_VIEWPORT_FROZEN], array_column($this->frames(), 0));
        $context->flushToSignalRouter();
        self::assertTrue(Hilos::$sr->isTableWindowOwed('ak', HilosDaemonWorkersTable::TABLE));

        $middle = $this->workers('n1', [1 => 200]);
        $current = $this->workers('n1', [1 => 300, 2 => 400]);
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(false, $middle->nodes()));
        $context->publishTableMutations('publication', 'ak', HilosDaemonWorkersTable::TABLE,
            static fn (ViewportTable $table, TableQueryDTO $query): array => $table->pictureMutations($before, $query));
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(false, $current->nodes()));
        $context->publishTableMutations('publication', 'ak', HilosDaemonWorkersTable::TABLE,
            static fn (ViewportTable $table, TableQueryDTO $query): array => $table->pictureMutations($middle, $query));
        self::assertSame([], $this->frames());
        self::assertTrue(Hilos::$sr->isTableWindowOwed('ak', HilosDaemonWorkersTable::TABLE));

        self::assertSame([], $context->flushToSignalRouter());
        $frames = $this->frames();
        self::assertSame([SignalTypeConstants::TABLE_WINDOW], array_column($frames, 0));
        self::assertSame(HilosDaemonWorkersTable::TABLE, $frames[0][1]->tableKey);
        self::assertSame(['regular:1', 'regular:2'], array_column($frames[0][1]->rows, 'rowKey'));
        self::assertSame([300, 400], array_map(static fn (array $row): int => $row['slots']['worker']['memoryBytes'], $frames[0][1]->rows));
        self::assertSame(2, $frames[0][1]->totalCount);
        self::assertTrue($frames[0][1]->totalExact);
        self::assertArrayNotHasKey('requestId', $frames[0][1]->toArray());
        self::assertFalse(Hilos::$sr->isTableWindowOwed('ak', HilosDaemonWorkersTable::TABLE));
        $context->flushToSignalRouter();
        self::assertSame([], $this->frames());
    }

    /** A catch-up marked for a subscription that left must not reach its former connection. */
    public function testOwedWindowUnsubscribedBeforeFlushSendsNothing(): void
    {
        $context = $this->subscribe();
        Hilos::$sr->oweTableWindow('ak', HilosDaemonWorkersTable::TABLE);
        $context->publishTableMutations('publication', 'ak', HilosDaemonWorkersTable::TABLE, static fn (): array => []);
        Hilos::$sr->unsubscribeFromPage('publication', new WebSocketPageUnsubscribeSignalDTO('ak', 'publication'));

        self::assertSame([], $context->flushToSignalRouter());
        self::assertSame([], $this->frames());
        self::assertNull(Hilos::$sr->pageSubscription('ak'));
        self::assertNull(Hilos::$sr->getTableViewport('ak', HilosDaemonWorkersTable::TABLE));
        self::assertFalse(Hilos::$sr->isTableWindowOwed('ak', HilosDaemonWorkersTable::TABLE));
    }

    /** A catch-up owns the rest of its flush, including repeated picture diffs. */
    public function testUnknownCronEmptyWindowCatchesUpInPictureOrderWithoutLiveFrames(): void
    {
        $before = $this->cronPicture(null);
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, $before->nodes()));
        $viewport = new TableViewportSubscription(HilosDaemonCronTable::TABLE, filter: ['node' => 'n1']);
        $context = $this->subscribeNodeWindow(AbstractHilosDaemonCronPage::PAGE, $viewport);
        self::assertNull($viewport->firstAnchor());
        $current = $this->cronPicture(new DaemonCronPicture(null, [
            new DaemonCronRulePicture(null, 'zeta', '* * * * *', null, 120000),
            new DaemonCronRulePicture('x:1', 'alpha', '0 * * * *', 60000, 3600000),
        ]));

        $this->publishCronPicture($before, $current);
        AbstractHilosDaemonCronPage::onPictureChanged($before);
        $context->flushToSignalRouter();

        $frames = $this->frames();
        self::assertSame([SignalTypeConstants::TABLE_WINDOW], array_column($frames, 0));
        $window = $frames[0][1];
        self::assertSame(HilosDaemonCronTable::TABLE, $window->tableKey);
        self::assertSame(2, $window->totalCount);
        self::assertTrue($window->totalExact);
        self::assertSame(['/zeta', 'x%3A1/alpha'], array_column($window->rows, 'rowKey'));
        self::assertSame([
            'rowKey' => 'x%3A1/alpha', 'agentId' => 'x:1', 'name' => 'alpha', 'expression' => '0 * * * *',
            'lastRunAt' => 60000, 'nextRunAt' => 3600000, 'idleReason' => null,
        ], $window->rows[1]['slots']['rule']);
        self::assertArrayNotHasKey('requestId', $window->toArray());
        self::assertSame($viewport, Hilos::$sr->getTableViewport('ak', HilosDaemonCronTable::TABLE));
        self::assertSame(['node' => 'n1'], $viewport->filter);
        self::assertNull($viewport->sort);
        self::assertFalse(Hilos::$sr->isTableWindowOwed('ak', HilosDaemonCronTable::TABLE));
    }

    /** A reported empty schedule can precede a later agent's first rules. */
    public function testKnownEmptyCronWindowWaitsForRuleChangesThenCatchesUpAgentRules(): void
    {
        $empty = $this->cronPicture(new DaemonCronPicture(null, []));
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, $empty->nodes()));
        $context = $this->subscribeNodeWindow(AbstractHilosDaemonCronPage::PAGE,
            new TableViewportSubscription(HilosDaemonCronTable::TABLE, filter: ['node' => 'n1']));
        self::assertNull(Hilos::$sr->getTableViewport('ak', HilosDaemonCronTable::TABLE)->firstAnchor());
        $this->publishCronPicture($empty, $empty);
        $context->flushToSignalRouter();
        self::assertSame([], $this->frames());
        self::assertFalse(Hilos::$sr->isTableWindowOwed('ak', HilosDaemonCronTable::TABLE));

        $current = $this->cronPicture(new DaemonCronPicture(null, [
            new DaemonCronRulePicture('x:1', 'cleanup', '* * * * *', null, 120000),
        ]));
        $this->publishCronPicture($empty, $current);
        $context->flushToSignalRouter();

        $frames = $this->frames();
        self::assertSame([SignalTypeConstants::TABLE_WINDOW], array_column($frames, 0));
        self::assertSame(['x%3A1/cleanup'], array_column($frames[0][1]->rows, 'rowKey'));
        self::assertSame(1, $frames[0][1]->totalCount);
        self::assertArrayNotHasKey('requestId', $frames[0][1]->toArray());
        self::assertFalse(Hilos::$sr->isTableWindowOwed('ak', HilosDaemonCronTable::TABLE));
    }

    /** Subject changes trigger recovery even when their rows stay outside the search. */
    public function testEmptyCronSearchCatchesUpSubjectChangesWithTheHeldSearchAndSort(): void
    {
        $before = $this->cronPicture(new DaemonCronPicture(null, [
            new DaemonCronRulePicture(null, 'cleanup', '* * * * *', null, 120000),
        ]));
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, $before->nodes()));
        $viewport = new TableViewportSubscription(HilosDaemonCronTable::TABLE,
            filter: ['node' => 'n1', 'search' => 'JOB'],
            sort: TableSortOrderDTO::of(new TableSortDTO('name', 'desc')), limit: 2, pageIndex: 0);
        $context = $this->subscribeNodeWindow(AbstractHilosDaemonCronPage::PAGE, $viewport);
        self::assertNull($viewport->firstAnchor());

        $unmatched = $this->cronPicture(new DaemonCronPicture(null, [
            new DaemonCronRulePicture(null, 'cleanup', '* * * * *', 120000, 180000),
        ]));
        $this->publishCronPicture($before, $unmatched);
        $context->flushToSignalRouter();
        $frames = $this->frames();
        self::assertSame([SignalTypeConstants::TABLE_WINDOW], array_column($frames, 0));
        self::assertSame([], $frames[0][1]->rows);
        self::assertSame(0, $frames[0][1]->totalCount);
        self::assertArrayNotHasKey('requestId', $frames[0][1]->toArray());
        self::assertFalse(Hilos::$sr->isTableWindowOwed('ak', HilosDaemonCronTable::TABLE));

        $current = $this->cronPicture(new DaemonCronPicture(null, [
            new DaemonCronRulePicture(null, 'ignored', '* * * * *', null, 240000),
            new DaemonCronRulePicture('x:1', 'job alpha', '* * * * *', null, 120000),
            new DaemonCronRulePicture('x:1', 'job zeta', '* * * * *', null, 180000),
        ]));
        $this->publishCronPicture($unmatched, $current);
        $context->flushToSignalRouter();
        $frames = $this->frames();
        self::assertSame([SignalTypeConstants::TABLE_WINDOW], array_column($frames, 0));
        self::assertSame(['x%3A1/job%20zeta', 'x%3A1/job%20alpha'], array_column($frames[0][1]->rows, 'rowKey'));
        self::assertSame(2, $frames[0][1]->totalCount);
        self::assertArrayNotHasKey('requestId', $frames[0][1]->toArray());
        self::assertSame($viewport, Hilos::$sr->getTableViewport('ak', HilosDaemonCronTable::TABLE));
        self::assertSame(['node' => 'n1', 'search' => 'JOB'], $viewport->filter);
        self::assertSame([['field' => 'name', 'direction' => 'desc']], $viewport->sort->toArray());
        self::assertSame(2, $viewport->limit);
        self::assertSame(0, $viewport->pageIndex);
        self::assertNull($viewport->anchor);
        self::assertFalse(Hilos::$sr->isTableWindowOwed('ak', HilosDaemonCronTable::TABLE));
    }

    /** Distinct picture portions before one flush must catch up to the latest matching rules. */
    public function testEmptyCronSearchCatchesUpTwoPicturePortionsBeforeOneFlush(): void
    {
        $before = $this->cronPicture(new DaemonCronPicture(null, [
            new DaemonCronRulePicture(null, 'cleanup', '* * * * *', null, 120000),
        ]));
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, $before->nodes()));
        $viewport = new TableViewportSubscription(HilosDaemonCronTable::TABLE,
            filter: ['node' => 'n1', 'search' => 'JOB'],
            sort: TableSortOrderDTO::of(new TableSortDTO('name', 'desc')), limit: 2, pageIndex: 0);
        $context = $this->subscribeNodeWindow(AbstractHilosDaemonCronPage::PAGE, $viewport);
        self::assertNull($viewport->firstAnchor());
        self::assertFalse(Hilos::$sr->isTableWindowOwed('ak', HilosDaemonCronTable::TABLE));

        $unmatched = $this->cronPicture(new DaemonCronPicture(null, [
            new DaemonCronRulePicture(null, 'cleanup', '* * * * *', 120000, 180000),
        ]));
        $current = $this->cronPicture(new DaemonCronPicture(null, [
            new DaemonCronRulePicture(null, 'ignored', '* * * * *', null, 240000),
            new DaemonCronRulePicture('x:1', 'job alpha', '* * * * *', null, 120000),
            new DaemonCronRulePicture('x:1', 'job zeta', '* * * * *', null, 180000),
        ]));
        $this->publishCronPicture($before, $unmatched);
        $this->publishCronPicture($unmatched, $current);
        $context->flushToSignalRouter();
        $frames = $this->frames();

        self::assertEquals($current->node('n1')->slot->picture->cron,
            ClusterDaemonPictureMirror::picture()->node('n1')->slot->picture->cron);
        $this->publishCronPicture($current, $current);
        $context->flushToSignalRouter();
        self::assertSame([], $this->frames(), 'An unchanged picture must not be needed to finish the previous flush');
        self::assertSame([SignalTypeConstants::TABLE_WINDOW], array_column($frames, 0));
        self::assertSame(HilosDaemonCronTable::TABLE, $frames[0][1]->tableKey);
        self::assertArrayNotHasKey('requestId', $frames[0][1]->toArray());
        self::assertSame($viewport, Hilos::$sr->getTableViewport('ak', HilosDaemonCronTable::TABLE));
        self::assertSame(['node' => 'n1', 'search' => 'JOB'], $viewport->filter);
        self::assertSame([['field' => 'name', 'direction' => 'desc']], $viewport->sort->toArray());
        self::assertSame(2, $viewport->limit);
        self::assertSame(0, $viewport->pageIndex);
        self::assertNull($viewport->anchor);
        self::assertSame([
            'rowKeys' => ['x%3A1/job%20zeta', 'x%3A1/job%20alpha'],
            'totalCount' => 2,
            'totalExact' => true,
            'heldRows' => [true, true],
            'owed' => false,
        ], [
            'rowKeys' => array_column($frames[0][1]->rows, 'rowKey'),
            'totalCount' => $frames[0][1]->totalCount,
            'totalExact' => $frames[0][1]->totalExact,
            'heldRows' => [$viewport->hasRow('x%3A1/job%20zeta'), $viewport->hasRow('x%3A1/job%20alpha')],
            'owed' => Hilos::$sr->isTableWindowOwed('ak', HilosDaemonCronTable::TABLE),
        ], 'The catch-up must contain the latest picture, not the earlier unmatched portion');
    }

    /** A populated unsorted window retains its rows while its final-set count changes. */
    public function testPopulatedUnsortedCronWindowKeepsTheCountPublicationPath(): void
    {
        $before = $this->cronPicture(new DaemonCronPicture(null, [
            new DaemonCronRulePicture(null, 'cleanup', '* * * * *', null, 120000),
        ]));
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, $before->nodes()));
        $viewport = new TableViewportSubscription(HilosDaemonCronTable::TABLE, filter: ['node' => 'n1']);
        $context = $this->subscribeNodeWindow(AbstractHilosDaemonCronPage::PAGE, $viewport);
        self::assertNotNull($viewport->firstAnchor());
        $current = $this->cronPicture(new DaemonCronPicture(null, [
            new DaemonCronRulePicture(null, 'cleanup', '* * * * *', null, 120000),
            new DaemonCronRulePicture('x:1', 'added', '* * * * *', null, 180000),
        ]));

        $this->publishCronPicture($before, $current);
        $context->flushToSignalRouter();

        $frames = $this->frames();
        self::assertSame([SignalTypeConstants::TABLE_VIEWPORT_COUNT], array_column($frames, 0));
        self::assertSame(2, $frames[0][1]->totalCount);
        self::assertTrue($frames[0][1]->totalExact);
        self::assertTrue($viewport->hasRow('/cleanup'));
        self::assertFalse($viewport->hasRow('x%3A1/added'));
        self::assertFalse(Hilos::$sr->isTableWindowOwed('ak', HilosDaemonCronTable::TABLE));
    }

    /** Cron recovery leaves other node tables on their existing publication path. */
    public function testEmptyWorkersWindowKeepsTheCountPublicationPath(): void
    {
        $before = $this->workers('n1', []);
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, $before->nodes()));
        $viewport = new TableViewportSubscription(HilosDaemonWorkersTable::TABLE, filter: ['node' => 'n1']);
        $context = $this->subscribeNodeWindow(AbstractHilosDaemonWorkersPage::PAGE, $viewport);
        self::assertNull($viewport->firstAnchor());
        $current = $this->workers('n1', [1 => 100]);
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(false, $current->nodes()));
        LivePublicationWorkersPage::settleNodeData();

        AbstractHilosDaemonWorkersPage::onPictureChanged($before);
        $context->flushToSignalRouter();

        $frames = $this->frames();
        self::assertSame([SignalTypeConstants::TABLE_VIEWPORT_COUNT], array_column($frames, 0));
        self::assertSame(1, $frames[0][1]->totalCount);
        self::assertTrue($frames[0][1]->totalExact);
        self::assertFalse($viewport->hasRow('regular:1'));
        self::assertFalse(Hilos::$sr->isTableWindowOwed('ak', HilosDaemonWorkersTable::TABLE));
    }

    public function testDataPublicationHasOnlyDataAndLeavesTheHeldWindowAlone(): void
    {
        $context = $this->subscribe();
        $held = Hilos::$sr->getTableViewport('ak', HilosDaemonWorkersTable::TABLE);
        $context->publishPageData('publication', 'ak', static fn (): PagePayload => new PagePayload(data: ['value' => 2]), [
            'value' => WireField::notPersonal(),
        ]);
        $frames = $this->frames();
        self::assertCount(1, $frames);
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $frames[0][0]);
        self::assertSame(['page' => 'publication', 'payload' => ['data' => ['value' => 2]]], $frames[0][1]->toArray());
        self::assertSame($held, Hilos::$sr->getTableViewport('ak', HilosDaemonWorkersTable::TABLE));
    }

    public function testGuardAndDepartedSubscriptionAreCheckedBeforeTheCallback(): void
    {
        $context = $this->subscribe(userId: null);
        $context->authenticated = true;
        $called = 0;
        $build = static function () use (&$called): PagePayload {
            ++$called;
            return new PagePayload(data: ['value' => 2]);
        };
        $context->publishPageData('publication', 'ak', $build, []);
        $context->publishPageData('other', 'ak', $build, []);
        $context->publishPageData('publication', 'gone', $build, []);
        self::assertSame(0, $called);
        self::assertSame([], $this->frames());
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure('ak'));
    }

    public function testPageDebtTakesOneWholeAttemptAndSuppressesBothPartialPublishersUntilFlush(): void
    {
        $context = $this->subscribe();
        $resender = new LivePublicationTestResender();
        $resender->rebind = true;
        $context->bindPageResender($resender);
        Hilos::$sr->markPageDeliveryFailure('ak');
        $called = 0;
        $data = static function () use (&$called): PagePayload {
            ++$called;
            return new PagePayload(data: ['value' => 2]);
        };
        $mutations = static function () use (&$called): array {
            ++$called;
            return [];
        };
        $context->publishPageData('publication', 'ak', $data, []);
        $context->publishTableMutations('publication', 'ak', HilosDaemonWorkersTable::TABLE, $mutations);
        $context->publishPageData('publication', 'ak', $data, []);
        self::assertSame(1, $resender->attempts);
        self::assertSame(0, $called);
        self::assertSame([], $this->frames());
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure('ak'));
        self::assertSame([], $context->flushToSignalRouter());
        $context->publishPageData('publication', 'ak', $data, ['value' => WireField::notPersonal()]);
        self::assertSame(1, $called);
        self::assertCount(1, $this->frames());
    }

    public function testRealWorkerDiffUsesLiveClassifierWithoutAWindowOrCorrelationId(): void
    {
        $before = $this->workers('n1', [1 => 100]);
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, $before->nodes()));
        $context = $this->subscribe();
        $current = $this->workers('n1', [1 => 200]);
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, $current->nodes()));
        $context->publishTableMutations('publication', 'ak', HilosDaemonWorkersTable::TABLE,
            static fn (ViewportTable $table, TableQueryDTO $query): array => $table->pictureMutations($before, $query));
        $context->flushToSignalRouter();
        $frames = $this->frames();
        self::assertSame([SignalTypeConstants::TABLE_VIEWPORT_DELTA], array_column($frames, 0));
        $delta = $frames[0][1]->toArray();
        self::assertSame('row_updated', $delta['kind']);
        self::assertSame(200, $delta['row']['slots']['worker']['memoryBytes']);
        self::assertFalse($frames[0][1]->own);
        self::assertArrayNotHasKey('own', $delta);
        self::assertArrayNotHasKey('requestId', $delta);
        self::assertArrayNotHasKey('deliveryId', $delta);
    }

    public function testBrokenTableFreezesOnceAndNextSuccessfulPublicationCatchesUpWhole(): void
    {
        $picture = $this->workers('n1', [1 => 100]);
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, $picture->nodes()));
        $context = $this->subscribe();
        $broken = static function (): array { throw new RuntimeException('broken projection'); };
        $context->publishTableMutations('publication', 'ak', HilosDaemonWorkersTable::TABLE, $broken);
        $context->publishTableMutations('publication', 'ak', HilosDaemonWorkersTable::TABLE, $broken);
        self::assertSame([SignalTypeConstants::TABLE_VIEWPORT_FROZEN], array_column($this->frames(), 0));
        $context->flushToSignalRouter();
        $context->publishTableMutations('publication', 'ak', HilosDaemonWorkersTable::TABLE,
            static fn (ViewportTable $table, TableQueryDTO $query): array => $table->pictureMutations(null, $query));
        $context->flushToSignalRouter();
        self::assertSame([SignalTypeConstants::TABLE_WINDOW], array_column($this->frames(), 0));
        self::assertFalse(Hilos::$sr->isTableWindowOwed('ak', HilosDaemonWorkersTable::TABLE));

        $context->publishTableMutations('publication', 'ak', HilosDaemonWorkersTable::TABLE, $broken);
        self::assertSame([SignalTypeConstants::TABLE_VIEWPORT_FROZEN], array_column($this->frames(), 0));
        $context->flushToSignalRouter();
        $context->publishTableMutations('publication', 'ak', HilosDaemonWorkersTable::TABLE,
            static fn (ViewportTable $table, TableQueryDTO $query): array => $table->pictureMutations(null, $query));
        $context->publishTableMutations('publication', 'ak', HilosDaemonWorkersTable::TABLE, $broken);
        $context->flushToSignalRouter();
        self::assertSame([], $this->frames());
        self::assertTrue(Hilos::$sr->isTableWindowOwed('ak', HilosDaemonWorkersTable::TABLE));

        $context->publishTableMutations('publication', 'ak', HilosDaemonWorkersTable::TABLE,
            static fn (ViewportTable $table, TableQueryDTO $query): array => $table->pictureMutations(null, $query));
        $context->flushToSignalRouter();
        self::assertSame([SignalTypeConstants::TABLE_WINDOW], array_column($this->frames(), 0));
        self::assertFalse(Hilos::$sr->isTableWindowOwed('ak', HilosDaemonWorkersTable::TABLE));
    }

    /**
     * @param bool $viewer Whether this is an admin view-mode reader
     * @return LivePublicationTestBrowser Real subscription and first page answer with the combined mismatch table
     */
    private function subscribeMismatch(bool $viewer = false): LivePublicationTestBrowser
    {
        $context = new LivePublicationTestBrowser($viewer ? null : 1);
        $context->viewer = $viewer;
        Hilos::$browser = $context;
        $context->bindHilosFacade(LivePublicationTestHilos::class);
        Hilos::$sr->subscribeToPage(AbstractHilosDaemonEnvMismatchPage::PAGE,
            new WebSocketPageSubscribeSignalDTO('ak', AbstractHilosDaemonEnvMismatchPage::PAGE));
        new LivePublicationMismatchPage(new DaemonCollectorAgent())->onSubscribe('ak', new PageRouteParams([]));
        return $context;
    }

    /**
     * @param string $page Node page whose real publisher is exercised
     * @param TableViewportSubscription $viewport Requested node window
     * @return LivePublicationTestBrowser Browser after delivering its initial window
     */
    private function subscribeNodeWindow(string $page, TableViewportSubscription $viewport): LivePublicationTestBrowser
    {
        $context = new LivePublicationTestBrowser();
        Hilos::$browser = $context;
        $context->bindHilosFacade(LivePublicationTestHilos::class);
        Hilos::$sr->subscribeToPage($page, new WebSocketPageSubscribeSignalDTO('ak', $page,
            [HilosPageRouteParams::HILOS_DAEMON_NODE_ID => 'n1']));
        Hilos::$sr->setTableViewport('ak', $viewport);
        self::assertTrue($context->sendTableWindow($page, 'ak', $viewport));
        self::assertSame([SignalTypeConstants::TABLE_WINDOW], array_column($this->frames(), 0));
        return $context;
    }

    /**
     * @param ?DaemonCronPicture $cron Reported schedule, or null before its first report
     * @return ClusterDaemonPicture One node's immutable schedule picture
     */
    private function cronPicture(?DaemonCronPicture $cron): ClusterDaemonPicture
    {
        return ClusterDaemonPicture::empty()->withNode(new ClusterDaemonNodeView('n1', true, new ClusterDaemonNodeSlot(
            'n1', new NodeDaemonPicture('n1', NodeRole::Master, 10, cron: $cron), 10,
        )));
    }

    /**
     * @param ClusterDaemonPicture $before Picture before the portion
     * @param ClusterDaemonPicture $current Picture delivered by the portion
     */
    private function publishCronPicture(ClusterDaemonPicture $before, ClusterDaemonPicture $current): void
    {
        ClusterDaemonPictureMirror::applyPortion(DaemonClusterPicturePortionSignalData::fromArray(
            new DaemonClusterPicturePortionSignalData(false, $current->nodes())->toArray(),
        ));
        LivePublicationCronPage::settleNodeData();
        AbstractHilosDaemonCronPage::onPictureChanged($before);
    }

    /**
     * @param ?int $userId Settled viewer identity
     * @param string $tableKey Mirror table to open
     * @return LivePublicationTestBrowser Browser with one real held worker window
     */
    private function subscribe(?int $userId = 1, string $tableKey = HilosDaemonWorkersTable::TABLE): LivePublicationTestBrowser
    {
        $context = new LivePublicationTestBrowser($userId);
        $context->viewer = $userId === null;
        Hilos::$browser = $context;
        $context->bindHilosFacade(LivePublicationTestHilos::class);
        Hilos::$sr->subscribeToPage('publication', new WebSocketPageSubscribeSignalDTO('ak', 'publication'));
        $viewport = new TableViewportSubscription($tableKey, filter: in_array($tableKey, [
            HilosDaemonPlacementTable::TABLE, HilosDaemonEnvMismatchTable::TABLE,
        ], true) ? [] : ['node' => 'n1']);
        Hilos::$sr->setTableViewport('ak', $viewport);
        self::assertTrue($context->sendTableWindow('publication', 'ak', $viewport));
        $this->frames();
        $context->viewer = false;
        return $context;
    }

    /** @return list<array{string, object}> Addressed names and payloads */
    private function frames(): array
    {
        $frames = [];
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertSame('ak', $frame->data->targetAcceptKey);
            $frames[] = [$frame->signalName->getName(), $frame->data->data];
        }
        return $frames;
    }

    public function testTypedDiffKeepsUpdatesDistinctFromRemovalsAndIgnoresOrder(): void
    {
        $before = [new GenericTableRow(['id' => 'kept', 'value' => 1]), new GenericTableRow(['id' => 'gone'])];
        $after = [new GenericTableRow(['id' => 'new']), new GenericTableRow(['id' => 'kept', 'value' => 2])];
        $mutations = DaemonPictureRowChanges::between($before, $after);
        self::assertSame([
            ['create', 'new'], ['update', 'kept'], ['delete', 'gone'],
        ], array_map(static fn ($mutation): array => [$mutation->type->value, $mutation->rowKey], $mutations));
        self::assertSame($after[1], $mutations[1]->row);
        self::assertNull($mutations[2]->row);
        self::assertSame([], DaemonPictureRowChanges::between($after, array_reverse($after)));
    }

    public function testWorkerDiffReadsWholeSubjectEvenWhenWindowIsOneRow(): void
    {
        $before = $this->workers('n1', [1 => 100, 2 => 200]);
        $current = $this->workers('n1', [1 => 101, 3 => 300])->withNode(
            $this->workers('n2', [7 => 700])->nodes()[0],
        );
        ClusterDaemonPictureMirror::applyPortion(new DaemonClusterPicturePortionSignalData(true, $current->nodes()));
        $table = new HilosDaemonWorkersTable();
        $query = new TableQueryDTO(limit: 1, filter: [HilosDaemonWorkersTable::FILTER_NODE => 'n1'], pageIndex: 1);
        $mutations = $table->pictureMutations($before, $query);
        self::assertSame([
            [TableMutationType::Update, 'regular:1'],
            [TableMutationType::Create, 'regular:3'],
            [TableMutationType::Delete, 'regular:2'],
        ], array_map(static fn ($mutation): array => [$mutation->type, $mutation->rowKey], $mutations));
        self::assertSame(101, $mutations[0]->row->toArray()['memoryBytes']);
        self::assertSame([], $table->pictureMutations($current, $query));
        self::assertCount(1, $table->pictureMutations($before, new TableQueryDTO(filter: ['node' => 'n2'])));
        self::assertSame([], $table->pictureMutations($before, new TableQueryDTO(filter: ['node' => 'missing'])));
    }

    /**
     * @param string $nodeId Subject node
     * @param array<int, int> $workers Worker memory by index
     * @return ClusterDaemonPicture Immutable subject picture
     */
    private function workers(string $nodeId, array $workers): ClusterDaemonPicture
    {
        $rows = [];
        foreach ($workers as $index => $memory) {
            $rows[] = new DaemonWorkerPicture($index, WorkerConstants::TYPE_REGULAR, 123, $memory, []);
        }
        $picture = new NodeDaemonPicture($nodeId, NodeRole::Master, 10, new DaemonProcessRoster($rows, [], 0));

        return ClusterDaemonPicture::empty()->withNode(new ClusterDaemonNodeView(
            $nodeId, true, new ClusterDaemonNodeSlot($nodeId, $picture, 10),
        ));
    }
}

/** Real mirror table, without a synthetic DB/RT source. */
final class LivePublicationTestTables extends TableContext
{
    public function configure(): void
    {
        $this->register(HilosDaemonWorkersTable::TABLE, new HilosDaemonWorkersTable());
        $this->register(HilosDaemonPlacementTable::TABLE, new HilosDaemonPlacementTable());
        $this->register(HilosDaemonAgentsTable::TABLE, new HilosDaemonAgentsTable());
        $this->register(HilosDaemonCronTable::TABLE, new HilosDaemonCronTable());
        $this->register(HilosDaemonHttpRoutesTable::TABLE, new HilosDaemonHttpRoutesTable());
        $this->register(HilosDaemonEnvMismatchTable::TABLE, new HilosDaemonEnvMismatchTable());
    }
}

final class LivePublicationTestBrowser extends IdentityTestBrowser
{
    public bool $authenticated = false;
    public bool $viewer = false;
    public bool $broken = false;
    public bool $readClusterSource = false;

    public function __construct(?int $userId = 1)
    {
        parent::__construct($userId, true);
    }

    public function isAdminViewModeViewer(string $pageClass, string $acceptKey): bool
    {
        return $this->viewer;
    }

    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        if ($this->broken) {
            throw new PageInternalErrorException('Broken page declaration');
        }
        return BrowserPageConfig::fromArray([
            BrowserConfigKey::SIGNAL => 'publication',
            BrowserConfigKey::GUARDS => $this->authenticated ? [[BrowserGuardKey::TYPE => BrowserGuardType::AUTHENTICATED]] : [],
        ]);
    }

    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        if ($page === AbstractHilosDaemonEnvMismatchPage::PAGE) {
            return BrowserPageBindings::fromArray([
                HilosDaemonEnvMismatchTable::TABLE => [],
            ]);
        }
        if ($this->readClusterSource) {
            return BrowserPageBindings::fromArray([HilosClusterNode::RT_COLLECTION => [], HilosDaemonWorkersTable::TABLE => []]);
        }
        return BrowserPageBindings::fromArray([
            HilosDaemonWorkersTable::TABLE => [], HilosDaemonPlacementTable::TABLE => [], HilosDaemonAgentsTable::TABLE => [],
            HilosDaemonCronTable::TABLE => [], HilosDaemonHttpRoutesTable::TABLE => [],
            HilosDaemonEnvMismatchTable::TABLE => [],
        ]);
    }

    protected function resolveBrowserOnlyConfig(string $browserKey): ?BrowserSourceConfig
    {
        return $this->readClusterSource && $browserKey === HilosClusterNode::RT_COLLECTION
            ? BrowserSourceConfig::fromArray([BrowserTableConfigKey::ROWS => [[
                BrowserFieldKey::SOURCE => [BrowserSourceKey::TYPE => BrowserSourceType::RT, BrowserSourceKey::KEY => $browserKey],
            ]]]) : null;
    }

}

final class LivePublicationTestResender implements PageResender
{
    public int $attempts = 0;
    public PageResendOutcome $outcome = PageResendOutcome::Answered;
    public bool $rebind = false;

    public function resendPage(string $page, string $acceptKey, array $params): PageResendOutcome
    {
        ++$this->attempts;
        if ($this->rebind) {
            Hilos::$sr->bindPageAgent($acceptKey, $page, 'publication-owner', null);
        }
        return $this->outcome;
    }

    public function refuseDeletedResource(string $page, string $acceptKey): PageResendOutcome
    {
        return PageResendOutcome::Unserved;
    }
}

abstract class LivePublicationTestHilos extends Hilos
{
    public const array AGENTS = ['x' => []];
    public const array PAGES = [
        'publication' => LivePublicationTestPage::class,
        AbstractHilosDaemonEnvMismatchPage::PAGE => LivePublicationMismatchPage::class,
        AbstractHilosDaemonCronPage::PAGE => LivePublicationCronPage::class,
        AbstractHilosDaemonWorkersPage::PAGE => LivePublicationWorkersPage::class,
    ];
}

final class LivePublicationTestPage extends AbstractPage
{
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::ADMIN;
    public const array READS_RT = [HilosClusterNode::RT_COLLECTION];
}

final class LivePublicationMismatchPage extends AbstractHilosDaemonEnvMismatchPage
{
}

final class LivePublicationCronPage extends AbstractHilosDaemonCronPage
{
    /** Settles data-only page publication so the test observes the window publisher alone. */
    public static function settleNodeData(): void
    {
        static::nodePagePayload('n1');
    }
}

final class LivePublicationWorkersPage extends AbstractHilosDaemonWorkersPage
{
    /** Settles data-only page publication so the test observes the window publisher alone. */
    public static function settleNodeData(): void
    {
        static::nodePagePayload('n1');
    }
}
