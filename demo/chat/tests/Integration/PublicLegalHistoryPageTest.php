<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Browser\ChatBrowserContext;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Database;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\TermsHistoryPage;
use Demo\Chat\Pages\Hilos\PrivacyHistoryPage;
use Demo\Chat\Pages\Hilos\TermsPage;
use Demo\Chat\Pages\Hilos\PrivacyPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\Auth\Impersonation\Takeover;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAccessGate;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos as HilosFacade;
use Hilos\Legal\LegalAgreementsGroup;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Pages\Legal\DTO\LegalRevisionTextReplyDTO;
use Hilos\Pages\Legal\DTO\TermsRevisionTextActionDTO;
use Hilos\Pages\Legal\DTO\PrivacyRevisionTextActionDTO;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Testing\IntegrationTest;
use Hilos\Testing\RollbackIntegrationTestCase;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\AccountStandingResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

/** Fresh ORM and browser sources around the rollback scope, over the migrated chat schema. */
abstract class PublicLegalHistoryEnvironment extends RollbackIntegrationTestCase
{
    private const string TEST_AGENT = 'public-legal-history-test';

    private ?HilosDbContext $previousDb = null;
    private ?RtContext $previousRt = null;
    private ?BrowserContext $previousBrowser = null;
    private ?TableContext $previousTable = null;
    private ?SignalRouter $previousSr = null;
    private string $previousAppClass = Hilos::class;
    private ?int $executionToken = null;

    /** Initializes shared schema access and mounts isolated contexts before the test scope. */
    protected function prepareIntegrationEnvironment(): void
    {
        Database::initialize(initHilos: true);
        $this->previousDb = Hilos::$db;
        $this->previousRt = Hilos::$rt;
        $this->previousBrowser = Hilos::$browser;
        $this->previousTable = Hilos::$table;
        $this->previousSr = Hilos::$sr;
        $this->previousAppClass = Hilos::appClass();
        Hilos::$db = new ChatDbContext();
        Hilos::$db->configure();
        Hilos::$rt = new ChatRtContext();
        Hilos::$rt->configure();
        Hilos::$rt->bindStateCollectionNames();
        Hilos::$browser = new ChatBrowserContext();
        Hilos::$browser->bindHilosFacade(Hilos::class);
        Hilos::$table = new ChatTableContext();
        Hilos::$table->configure();
        Hilos::$sr = new SignalRouter();
        foreach ([HilosDbContext::users, HilosDbContext::legalAcceptances, HilosDbContext::sessions] as $collection) {
            TruthSourceRegistry::register($collection, TruthSourceKeys::all(), self::TEST_AGENT);
        }
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT);
        $this->executionToken = ExecutionContext::push(new ExecutionFrame(agentId: self::TEST_AGENT));
    }

    /** Restores the surrounding contexts after the harness rolls back persisted and runtime fixtures. */
    protected function releaseIntegrationEnvironment(): void
    {
        Hilos::$db = $this->previousDb;
        Hilos::$rt = $this->previousRt;
        Hilos::$browser = $this->previousBrowser;
        Hilos::$table = $this->previousTable;
        Hilos::$sr = $this->previousSr;
        new ReflectionProperty(HilosFacade::class, 'appClass')->setValue(null, $this->previousAppClass);
        TruthSourceRegistry::unregisterAgent(self::TEST_AGENT);
        RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT);
        if ($this->executionToken !== null) {
            ExecutionContext::pop($this->executionToken);
            $this->executionToken = null;
        }
    }
}

/** Public history uses one subscription answer and retains the original public text readers. */
#[IntegrationTest]
final class PublicLegalHistoryPageTest extends PublicLegalHistoryEnvironment
{
    private const string ACCEPT_KEY = 'public-history-accept';

