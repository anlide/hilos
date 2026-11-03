<?php

declare(strict_types=1);

namespace Demo\Tasks\Tests\Integration;

use Demo\Tasks\Agents\Hilos\DemoHilosLegalAgent;
use Demo\Tasks\Core\Router\TasksSignalRouter;
use Demo\Tasks\Database\Pages\PageCatalog;
use Demo\Tasks\Hilos;
use Demo\Tasks\Pages\Hilos\Legal\LegalRevisionPage;
use Demo\Tasks\Pages\Hilos\Legal\LegalRevisionTextPage;
use Demo\Tasks\Runtime\View\Context\TasksRtContext;
use Demo\Tasks\Tables\TasksTableContext;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\Exception\PageSubscriptionException;
use Hilos\Core\Page\PageAccessGate;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\Database;
use Hilos\Hilos as FrameworkHilos;
use Hilos\Legal\Exception\UnknownRevisionException;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalCatalogResolver;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalWire;
use Hilos\Pages\Legal\LegalAdminAudience;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;

/** Real Tasks bindings deliver Revision Overview and Text through the legal agent's page path. */
final class LegalRevisionPagesTest extends IntegrationTestCase
{
    private const string TEST_AGENT = 'test-agent';
    private const string ADMIN_KEY = 'revision-admin';
    private const string VIEWER_KEY = 'revision-viewer';
    private const string ANONYMOUS_KEY = 'revision-anonymous';
    private const string ADMIN_NAME = 'Revision administrator';
    private const string VIEWER_NAME = 'Revision private viewer';
    private const string SUBJECT_NAME = 'Revision historical holder';
    private const string HISTORICAL_REVISION = '2026-09-17';
    private const string CURRENT_REVISION = '2026-10-09';
    private const array PARAMS = ['documentKey' => 'privacy', 'revisionId' => self::HISTORICAL_REVISION];

    private ?SignalRouter $previousRouter = null;
    private bool $previousViewMode = false;
    /** @var list<int> Fixture people removed after their dependent acceptance rows */
    private array $userIds = [];

