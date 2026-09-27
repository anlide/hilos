<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\Legal;

use DateTimeImmutable;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Pages\Legal\AbstractHilosLegalAcceptancesPage;
use Hilos\Pages\Legal\DTO\HilosLegalAcceptanceFiltersSignalData;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\Legal\LegalAcceptanceChangeSubscriber;
use Hilos\Legal\LegalStandingResolver;
use Hilos\Legal\LegalTally;
use Hilos\Pages\Legal\LegalAdminAudience;
use Hilos\Tables\Legal\HilosLegalDocumentsTable;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\Exception\UnknownRevisionException;
use ReflectionProperty;

/** Histogram reuse, date folding and the source-change route to every subscribed window. */
final class LegalAdminAudienceTest extends LegalAdminTestCase
{
    public function testAllWindowsShareOneReadUntilAChangeInvalidatesIt(): void
    {
        $first = LegalAdminAudience::tallies();
        self::assertSame(3, $this->reads->reads);
        self::assertSame($first, LegalAdminAudience::tallies());
        self::assertSame(3, $this->reads->reads);
        LegalAdminAudience::markStale();
        LegalAdminAudience::markStale();
        $this->reads->held['current']++;
        self::assertSame(3, $this->reads->reads);
        self::assertSame(3, LegalAdminAudience::tallies()['terms']->covered);
        self::assertSame(6, $this->reads->reads);
    }

    public function testTheDateFoldsTheCachedHistogramsWithoutSql(): void
    {
        $yesterday = new DateTimeImmutable(LegalStandingResolver::today())->modify('-1 day')->format('Y-m-d');
        $before = LegalTally::of('terms', $this->reads->held, $this->reads->accepted, $yesterday);
        self::assertSame(3, $before->window);
        new ReflectionProperty(LegalAdminAudience::class, 'cache')->setValue(null, ['terms' => $before]);
        new ReflectionProperty(LegalAdminAudience::class, 'cacheDate')->setValue(null, $yesterday);
        self::assertSame(3, LegalAdminAudience::tallies()['terms']->lapsed);
        self::assertSame(0, LegalAdminAudience::tallies()['terms']->window);
        self::assertSame(0, $this->reads->reads);
    }

    public function testATickWithNoSubscribersDoesNotReadOrSend(): void
    {
        Hilos::$browser = $this->createMock(BrowserContext::class);
        Hilos::$browser->expects(self::never())->method('sendTableWindow');
        LegalAdminAudience::markStale();
        LegalAdminAudience::onAgentTick($this->createMock(PageAgentInterface::class));
        self::assertSame(0, $this->reads->reads);
    }

    public function testRemoteAndLocalChangesRefreshBothViewersOncePerTick(): void
    {
        $agent = $this->createMock(PageAgentInterface::class);
        Hilos::$browser = $this->createMock(BrowserContext::class);
        Hilos::$browser->expects(self::exactly(4))->method('sendTableWindow')->willReturn(true);
        foreach (['first-viewer', 'second-viewer'] as $acceptKey) {
            Hilos::$sr->setTableViewport($acceptKey, new TableViewportSubscription(HilosLegalDocumentsTable::TABLE));
            LegalAdminAudience::addSubscriber($acceptKey, HilosPageConstants::HILOS_LEGAL);
        }
        LegalAdminAudience::onAgentTick($agent);
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame(3, $this->reads->reads);
        $subscriber = new LegalAcceptanceChangeSubscriber();
        foreach ([SourceChangeProvenance::LocalWrite, SourceChangeProvenance::AppliedRemote] as $origin) {
            foreach ([TableMutationType::Create, TableMutationType::Delete, TableMutationType::Clear] as $mutation) {
                $subscriber->onSourceChange(new SourceChange(
                    SourceChange::KIND_DB,
                    HilosDbContext::legalAcceptances,
                    '42',
                    $mutation,
                ), $origin);
            }
        }
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame(6, $this->reads->reads);
        LegalAdminAudience::removeSubscriber('first-viewer');
        LegalAdminAudience::removeSubscriber('second-viewer');
        LegalAdminAudience::markStale();
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame(6, $this->reads->reads);
    }

    public function testDateRefreshReachesExistingViewersEvenIfAnotherReaderFoldedTheCache(): void
    {
        $agent = $this->createMock(PageAgentInterface::class);
        Hilos::$browser = $this->createMock(BrowserContext::class);
        Hilos::$browser->expects(self::exactly(2))->method('sendTableWindow')->willReturn(true);
        Hilos::$sr->setTableViewport('viewer', new TableViewportSubscription(HilosLegalDocumentsTable::TABLE));
        LegalAdminAudience::addSubscriber('viewer', HilosPageConstants::HILOS_LEGAL);
        LegalAdminAudience::onAgentTick($agent);
        $yesterday = new DateTimeImmutable(LegalStandingResolver::today())->modify('-1 day')->format('Y-m-d');
        new ReflectionProperty(LegalAdminAudience::class, 'cacheDate')->setValue(null, $yesterday);
        new ReflectionProperty(LegalAdminAudience::class, 'deliveredDate')->setValue(null, $yesterday);
        LegalAdminAudience::tallies();
        LegalAdminAudience::onAgentTick($agent);
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame(3, $this->reads->reads);
    }