    /**
     * @param string $page New page key
     * @param string $section Existing legal section
     * @param string $table Public history window key
     * @param list<string> $revisions Expected newest-first revisions
     */
    #[DataProvider('documents')]
    public function testAGuestGetsTextAndPublicWindowInOneAnswer(string $page, string $section, string $table, array $revisions): void
    {
        Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, null);
        $payload = $this->subscribe($page);
        self::assertArrayNotHasKey('legalAgreements', $payload['data']);
        self::assertSame(array_reverse($revisions), array_column($payload['data'][$section]['revisions'], 'revisionId'));
        self::assertNotEmpty($payload['data'][$section]['clauses']);
        self::assertNull($payload['data'][$section]['changes']);
        self::assertSame([$table], array_keys($payload['windows']));
        self::assertSame(count($revisions), $payload['windows'][$table]['totalCount']);
        self::assertTrue($payload['windows'][$table]['totalExact']);
        self::assertSame(25, $payload['windows'][$table]['limit']);
        self::assertSame($revisions, array_column($payload['windows'][$table]['rows'], 'rowKey'));
        foreach ($payload['windows'][$table]['rows'] as $row) {
            self::assertSame(['rowKey', 'slots'], array_keys($row));
            self::assertSame(['revision'], array_keys($row['slots']));
            self::assertSame([
                'rowKey', 'revisionId', 'publishedOn', 'effectiveOn', 'significance', 'setVersion',
                'deviationCount', 'origin', 'previousSetVersion',
            ], array_keys($row['slots']['revision']));
            self::assertSame($row['rowKey'], $row['slots']['revision']['revisionId']);
        }
        self::assertNull(Hilos::$sr->groupSubscriptionName(self::ACCEPT_KEY, LegalAgreementsGroup::NAME));
    }

    /**
     * @param string $page History address
     * @param string $section Legal text section
     * @param string $table Window key
     * @param list<string> $revisions Published revisions
     */
    #[DataProvider('documents')]
    public function testASignedInReaderGetsOnlyTheirAcceptances(string $page, string $section, string $table, array $revisions): void
    {
        $reader = $this->reader('Public history reader');
        $document = $page === TermsHistoryPage::PAGE ? LegalDocument::TERMS : LegalDocument::PRIVACY;
        Hilos::$db->legalAcceptances->actions->accept($reader, $document, $revisions[0]);
        $other = (int)Hilos::$db->users->actions->createWithName('Other history reader')->id;
        Hilos::$db->legalAcceptances->actions->accept($other, $document, $revisions[array_key_last($revisions)]);
        $payload = $this->subscribe($page);
        $state = array_values(array_filter($payload['data']['legalAgreements']['documents'],
            static fn (array $state): bool => $state['document'] === $document->value))[0];
        self::assertSame([$revisions[0]], array_column($state['accepted'], 'revisionId'));
        self::assertSame('covered', $state['standing']);
        self::assertNull($payload['data'][$section]['changes']);
        self::assertCount(count($revisions), $payload['windows'][$table]['rows']);
        self::assertSame(LegalAgreementsGroup::forUser($reader), Hilos::$sr->groupSubscriptionName(
            self::ACCEPT_KEY, LegalAgreementsGroup::forUser($reader),
        ));
    }

    /**
     * @param string $page History address
     * @param string $section Legal text section
     * @param string $table Window key
     * @param list<string> $revisions Published revisions
     */
    #[DataProvider('documents')]
    public function testAFrozenRepresentedReaderStillReadsTheirHistory(string $page, string $section, string $table, array $revisions): void
    {
        $reader = $this->reader('Represented frozen reader');
        $document = $page === TermsHistoryPage::PAGE ? LegalDocument::TERMS : LegalDocument::PRIVACY;
        $old = $revisions[array_key_last($revisions)];
        Hilos::$db->legalAcceptances->actions->accept($reader, $document, $old);
        self::assertTrue(AccountStandingResolver::isFrozen($reader));
        $ownHistory = $this->subscribe($page);
        self::assertNotNull($ownHistory['data'][$section]['changes']);
        self::assertCount(count($revisions), $ownHistory['windows'][$table]['rows']);
        $admin = (int)Hilos::$db->users->actions->createWithName('History administrator')->id;
        $session = Hilos::$db->sessions->actions->createAnonymous('b3f650d14f7a4a168c263d0437cf232e');
        $session->actions->bindUser($reader);
        $session->actions->setImpersonator($admin);
        Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, $reader, $session->token, (int)$session->id);
        self::assertSame($admin, $session->impersonatorUserId);
        self::assertSame($admin, Takeover::administratorBehind(self::ACCEPT_KEY));
        self::assertTrue(AccountStandingResolver::isFrozen($reader));
        self::assertSame($reader, Hilos::$browser->resolveActionUserId(self::ACCEPT_KEY));
        $payload = $this->subscribe($page);
        $state = array_values(array_filter($payload['data']['legalAgreements']['documents'],
            static fn (array $state): bool => $state['document'] === $document->value))[0];
        self::assertSame('lapsed', $state['standing']);
        self::assertSame([$old], array_column($state['accepted'], 'revisionId'));
        self::assertNotNull($payload['data'][$section]['changes']);
        self::assertCount(count($revisions), $payload['windows'][$table]['rows']);
    }

    /**
     * @param string $page History address
     * @param string $section Legal text section
     * @param string $table Window key
     * @param list<string> $revisions Unused published revisions
     */
    #[DataProvider('documents')]
    public function testAbsentAndFaultyDocumentsGiveNullTextAndAnEmptyWindow(
        string $page, string $section, string $table, array $revisions,
    ): void {
        foreach ([false, true] as $signedIn) {
            if ($signedIn) {
                $this->reader('History without published document');
            } else {
                Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, null);
            }
            foreach ([PublicHistoryUnpublishedHilos::class, PublicHistoryBrokenHilos::class] as $facade) {
                new ReflectionProperty(HilosFacade::class, 'appClass')->setValue(null, $facade);
                $payload = $this->subscribe($page);
                self::assertNull($payload['data'][$section]);
                self::assertArrayNotHasKey('legalAgreements', $payload['data']);
                self::assertSame([], $payload['windows'][$table]['rows']);
                self::assertSame(0, $payload['windows'][$table]['totalCount']);
                self::assertTrue($payload['windows'][$table]['totalExact']);
            }
        }
    }

    /** @param string $document Original public action's document */
    #[DataProvider('readDocuments')]
    public function testPublicTextReadStillBelongsToItsOriginalPage(string $document): void
    {
        $page = $document === 'terms' ? new TermsPage(new ChatAgent()) : new PrivacyPage(new ChatAgent());
        $dto = $document === 'terms'
            ? new TermsRevisionTextActionDTO($document, '2026-09-17')
            : new PrivacyRevisionTextActionDTO($document, '2026-09-17');
        self::assertSame($page::PAGE, Hilos::getPageActionRoutes()[$dto->getAction()]);
        PageAccessGate::assertAction($page::class, self::ACCEPT_KEY, $dto->getAction());
        $reply = $page->onAction(self::ACCEPT_KEY, $dto->getAction(), $dto);
        self::assertInstanceOf(LegalRevisionTextReplyDTO::class, $reply);
        self::assertSame($document, $reply->document);
        self::assertSame('2026-09-17', $reply->revisionId);
        self::assertNotEmpty($reply->clauses);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /**
     * @param string $document Original action owner
     * @param bool $foreign Whether to name the other document or an unknown revision
     */
    #[DataProvider('refusedReads')]
    public function testTextReadsRefuseAnotherDocumentAndUnknownRevisions(string $document, bool $foreign): void
    {
        $page = $document === 'terms' ? new TermsPage(new ChatAgent()) : new PrivacyPage(new ChatAgent());
        $requested = $foreign ? ($document === 'terms' ? 'privacy' : 'terms') : $document;
        $revision = $foreign ? '2026-09-17' : 'unknown';
        $dto = $document === 'terms'
            ? new TermsRevisionTextActionDTO($requested, $revision)
            : new PrivacyRevisionTextActionDTO($requested, $revision);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($foreign ? 'Unknown legal document' : 'Unknown legal revision');
        $page->onAction(self::ACCEPT_KEY, $dto->getAction(), $dto);
    }

    /** @return array<string, array{string, string, string, list<string>}> Both public history bindings */
    public static function documents(): array
    {
        return [
            'terms' => [TermsHistoryPage::PAGE, 'legalTerms', ChatTableContext::hilosTermsHistory,
                ['2026-10-01', '2026-09-27', '2026-09-17']],
            'privacy' => [PrivacyHistoryPage::PAGE, 'legalPrivacy', ChatTableContext::hilosPrivacyHistory,
                ['2026-10-05', '2026-09-17']],
        ];
    }

    /** @return array<string, array{string}> Existing public read owners */
    public static function readDocuments(): array
    {
        return ['terms' => ['terms'], 'privacy' => ['privacy']];
    }

    /** @return array<string, array{string, bool}> Invalid addresses for each original public read */
    public static function refusedReads(): array
    {
        return [
            'terms foreign' => ['terms', true], 'terms unknown' => ['terms', false],
            'privacy foreign' => ['privacy', true], 'privacy unknown' => ['privacy', false],
        ];
    }

    /**
     * @param string $name Reader display name
     * @return int Registered reader behind the connection
     */
    private function reader(string $name): int
    {
        $reader = (int)Hilos::$db->users->actions->createWithName($name)->id;
        Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, $reader);

        return $reader;
    }

    /**
     * @param string $page New page key
     * @return array<string, mixed> The payload of the single subscription reply
     */
    private function subscribe(string $page): array
    {
        $subscribe = new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, $page);
        self::assertSame([], $subscribe->params);
        Hilos::$sr->subscribeToPage($page, $subscribe);
        $router = new PageSignalRouter(new HilosPageFactory(new ChatAgent(), Hilos::class), new ActionRouteConfig(Hilos::getPageActionRoutes()));
        ExecutionContext::run(new ExecutionFrame(acceptKey: self::ACCEPT_KEY), static function () use ($router, $subscribe, $page): void {
            $router->dispatchPageSubscribe($subscribe, SignalTypeConstants::PAGE_SUBSCRIBE, $page);
        });
        $answers = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertNotSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $signal->signalName->getName());
            self::assertNotSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_TERMS_HISTORY, $signal->signalName->getName());
            self::assertNotSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_PRIVACY_HISTORY, $signal->signalName->getName());
            if ($signal->signalName->getName() === SignalTypeConstants::PAGE_RESPONSE) {
                self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
                self::assertSame(self::ACCEPT_KEY, $signal->data->targetAcceptKey);
                self::assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
                $answers[] = $signal->data->data->toArray();
            }
        }
        self::assertCount(1, $answers);
        self::assertSame($page, $answers[0]['page']);
        self::assertArrayNotHasKey('refusedWindows', $answers[0]['payload']);

        return $answers[0]['payload'];
    }
}

abstract class PublicHistoryUnpublishedHilos extends HilosFacade
{
}

abstract class PublicHistoryBrokenHilos extends HilosFacade
{
    protected const ?string LEGAL_CATALOG = PublicHistoryBrokenCatalog::class;
}

final class PublicHistoryBrokenCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Invalid declaration for both public readers */
    public static function revisions(): array
    {
        return ['terms' => [], 'privacy' => []];
    }
}