    /** Mounts Tasks, two account connections, an anonymous viewer and exact acceptance fixtures. */
    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
        LegalAdminAudience::reset();
        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new TasksSignalRouter();
        RtTruthSourceRegistry::register(TasksRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT);
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        $this->previousViewMode = Hilos::$rt->hilosAdminViewModeRuntime->enabled;
        Hilos::$rt->hilosAdminViewModeRuntime->actions->set(true);
        $admin = Hilos::$db->users->actions->createWithName(self::ADMIN_NAME);
        $this->userIds[] = (int) $admin->id;
        $admin->actions->setAdmin(true);
        $viewer = Hilos::$db->users->actions->createWithName(self::VIEWER_NAME);
        $this->userIds[] = (int) $viewer->id;
        $subject = Hilos::$db->users->actions->createWithName(self::SUBJECT_NAME);
        $this->userIds[] = (int) $subject->id;
        Hilos::$rt->connections->actions->register(self::ADMIN_KEY, (int) $admin->id);
        Hilos::$rt->connections->actions->register(self::VIEWER_KEY, (int) $viewer->id);
        Hilos::$rt->connections->actions->register(self::ANONYMOUS_KEY, null);
        Hilos::$db->legalAcceptances->actions->accept((int) $subject->id, LegalDocument::PRIVACY, self::HISTORICAL_REVISION);
        Hilos::$db->legalAcceptances->actions->accept((int) $viewer->id, LegalDocument::PRIVACY, self::CURRENT_REVISION);
    }

    /** Restores facade bindings and the node mode, then removes only this case's fixture rows. */
    protected function tearDown(): void
    {
        try {
            Hilos::initBrowser();
            LegalAdminAudience::reset();
            foreach ([self::ADMIN_KEY, self::VIEWER_KEY, self::ANONYMOUS_KEY] as $acceptKey) {
                Hilos::$sr?->unsubscribeFromAll($acceptKey);
                Hilos::$rt->connections[$acceptKey]?->actions->unregister();
            }
            foreach ($this->userIds as $userId) {
                Hilos::$db->legalAcceptances->actions->deleteForUser($userId);
                Hilos::$db->users[$userId]->actions->delete();
            }
        } finally {
            Hilos::$rt->hilosAdminViewModeRuntime->actions->set($this->previousViewMode);
            RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
            Hilos::$sr = $this->previousRouter;
            Hilos::initBrowser();
            parent::tearDown();
        }
    }

    /** Historical Privacy, its identity and exact count all arrive in each page's first response. */
    public function testHistoricalPrivacyIsCompleteInBothFirstResponses(): void
    {
        $overview = $this->subscribe(LegalRevisionPage::class);
        $this->assertIdentity($overview);
        self::assertNull($overview->payload->data['legalCatalogRefusal']);
        $revision = $overview->payload->data['legalRevision'];
        self::assertSame([
            'document' => 'privacy',
            'revisionId' => self::HISTORICAL_REVISION,
            'declared' => true,
            'revision' => [
                'revisionId' => self::HISTORICAL_REVISION,
                'publishedOn' => self::HISTORICAL_REVISION,
                'effectiveOn' => self::HISTORICAL_REVISION,
                'significance' => 'substantial',
                'setVersion' => 1,
                'deviationCount' => 0,
            ],
            'current' => false,
            'predecessorId' => null,
            'clauses' => LegalWire::clauses(LegalCatalogResolver::compose(LegalDocument::PRIVACY, self::HISTORICAL_REVISION)),
            'changes' => null,
        ], $revision);
        self::assertNotSame(
            LegalWire::clauses(LegalCatalogResolver::compose(LegalDocument::PRIVACY, self::CURRENT_REVISION)),
            $revision['clauses'],
        );
        self::assertSame([TasksTableContext::hilosLegalRevisions], array_keys($overview->payload->windows));
        self::assertSame(['document' => 'privacy', 'revision' => self::HISTORICAL_REVISION],
            Hilos::$sr->getTableViewport(self::ADMIN_KEY, TasksTableContext::hilosLegalRevisions)->filter);
        $rows = $overview->payload->windows[TasksTableContext::hilosLegalRevisions]['rows'];
        self::assertCount(1, $rows);
        self::assertSame(self::HISTORICAL_REVISION, $rows[0]['rowKey']);
        self::assertSame(1, $rows[0]['slots']['revision']['acceptedCount']);
        self::assertSame(1, $rows[0]['slots']['revision']['heldCount']);
        self::assertSame([], $overview->payload->refusedWindows);

        $text = $this->subscribe(LegalRevisionTextPage::class);
        $this->assertIdentity($text);
        self::assertNull($text->payload->data['legalCatalogRefusal']);
        self::assertSame($revision, $text->payload->data['legalRevision']);
        self::assertSame('Text', $text->payload->data['pageLabel']);
        self::assertSame('The assembled text of this revision.', $text->payload->data['pageLead']);
        self::assertSame([], $text->payload->windows);
        self::assertSame([], $text->payload->refusedWindows);
        self::assertArrayNotHasKey(PagePayload::windows, $text->payload->toArray());
        self::assertArrayNotHasKey(PagePayload::refusedWindows, $text->payload->toArray());
        self::assertNull(Hilos::$sr->getTableViewport(self::ADMIN_KEY, TasksTableContext::hilosLegalRevisions));
    }

    /** A recorded-only Overview retains its count; neither recorded-only nor unknown Text is a text substitute. */
    public function testRecordedOnlyOverviewAndMissingTextHaveDistinctOutcomes(): void
    {
        // The public accept() API deliberately refuses undeclared revisions; this fixture models a historical record.
        Database::sqlRun(
            'INSERT INTO hilos_legal_acceptance (user_id, document, revision_id, accepted_at) VALUES (?, ?, ?, ?)',
            [$this->userIds[0], 'privacy', 'removed', '2026-09-17 12:00:00'],
        );
        LegalAdminAudience::reset();
        $overview = $this->subscribe(LegalRevisionPage::class, ['documentKey' => 'privacy', 'revisionId' => 'removed']);
        $this->assertIdentity($overview, declared: false);
        self::assertNull($overview->payload->data['legalCatalogRefusal']);
        self::assertSame([
            'document' => 'privacy', 'revisionId' => 'removed', 'declared' => false,
            'revision' => null, 'current' => null, 'predecessorId' => null, 'clauses' => null, 'changes' => null,
        ], $overview->payload->data['legalRevision']);
        $rows = $overview->payload->windows[TasksTableContext::hilosLegalRevisions]['rows'];
        self::assertCount(1, $rows);
        self::assertSame('removed', $rows[0]['rowKey']);
        self::assertFalse($rows[0]['slots']['revision']['declared']);
        self::assertSame(1, $rows[0]['slots']['revision']['acceptedCount']);
        foreach (['removed', 'unknown'] as $revisionId) {
            try {
                $this->subscribe(LegalRevisionTextPage::class, ['documentKey' => 'privacy', 'revisionId' => $revisionId]);
                self::fail('Text must refuse a revision absent from the declaration');
            } catch (PageResourceNotFoundException $failure) {
                self::assertSame(404, $failure->httpCode);
                self::assertSame('not_found', $failure->errorCode);
                self::assertSame('No such legal revision', $failure->getMessage());
            }
        }
    }

    /** Signed-in and anonymous viewers see catalog text, identity and aggregates without personal fields. */
    public function testViewersReceiveClausesIdentityAndOnlyAggregateCounts(): void
    {
        foreach ([self::VIEWER_KEY, self::ANONYMOUS_KEY] as $acceptKey) {
            foreach ([LegalRevisionPage::class, LegalRevisionTextPage::class] as $page) {
                $response = $this->subscribe($page, acceptKey: $acceptKey);
                $this->assertIdentity($response);
                self::assertNull($response->payload->data['legalCatalogRefusal']);
                self::assertSame('privacy', $response->payload->data['legalRevision']['document']);
                self::assertSame(self::HISTORICAL_REVISION, $response->payload->data['legalRevision']['revisionId']);
                self::assertSame(
                    LegalWire::clauses(LegalCatalogResolver::compose(LegalDocument::PRIVACY, self::HISTORICAL_REVISION)),
                    $response->payload->data['legalRevision']['clauses'],
                );
                if ($page === LegalRevisionPage::class) {
                    $rows = $response->payload->windows[TasksTableContext::hilosLegalRevisions]['rows'];
                    self::assertCount(1, $rows);
                    $row = $rows[0]['slots']['revision'];
                    self::assertSame([
                        'rowKey', 'declared', 'revision', 'current', 'origin', 'heldCount', 'acceptedCount', 'publishedOn', 'effectiveOn',
                    ], array_keys($row));
                    self::assertSame(1, $row['acceptedCount']);
                    self::assertSame(1, $row['heldCount']);
                } else {
                    self::assertSame([], $response->payload->windows);
                }
                $wire = json_encode($response->toArray(), JSON_THROW_ON_ERROR);
                foreach ([self::ADMIN_NAME, self::VIEWER_NAME, self::SUBJECT_NAME, 'userId', 'email', 'acceptedAt'] as $personal) {
                    self::assertStringNotContainsString($personal, $wire);
                }
            }
        }
    }

    /** Turning the view mode off restores the ADMIN 401/403 gate on both subscriptions. */
    public function testBothPagesKeepTheAdminGateWithViewModeOff(): void
    {
        Hilos::$rt->hilosAdminViewModeRuntime->actions->set(false);
        foreach ([LegalRevisionPage::class, LegalRevisionTextPage::class] as $page) {
            foreach ([self::ANONYMOUS_KEY => 401, self::VIEWER_KEY => 403] as $acceptKey => $status) {
                try {
                    $this->subscribe($page, acceptKey: $acceptKey);
                    self::fail('An ADMIN subscription must refuse this connection');
                } catch (PageSubscriptionException $failure) {
                    self::assertSame($status, $failure->httpCode);
                    self::assertSame($status === 401 ? 'unauthorized' : 'forbidden', $failure->errorCode);
                }
            }
            $this->assertIdentity($this->subscribe($page));
        }
    }

    /** A faulty catalog replaces a warm text with a refusal, preserving identity and safe viewer words. */
    public function testFaultyCatalogAnswersWithSafeRefusalInsteadOf404OrOldText(): void
    {
        self::assertNotEmpty($this->subscribe(LegalRevisionTextPage::class)->payload->data['legalRevision']['clauses']);
        RefusedTasksRevisionHilos::initBrowser(Hilos::$browser);
        foreach ([LegalRevisionPage::class, LegalRevisionTextPage::class] as $page) {
            foreach ([self::ADMIN_KEY, self::VIEWER_KEY, self::ANONYMOUS_KEY] as $acceptKey) {
                $response = $this->subscribe($page, acceptKey: $acceptKey);
                $this->assertIdentity($response);
                self::assertNull($response->payload->data['legalRevision']);
                self::assertSame(
                    $acceptKey === self::ADMIN_KEY ? 'Refused Tasks revision catalog' : SignalConstants::ACTION_FAILED_REASON,
                    $response->payload->data['legalCatalogRefusal'],
                );
                if ($page === LegalRevisionTextPage::class) {
                    self::assertSame([], $response->payload->windows);
                    self::assertSame([], $response->payload->refusedWindows);
                }
            }
        }
    }

    /**
     * Exercises the registered project wrapper and collects its first response without a client viewport request.
     *
     * @param class-string<AbstractPage> $pageClass Registered Tasks page
     * @param array<string, string> $params Requested document and revision
     * @param string $acceptKey Subscribing connection
     * @return PageResponseSignalData The sole page response
     */
    private function subscribe(string $pageClass, array $params = self::PARAMS, string $acceptKey = self::ADMIN_KEY): PageResponseSignalData
    {
        $this->drainSignals();
        Hilos::$sr->unsubscribeFromAll($acceptKey);
        LegalAdminAudience::removeSubscriber($acceptKey);
        Hilos::$sr->subscribeToPage($pageClass::PAGE, new WebSocketPageSubscribeSignalDTO($acceptKey, $pageClass::PAGE, $params));
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), static function () use ($pageClass, $params, $acceptKey): void {
            PageAccessGate::assert($pageClass, $acceptKey);
            new $pageClass(new DemoHilosLegalAgent())->onSubscribe($acceptKey, new PageRouteParams($params));
        });
        $frames = [];
        $responses = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof WebSocketSignalData) {
                self::assertSame($acceptKey, $signal->data->targetAcceptKey);
                $frames[] = $signal->signalName->getName();
                if ($signal->data->data instanceof PageResponseSignalData) {
                    $responses[] = $signal->data->data;
                }
            }
        }
        self::assertCount(1, $responses);
        self::assertSame($pageClass::PAGE, $responses[0]->pageKey);
        self::assertNotContains(SignalTypeConstants::TABLE_WINDOW, $frames);
        if ($pageClass === LegalRevisionTextPage::class) {
            self::assertSame([SignalTypeConstants::PAGE_RESPONSE], $frames);
            self::assertNull(Hilos::$sr->getTableViewport($acceptKey, TasksTableContext::hilosLegalRevisions));
        }

        return $responses[0];
    }

    /**
     * @param PageResponseSignalData $response The answered Revision tab
     * @param bool $declared Whether the Text tab is applicable
     */
    private function assertIdentity(PageResponseSignalData $response, bool $declared = true): void
    {
        $data = $response->payload->data;
        self::assertSame([
            'page' => 'hilos_legal_revision', 'label' => 'Revision',
            'lead' => 'The exact text, its changes, and its acceptances.', 'icon' => 'bi-file-earmark-text',
        ], $data['pageHeadOwner']);
        self::assertSame([
            ['page' => 'hilos', 'label' => 'Hilos'],
            ['page' => 'hilos_legal', 'label' => 'Legal'],
            ['page' => 'hilos_legal_documents', 'label' => 'Documents'],
            ['page' => 'hilos_legal_document', 'label' => 'Document'],
            ['page' => 'hilos_legal_document_revisions', 'label' => 'Revisions'],
            ['page' => 'hilos_legal_revision', 'label' => 'Revision'],
        ], $data['pageBreadcrumb']);
        self::assertSame($declared ? [
            ['page' => 'hilos_legal_revision', 'label' => 'Overview'],
            ['page' => 'hilos_legal_revision_text', 'label' => 'Text'],
        ] : [
            ['page' => 'hilos_legal_revision', 'label' => 'Overview'],
        ], $data['pageTabs']);
        self::assertSame([], $data['pageChildren']);
    }
}

/** Broken declaration fixture for the ordinary Tasks admin and viewer subscription paths. */
final class RefusedTasksRevisionCatalog implements LegalCatalogProviderInterface
{
    /** @return array Never supplies declarations */
    public static function revisions(): array
    {
        throw new UnknownRevisionException('Refused Tasks revision catalog');
    }
}

/** Preserves Tasks topology and page-head adoption while replacing only the legal declaration source. */
abstract class RefusedTasksRevisionHilos extends FrameworkHilos
{
    public const array PAGES = Hilos::PAGES;
    public const array TABLES = Hilos::TABLES;
    public const array PAGE_TABLES = Hilos::PAGE_TABLES;

    protected const string PAGE_CATALOG = PageCatalog::class;
    protected const ?string LEGAL_CATALOG = RefusedTasksRevisionCatalog::class;
}