    public function testUnrelatedSourcesDoNotInvalidateHistograms(): void
    {
        LegalAdminAudience::tallies();
        $subscriber = new LegalAcceptanceChangeSubscriber();
        foreach ([SourceChange::KIND_DB, SourceChange::KIND_RT] as $kind) {
            $subscriber->onSourceChange(new SourceChange(
                $kind,
                $kind === SourceChange::KIND_RT ? HilosDbContext::legalAcceptances : HilosDbContext::settings,
                '42',
                TableMutationType::Update,
            ), SourceChangeProvenance::AppliedRemote);
        }
        LegalAdminAudience::tallies();
        self::assertSame(3, $this->reads->reads);
    }
    public function testFiltersIncludeDeclaredDocumentsAndAllRecordedRevisionsInStableOrder(): void
    {
        $this->reads->recorded = ['retired' => ['a', 'z'], 'terms' => ['first', 'gone', 'current']];
        $filters = LegalAdminAudience::filters();
        self::assertSame([
            ['document' => 'terms', 'declared' => true, 'revisions' => [
                ['revisionId' => 'current', 'declared' => true],
                ['revisionId' => 'first', 'declared' => true],
                ['revisionId' => 'gone', 'declared' => false],
            ]],
            ['document' => 'retired', 'declared' => false, 'revisions' => [
                ['revisionId' => 'z', 'declared' => false],
                ['revisionId' => 'a', 'declared' => false],
            ]],
        ], $filters->documents);
        self::assertSame($filters, LegalAdminAudience::filters());
        self::assertSame(1, $this->reads->revisionReads);
        self::assertSame(0, $this->reads->reads);
        self::assertSame($filters->toArray(), HilosLegalAcceptanceFiltersSignalData::fromArray($filters->toArray())->toArray());
        $this->reads->recorded = [];
        LegalAdminAudience::markStale();
        self::assertSame([['document' => 'terms', 'declared' => true, 'revisions' => []]], LegalAdminAudience::filters()->documents);
    }

    public function testCatalogRefusalLeavesRecordedOptionsWithUnknownDeclarationStatus(): void
    {
        LegalFiltersBrokenHilos::initBrowser();
        $this->reads->recorded = ['terms' => ['first', 'gone'], 'retired' => ['old']];
        $data = LegalAdminAudience::filters();
        self::assertSame(['retired', 'terms'], array_column($data->documents, 'document'));
        self::assertSame([null, null], array_column($data->documents, 'declared'));
        self::assertSame([
            ['revisionId' => 'gone', 'declared' => null],
            ['revisionId' => 'first', 'declared' => null],
        ], $data->documents[1]['revisions']);
        self::assertSame($data->toArray(), HilosLegalAcceptanceFiltersSignalData::fromArray($data->toArray())->toArray());
    }

    public function testFiltersGoOnlyToAcceptanceViewersAndOnlyWhenTheVocabularyChanges(): void
    {
        $agent = $this->createMock(PageAgentInterface::class);
        LegalAdminAudience::addSubscriber('first', HilosPageConstants::HILOS_LEGAL_ACCEPTANCES);
        LegalAdminAudience::addSubscriber('second', HilosPageConstants::HILOS_LEGAL_ACCEPTANCES);
        LegalAdminAudience::addSubscriber('settings', HilosPageConstants::HILOS_LEGAL_SETTINGS);
        LegalAdminAudience::markStale();
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame(['first', 'second'], $this->filterTargets());
        self::assertSame(0, $this->reads->reads);
        LegalAdminAudience::markStale();
        LegalAdminAudience::markStale();
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame([], $this->filterTargets());
        self::assertSame(2, $this->reads->revisionReads);
        $this->reads->recorded['terms'][] = 'new-unknown';
        LegalAdminAudience::markStale();
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame(['first', 'second'], $this->filterTargets());
        self::assertSame(3, $this->reads->revisionReads);
        self::assertSame(0, $this->reads->reads);
        LegalAdminAudience::removeSubscriber('first');
        LegalAdminAudience::removeSubscriber('second');
        LegalAdminAudience::markStale();
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame([], $this->filterTargets());
        self::assertSame(3, $this->reads->revisionReads);
    }

