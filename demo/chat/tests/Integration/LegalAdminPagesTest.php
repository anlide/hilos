<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosLegalAgent;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Legal\LegalAcceptancesPage;
use Demo\Chat\Pages\Hilos\Legal\LegalDocumentPage;
use Demo\Chat\Pages\Hilos\Legal\LegalPage;
use Demo\Chat\Pages\Hilos\Legal\LegalRevisionPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\Database;
use Hilos\Hilos as FrameworkHilos;
use Hilos\Legal\Exception\UnknownRevisionException;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Pages\Legal\DTO\HilosLegalAcceptanceFiltersSignalData;
use Hilos\Pages\Legal\LegalAdminAudience;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use ReflectionMethod;

/** Real demo bindings answer read-only legal pages and supply options beyond the first SQL window. */
final class LegalAdminPagesTest extends IntegrationTestCase
{
    private const string ACCEPT_KEY = 'legal-admin-viewer';
    private const string TEST_AGENT = 'legal-admin-test';

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
        LegalAdminAudience::reset();
        Database::sqlRun('DELETE FROM hilos_legal_acceptance');
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT);
        Hilos::$rt->connections->actions->clear();
        $user = Hilos::$db->users->actions->createWithName('Legal administrator');
        $user->actions->setAdmin(true);
        $this->userId = (int) $user->id;
        $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', $this->name()), 0, 32));
        $session->actions->bindUser($this->userId);
        Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, $this->userId, $session->token, (int) $session->id);
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        Hilos::initBrowser();
        LegalAdminAudience::reset();
        Database::sqlRun('DELETE FROM hilos_legal_acceptance');
        Hilos::$rt->connections->actions->clear();
        RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT);
        Hilos::$sr = null;
        parent::tearDown();
    }

    public function testRootDocumentAndRevisionResponsesUseTheDemoCatalogWithoutWritingAcceptances(): void
    {
        $this->record($this->userId, '2026-09-17');
        $root = $this->response($this->subscribe(LegalPage::class));
        self::assertNull($root->payload->data['legalCatalogRefusal']);
        $documents = Hilos::$table->hilosLegalDocuments->getFullSnapshot();
        self::assertSame(['terms', 'privacy'], array_column($documents->rows, 'rowKey'));
        self::assertSame(1, $documents->rows[0]->covered);
        self::assertCount(4, Hilos::$table->hilosLegalChecks->getFullSnapshot()->rows);
        $document = $this->response($this->subscribe(LegalDocumentPage::class, ['documentKey' => 'terms']));
        self::assertSame(1, $document->payload->data['legalDocument']['set']['version']);
        self::assertCount(3, $document->payload->data['legalDocument']['deviations']);
        $revision = $this->response($this->subscribe(LegalRevisionPage::class, ['documentKey' => 'terms', 'revisionId' => '2026-09-27']));
        self::assertCount(6, $revision->payload->data['legalRevision']['clauses']);
        self::assertCount(1, $revision->payload->data['legalRevision']['changes']);
        $rows = Hilos::$table->hilosLegalRevisions->getPage(new TableQueryDTO(filter: ['document' => 'terms']))->rows;
        self::assertSame([0, 1], array_column($rows, 'heldCount'));
        self::assertSame(1, (int) Database::sql('SELECT COUNT(*) AS total FROM hilos_legal_acceptance')->firstRow()['total']);
    }

    public function testAcceptanceVocabularyPrecedesTheResponseAndIncludesAnOffWindowRevision(): void
    {
        for ($index = 0; $index < 26; $index++) {
            $user = Hilos::$db->users->actions->createWithName('Legal acceptor ' . $index);
            $this->record((int) $user->id, '2026-09-27');
        }
        $this->record($this->userId, 'removed', '2026-01-01 00:00:00');
        $frames = $this->subscribe(LegalAcceptancesPage::class);
        $filtersAt = array_search(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LEGAL_ACCEPTANCES, array_column($frames, 'name'), true);
        $responseAt = array_search(SignalTypeConstants::PAGE_RESPONSE, array_column($frames, 'name'), true);
        self::assertIsInt($filtersAt);
        self::assertIsInt($responseAt);
        self::assertLessThan($responseAt, $filtersAt);
        self::assertInstanceOf(HilosLegalAcceptanceFiltersSignalData::class, $frames[$filtersAt]['data']);
        self::assertSame(['2026-09-27', 'removed'], array_column($frames[$filtersAt]['data']->documents[0]['revisions'], 'revisionId'));
        self::assertFalse($frames[$filtersAt]['data']->documents[0]['revisions'][1]['declared']);
        $snapshot = Hilos::$table->hilosLegalAcceptances->getPage(new TableQueryDTO(limit: 25));
        self::assertSame(27, $snapshot->totalCount);
        self::assertCount(25, $snapshot->rows);
        self::assertSame(['2026-09-27'], array_values(array_unique(array_column($snapshot->rows, 'revisionId'))));
        self::assertSame(1, Hilos::$table->hilosLegalAcceptances->getPage(new TableQueryDTO(search: 'administrator', limit: 25))->totalCount);
        self::assertSame(27, (int) Database::sql('SELECT COUNT(*) AS total FROM hilos_legal_acceptance')->firstRow()['total']);
    }

    public function testAnUndeclaredRevisionWithRecordsStillOpens(): void
    {
        $this->record($this->userId, 'removed');
        $revision = $this->response($this->subscribe(LegalRevisionPage::class, ['documentKey' => 'terms', 'revisionId' => 'removed']));
        self::assertFalse($revision->payload->data['legalRevision']['declared']);
        self::assertNull($revision->payload->data['legalRevision']['clauses']);
    }

    public function testRevokedAdministratorReceivesNoLaterFilterVocabulary(): void
    {
        $this->subscribe(LegalAcceptancesPage::class);
        foreach ([true, false] as $admin) {
            Hilos::$db->users[$this->userId]->actions->setAdmin($admin);
            $this->record($this->userId, $admin ? 'before-revocation' : 'after-revocation');
            LegalAdminAudience::markStale();
            while (Hilos::$sr->getNextQueuedSignal() !== null) {
            }
            LegalAdminAudience::onAgentTick(new DemoHilosLegalAgent());
            $filters = 0;
            while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
                if ($signal->signalName->getName() === HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LEGAL_ACCEPTANCES) {
                    $filters++;
                }
            }
            self::assertSame($admin ? 1 : 0, $filters);
        }
    }

    public function testUnknownDocumentRefusesTheSubscription(): void
    {
        $this->expectException(PageResourceNotFoundException::class);
        $this->expectExceptionMessage('No such legal document');
        $this->subscribe(LegalDocumentPage::class, ['documentKey' => 'missing']);
    }

    public function testUnknownRevisionRefusesTheSubscription(): void
    {
        $this->expectException(PageResourceNotFoundException::class);
        $this->expectExceptionMessage('No such legal revision');
        $this->subscribe(LegalRevisionPage::class, ['documentKey' => 'terms', 'revisionId' => 'missing']);
    }

    public function testCatalogRefusalReachesEachDeclarationPageWhileRecordsRemainReadable(): void
    {
        $this->record($this->userId, 'removed');
        BrokenLegalAdminHilos::initBrowser();
        foreach ([LegalPage::class, LegalDocumentPage::class, LegalRevisionPage::class] as $page) {
            $payload = new ReflectionMethod($page, 'buildPagePayload')->invoke(
                new $page(new DemoHilosLegalAgent()), self::ACCEPT_KEY,
                new PageRouteParams(['documentKey' => 'terms', 'revisionId' => 'removed']),
            );
            self::assertInstanceOf(PagePayload::class, $payload);
            self::assertSame('Broken admin catalog', $payload->data['legalCatalogRefusal']);
        }
        self::assertNull(Hilos::$table->hilosLegalAcceptances->getPage(new TableQueryDTO(limit: 25))->rows[0]->declared);
    }

    /**
     * @param int $userId Person giving the acceptance
     * @param string $revisionId Exact recorded revision
     * @param string $acceptedAt SQL acceptance timestamp
     */
    private function record(int $userId, string $revisionId, string $acceptedAt = '2026-09-27 12:00:00'): void
    {
        Database::sqlRun(
            'INSERT INTO hilos_legal_acceptance (user_id, document, revision_id, accepted_at) VALUES (?, ?, ?, ?)',
            [$userId, 'terms', $revisionId, $acceptedAt],
        );
    }

    /**
     * @param class-string<AbstractPage> $pageClass Page being opened
     * @param array<string, string> $params Route parameters
     * @return list<array{name: string, data: object}> Queued browser frames in delivery order
     */
    private function subscribe(string $pageClass, array $params = []): array
    {
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        Hilos::$sr->subscribeToPage($pageClass::PAGE, new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, $pageClass::PAGE, $params));
        ExecutionContext::run(new ExecutionFrame(acceptKey: self::ACCEPT_KEY), static function () use ($pageClass, $params): void {
            new $pageClass(new DemoHilosLegalAgent())->onSubscribe(self::ACCEPT_KEY, new PageRouteParams($params));
        });
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof WebSocketSignalData) {
                $frames[] = ['name' => $signal->signalName->getName(), 'data' => $signal->data->data];
            }
        }
        return $frames;
    }

    /**
     * @param list<array{name: string, data: object}> $frames Frames from one subscription
     * @return PageResponseSignalData The declaration page's single data response, alongside the framework's browser snapshot
     */
    private function response(array $frames): PageResponseSignalData
    {
        $responses = array_values(array_filter($frames, static fn (array $frame): bool =>
            $frame['name'] === SignalTypeConstants::PAGE_RESPONSE
            && array_key_exists('legalCatalogRefusal', $frame['data']->payload->data)));
        self::assertCount(1, $responses);
        self::assertInstanceOf(PageResponseSignalData::class, $responses[0]['data']);
        return $responses[0]['data'];
    }
}

/** Refusing catalog bound only for one integration case. */
final class BrokenLegalAdminCatalog implements LegalCatalogProviderInterface
{
    /**
     * @return array Never returns
     * @throws UnknownRevisionException Always refuses the fixture catalog
     */
    public static function revisions(): array
    {
        throw new UnknownRevisionException('Broken admin catalog');
    }
}

/** Keeps the refusal isolated from the demo's normal catalog. */
abstract class BrokenLegalAdminHilos extends FrameworkHilos
{
    protected const ?string LEGAL_CATALOG = BrokenLegalAdminCatalog::class;
}
