<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Browser\ChatBrowserContext;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Database;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\PrivacyPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos as HilosFacade;
use Hilos\Legal\LegalAgreementsGroup;
use Hilos\Legal\LegalAgreementsProjector;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Pages\Legal\DTO\LegalRevisionTextReplyDTO;
use Hilos\Pages\Legal\DTO\PrivacyRevisionTextActionDTO;
use Hilos\Pages\Legal\DTO\TermsRevisionTextActionDTO;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Testing\IntegrationTest;
use Hilos\Testing\RollbackIntegrationTestCase;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

/** Owns fresh ORM/runtime/browser contexts around each rollback scope; uses the migrated chat test schema. */
abstract class PrivacyPageIntegrationEnvironment extends RollbackIntegrationTestCase
{
    private const string TEST_AGENT = 'privacy-page-test';

    private ?HilosDbContext $previousDb = null;
    private ?RtContext $previousRt = null;
    private ?BrowserContext $previousBrowser = null;
    private ?SignalRouter $previousSr = null;
    /** @var class-string<HilosFacade> Facade active before the case changes the catalog */
    private string $previousAppClass = Hilos::class;
    private ?int $executionToken = null;

    /** Initializes the shared installation, then isolates the contexts the case reads and writes. */
    protected function prepareIntegrationEnvironment(): void
    {
        Database::initialize(initHilos: true);
        $this->previousDb = Hilos::$db;
        $this->previousRt = Hilos::$rt;
        $this->previousBrowser = Hilos::$browser;
        $this->previousSr = Hilos::$sr;
        $this->previousAppClass = Hilos::appClass();
        Hilos::$db = new ChatDbContext();
        Hilos::$db->configure();
        Hilos::$rt = new ChatRtContext();
        Hilos::$rt->configure();
        Hilos::$rt->bindStateCollectionNames();
        Hilos::$browser = new ChatBrowserContext();
        Hilos::$browser->bindHilosFacade(Hilos::class);
        Hilos::$sr = new SignalRouter();
        TruthSourceRegistry::register(HilosDbContext::users, TruthSourceKeys::all(), self::TEST_AGENT);
        TruthSourceRegistry::register(HilosDbContext::legalAcceptances, TruthSourceKeys::all(), self::TEST_AGENT);
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT);
        $this->executionToken = ExecutionContext::push(new ExecutionFrame(agentId: self::TEST_AGENT));
    }

    /** Restores facades and releases claims after the harness rolls back the case's rows. */
    protected function releaseIntegrationEnvironment(): void
    {
        Hilos::$db = $this->previousDb;
        Hilos::$rt = $this->previousRt;
        Hilos::$browser = $this->previousBrowser;
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

/** The public Privacy reader gets its text and personal state together, and reads history without signing in (HIL-1608). */
#[IntegrationTest]
final class PrivacyPageTest extends PrivacyPageIntegrationEnvironment
{
    private const string ACCEPT_KEY = 'legal-privacy-accept';

    public function testAGuestReadsTheCurrentPolicyAndHistoryWithoutPersonalState(): void
    {
        Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, null);
        $sections = $this->subscribe();

        self::assertArrayNotHasKey(LegalAgreementsProjector::SECTION, $sections);
        $privacy = $sections[LegalAgreementsProjector::PRIVACY_SECTION];
        self::assertSame([
            'revisionId' => '2026-10-05', 'publishedOn' => '2026-10-05', 'effectiveOn' => '2026-10-05',
            'significance' => 'substantial', 'setVersion' => 1, 'deviationCount' => 2,
        ], $privacy['current']);
        self::assertCount(7, $privacy['clauses']);
        self::assertCount(2, array_filter($privacy['clauses'], static fn (array $clause): bool => $clause['source'] === 'deviation'));
        foreach ($privacy['clauses'] as $clause) {
            self::assertSame(['clauseKey', 'standardStatement', 'source', 'statement', 'text', 'direction'], array_keys($clause));
            self::assertNotSame('', $clause['text']);
        }
        self::assertSame(['2026-09-17', '2026-10-05'], array_column($privacy['revisions'], 'revisionId'));
        self::assertSame(['first', 'project'], array_column($privacy['revisions'], 'origin'));
        self::assertSame([null, 1], array_column($privacy['revisions'], 'previousSetVersion'));
        self::assertNull($privacy['changes']);
        self::assertNull(Hilos::$sr->groupSubscriptionName(self::ACCEPT_KEY, LegalAgreementsGroup::NAME));
    }

    public function testAReaderHoldingTheCurrentPolicyReceivesTheirTimestampAndJoinsTheGroup(): void
    {
        $userId = $this->signIn('Privacy current');
        $acceptance = Hilos::$db->legalAcceptances->actions->accept($userId, LegalDocument::PRIVACY, '2026-10-05');
        $sections = $this->subscribe();
        $privacy = $sections[LegalAgreementsProjector::PRIVACY_SECTION];
        $documents = $sections[LegalAgreementsProjector::SECTION]['documents'];

        self::assertNull($privacy['changes']);
        self::assertSame(['terms', 'privacy'], array_column($documents, 'document'));
        self::assertSame('covered', $documents[1]['standing']);
        self::assertSame($privacy['current'], $documents[1]['current']);
        self::assertSame($privacy['current'], $documents[1]['held']);
        self::assertSame(TimeHelper::sqlToMs($acceptance->acceptedAt), $documents[1]['acceptedAt']);
        self::assertGreaterThan(1_000_000_000_000, $documents[1]['acceptedAt']);
        self::assertSame([
            ['revisionId' => '2026-10-05', 'acceptedAt' => $documents[1]['acceptedAt']],
        ], $documents[1]['accepted']);
        self::assertNull($documents[1]['deadline']);
        $this->assertJoined($userId);
    }

    public function testAReaderHoldingAnOlderPolicyReceivesTheEffectiveClauseComparison(): void
    {
        $userId = $this->signIn('Privacy behind');
        Hilos::$db->legalAcceptances->actions->accept($userId, LegalDocument::PRIVACY, '2026-09-17');
        $sections = $this->subscribe();
        $changes = $sections[LegalAgreementsProjector::PRIVACY_SECTION]['changes'];

        self::assertSame('2026-09-17', $changes['fromRevisionId']);
        self::assertSame('2026-10-05', $changes['toRevisionId']);
        self::assertSame(['standard.deletion', 'standard.access_log'], array_column($changes['changes'], 'clauseKey'));
        self::assertSame(['changed', 'changed'], array_column($changes['changes'], 'kind'));
        self::assertSame('standard', $changes['changes'][0]['before']['source']);
        self::assertSame('deviation', $changes['changes'][0]['after']['source']);
        self::assertSame('looser', $changes['changes'][1]['before']['direction']);
        self::assertSame('stricter', $changes['changes'][1]['after']['direction']);
        foreach ($changes['changes'] as $change) {
            self::assertNotSame('', $change['title']);
            self::assertSame(['source', 'statement', 'text', 'direction'], array_keys($change['before']));
            self::assertSame(['source', 'statement', 'text', 'direction'], array_keys($change['after']));
        }
        self::assertSame('lapsed', $sections[LegalAgreementsProjector::SECTION]['documents'][1]['standing']);
        self::assertSame('2026-10-05', $sections[LegalAgreementsProjector::SECTION]['documents'][1]['deadline']);
        $this->assertJoined($userId);
    }

    public function testAnUnacceptedPolicyHasNoHeldComparison(): void
    {
        $userId = $this->signIn('Privacy unaccepted');
        $sections = $this->subscribe();
        $privacyState = $sections[LegalAgreementsProjector::SECTION]['documents'][1];

        self::assertNull($sections[LegalAgreementsProjector::PRIVACY_SECTION]['changes']);
        self::assertNull($privacyState['held']);
        self::assertNull($privacyState['acceptedAt']);
        self::assertSame([], $privacyState['accepted']);
        self::assertSame('none', $privacyState['standing']);
        $this->assertJoined($userId);
    }

    public function testAnotherPersonsAcceptanceCannotBecomeTheReadersHeldRevision(): void
    {
        $otherId = (int)Hilos::$db->users->actions->createWithName('Another privacy reader')->id;
        Hilos::$db->legalAcceptances->actions->accept($otherId, LegalDocument::PRIVACY, '2026-09-17');
        $this->signIn('Privacy with no acceptance');
        $sections = $this->subscribe();

        self::assertNull($sections[LegalAgreementsProjector::PRIVACY_SECTION]['changes']);
        self::assertSame('none', $sections[LegalAgreementsProjector::SECTION]['documents'][1]['standing']);
    }

    public function testTheLatestDeclaredAcceptanceWinsRegardlessOfAcceptanceOrder(): void
    {
        $userId = $this->signIn('Privacy accepts in reverse');
        Hilos::$db->legalAcceptances->actions->accept($userId, LegalDocument::PRIVACY, '2026-10-05');
        Hilos::$db->legalAcceptances->actions->accept($userId, LegalDocument::PRIVACY, '2026-09-17');
        $sections = $this->subscribe();
        $privacyState = $sections[LegalAgreementsProjector::SECTION]['documents'][1];

        self::assertNull($sections[LegalAgreementsProjector::PRIVACY_SECTION]['changes']);
        self::assertSame('2026-10-05', $privacyState['held']['revisionId']);
        self::assertSame(['2026-09-17', '2026-10-05'], array_column($privacyState['accepted'], 'revisionId'));
    }

    /** @param bool $signedIn Whether this connection has a person */
    #[DataProvider('unpublishedReaders')]
    public function testAnUnpublishedPolicyAlwaysAnswersNullWithoutPersonalState(bool $signedIn): void
    {
        if ($signedIn) {
            $this->signIn('Privacy unpublished');
        } else {
            Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, null);
        }
        new ReflectionProperty(HilosFacade::class, 'appClass')->setValue(null, PrivacyUnpublishedHilos::class);
        $sections = $this->subscribe();

        self::assertArrayHasKey(LegalAgreementsProjector::PRIVACY_SECTION, $sections);
        self::assertNull($sections[LegalAgreementsProjector::PRIVACY_SECTION]);
        self::assertArrayNotHasKey(LegalAgreementsProjector::SECTION, $sections);
    }

    /** @return array<string, array{bool}> Public readers without a policy */
    public static function unpublishedReaders(): array
    {
        return ['guest' => [false], 'signed in' => [true]];
    }

    public function testAFaultyCatalogAnswersNullWithoutPersonalState(): void
    {
        $this->signIn('Privacy faulty catalog');
        new ReflectionProperty(HilosFacade::class, 'appClass')->setValue(null, PrivacyFaultyHilos::class);
        $sections = $this->subscribe();

        self::assertArrayHasKey(LegalAgreementsProjector::PRIVACY_SECTION, $sections);
        self::assertNull($sections[LegalAgreementsProjector::PRIVACY_SECTION]);
        self::assertArrayNotHasKey(LegalAgreementsProjector::SECTION, $sections);
    }

    public function testAGuestReadsAnOlderPolicyThroughThePagesOwnAction(): void
    {
        $payload = ['document' => 'privacy', 'revisionId' => '2026-09-17'];
        $dto = PrivacyRevisionTextActionDTO::fromArray($payload);
        self::assertSame($payload, $dto->toArray());
        self::assertSame($payload, PrivacyRevisionTextActionDTO::fromArray($dto->toArray())->toArray());
        self::assertSame(HilosSignalConstants::HILOS_PRIVACY_REVISION_TEXT, $dto->getAction());
        self::assertSame([], PrivacyRevisionTextActionDTO::SECRET_FIELDS);
        $text = new PrivacyPage(new ChatAgent())->onAction(self::ACCEPT_KEY, $dto->getAction(), $dto);

        self::assertInstanceOf(LegalRevisionTextReplyDTO::class, $text);
        self::assertSame('privacy', $text->document);
        self::assertSame('2026-09-17', $text->revisionId);
        self::assertCount(7, $text->clauses);
        self::assertCount(1, array_filter($text->clauses, static fn (array $clause): bool => $clause['source'] === 'deviation'));
        self::assertNull(Hilos::$sr->getNextQueuedSignal(), 'The read returns its text in the action ACK');
    }

    public function testThePageReadsOnlyPrivacy(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unknown legal document');
        new PrivacyPage(new ChatAgent())->onAction(self::ACCEPT_KEY, HilosSignalConstants::HILOS_PRIVACY_REVISION_TEXT,
            new PrivacyRevisionTextActionDTO('terms', '2026-09-17'));
    }

    public function testAnUnknownRevisionIsRefusedAsUserInput(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unknown legal revision');
        new PrivacyPage(new ChatAgent())->onAction(self::ACCEPT_KEY, HilosSignalConstants::HILOS_PRIVACY_REVISION_TEXT,
            new PrivacyRevisionTextActionDTO('privacy', 'unknown'));
    }

    public function testAWrongDtoCannotReachTheRead(): void
    {
        $this->expectException(InvalidActionPayloadException::class);
        new PrivacyPage(new ChatAgent())->onAction(self::ACCEPT_KEY, HilosSignalConstants::HILOS_PRIVACY_REVISION_TEXT,
            new TermsRevisionTextActionDTO('privacy', '2026-09-17'));
    }

    public function testThePageDoesNotOwnTheAcceptanceAction(): void
    {
        $this->expectException(AgentUnknownActionException::class);
        new PrivacyPage(new ChatAgent())->onAction(self::ACCEPT_KEY, HilosSignalConstants::HILOS_LEGAL_ACCEPT,
            new PrivacyRevisionTextActionDTO('privacy', '2026-10-05'));
    }

    /** @param array<string, mixed> $payload Broken request fields */
    #[DataProvider('invalidPayloads')]
    public function testAnIncompleteOrMistypedPayloadIsRefused(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        PrivacyRevisionTextActionDTO::fromArray($payload);
    }

    /** @return array<string, array{array<string, mixed>}> Absent and mistyped required request fields */
    public static function invalidPayloads(): array
    {
        return [
            'missing revision' => [['document' => 'privacy']],
            'missing document' => [['revisionId' => '2026-09-17']],
            'numeric revision' => [['document' => 'privacy', 'revisionId' => 123]],
            'null document' => [['document' => null, 'revisionId' => '2026-09-17']],
        ];
    }

    /**
     * @param string $name Reader's display name
     * @return int Person behind this connection
     */
    private function signIn(string $name): int
    {
        $userId = (int)Hilos::$db->users->actions->createWithName($name)->id;
        Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, $userId);

        return $userId;
    }

    /**
     * @param int $userId Person whose live state the reader follows
     */
    private function assertJoined(int $userId): void
    {
        self::assertSame(LegalAgreementsGroup::forUser($userId), Hilos::$sr->groupSubscriptionName(
            self::ACCEPT_KEY, LegalAgreementsGroup::forUser($userId),
        ));
    }

    /** @return array<string, mixed> Data in the single initial page answer */
    private function subscribe(): array
    {
        Hilos::$sr->subscribeToPage(PrivacyPage::PAGE, new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, PrivacyPage::PAGE));
        ExecutionContext::run(new ExecutionFrame(acceptKey: self::ACCEPT_KEY), static function (): void {
            new PrivacyPage(new ChatAgent())->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
        });
        $answers = [];
        $names = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $names[] = $signal->signalName->getName();
            if ($signal->signalName->getName() === SignalTypeConstants::PAGE_RESPONSE) {
                self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
                self::assertSame(self::ACCEPT_KEY, $signal->data->targetAcceptKey);
                self::assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
                $answers[] = $signal->data->data->toArray();
            }
        }
        self::assertCount(1, $answers);
        self::assertSame(PrivacyPage::PAGE, $answers[0][PageResponseSignalData::page]);
        self::assertSame(Hilos::$browser->resolveActionUserId(self::ACCEPT_KEY) === null
            ? [SignalTypeConstants::PAGE_RESPONSE]
            : [SignalTypeConstants::PAGE_RESPONSE, SignalTypeConstants::GROUP_JOIN], $names);

        return $answers[0][PageResponseSignalData::payload][PagePayload::data];
    }
}

/** Catalog-only facade: this installation publishes no documents. */
abstract class PrivacyUnpublishedHilos extends HilosFacade
{
}

/** Catalog-only facade whose declared policy is faulty. */
abstract class PrivacyFaultyHilos extends HilosFacade
{
    protected const ?string LEGAL_CATALOG = PrivacyFaultyCatalog::class;
}

/** A document without revisions exercises the public reader's existing null-on-catalog-failure contract. */
final class PrivacyFaultyCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Faulty declaration */
    public static function revisions(): array
    {
        return [LegalDocument::PRIVACY->value => []];
    }
}