    public function testDateChangeDoesNotReadOrSendFilters(): void
    {
        $agent = $this->createMock(PageAgentInterface::class);
        LegalAdminAudience::addSubscriber('viewer', HilosPageConstants::HILOS_LEGAL_ACCEPTANCES);
        LegalAdminAudience::markStale();
        LegalAdminAudience::onAgentTick($agent);
        $this->filterTargets();
        new ReflectionProperty(LegalAdminAudience::class, 'deliveredDate')->setValue(null, '2000-01-01');
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame([], $this->filterTargets());
        self::assertSame(1, $this->reads->revisionReads);
        self::assertSame(0, $this->reads->reads);
    }

    public function testNewSubscriptionDoesNotConsumeTheBroadcastOwedToExistingViewers(): void
    {
        $agent = $this->createMock(PageAgentInterface::class);
        $page = new class ($agent) extends AbstractHilosLegalAcceptancesPage {
            /** @param string $acceptKey Viewer to answer and register */
            public function subscribeForTest(string $acceptKey): void
            {
                $params = new PageRouteParams([]);
                $this->onSubscribeBeforeResponse($acceptKey, $params);
                $this->onSubscribeAfterResponse($acceptKey, $params);
            }
        };
        $page->subscribeForTest('first');
        self::assertSame(['first'], $this->filterTargets());
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame([], $this->filterTargets());
        LegalAdminAudience::markStale();
        LegalAdminAudience::onAgentTick($agent);
        $this->filterTargets();
        $this->reads->recorded['terms'][] = 'new-unknown';
        LegalAdminAudience::markStale();
        $page->subscribeForTest('second');
        self::assertSame(['second'], $this->filterTargets());
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame(['first', 'second'], $this->filterTargets());
        self::assertSame(3, $this->reads->revisionReads);
        $page->onUnsubscribe('first');
        $page->onUnsubscribe('second');
        LegalAdminAudience::markStale();
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame([], $this->filterTargets());
        self::assertSame(3, $this->reads->revisionReads);
    }

    public function testNumericHistoricalDocumentKeysRemainStringsOnTheWire(): void
    {
        $this->reads->recorded = ['123' => ['old']];
        $data = LegalAdminAudience::filters();
        self::assertSame('123', $data->documents[1]['document']);
        self::assertSame($data->toArray(), HilosLegalAcceptanceFiltersSignalData::fromArray($data->toArray())->toArray());
    }

    public function testEachBroadcastRechecksTheCurrentRightsAndBrowserGuards(): void
    {
        $admin = true;
        Hilos::$browser = $this->createMock(BrowserContext::class);
        Hilos::$browser->method('resolveActionUserId')->willReturn(1);
        Hilos::$browser->method('isAdmin')->willReturnCallback(static function () use (&$admin): bool { return $admin; });
        Hilos::$browser->method('assertSubscriptionAccess')->willReturnCallback(
            static function (string $page, string $acceptKey): void {
                if ($acceptKey === 'guard-refused') {
                    throw new PageForbiddenException();
                }
            },
        );
        LegalAdminAudience::addSubscriber('viewer', HilosPageConstants::HILOS_LEGAL_ACCEPTANCES);
        LegalAdminAudience::addSubscriber('guard-refused', HilosPageConstants::HILOS_LEGAL_ACCEPTANCES);
        $agent = $this->createMock(PageAgentInterface::class);
        LegalAdminAudience::markStale();
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame(['viewer'], $this->filterTargets());
        $admin = false;
        $this->reads->recorded['terms'][] = 'after-revocation';
        LegalAdminAudience::markStale();
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame([], $this->filterTargets());
        Hilos::$browser = null;
        $this->reads->recorded['terms'][] = 'without-identity';
        LegalAdminAudience::markStale();
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame([], $this->filterTargets());
    }

    /** @return list<string> Recipients of queued filter vocabulary frames */
    private function filterTargets(): array
    {
        $targets = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LEGAL_ACCEPTANCES, $signal->signalName->getName());
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertInstanceOf(HilosLegalAcceptanceFiltersSignalData::class, $signal->data->data);
            $targets[] = $signal->data->targetAcceptKey;
        }
        return $targets;
    }
}

/** Refuses independently of any catalog used by neighboring tests. */
final class LegalFiltersBrokenCatalog implements LegalCatalogProviderInterface
{
    /**
     * @return array Never returns
     * @throws UnknownRevisionException Always refuses this fixture
     */
    public static function revisions(): array
    {
        throw new UnknownRevisionException('Broken filter catalog');
    }
}

/** Binds the refusing filter catalog. */
abstract class LegalFiltersBrokenHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = LegalFiltersBrokenCatalog::class;
}
