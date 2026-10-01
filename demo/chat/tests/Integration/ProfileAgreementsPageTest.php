<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\ProfileAgreementsHistoryPage;
use Demo\Chat\Pages\Hilos\ProfileAgreementsPage;
use Demo\Chat\Pages\Hilos\ProfilePage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Legal\LegalAgreementsGroup;
use Hilos\Legal\LegalAgreementsProjector;
use Hilos\Pages\Legal\DTO\LegalRevisionChangesActionDTO;
use Hilos\Pages\Legal\DTO\LegalRevisionChangesReplyDTO;
use Hilos\Pages\Legal\DTO\LegalRevisionTextActionDTO;
use Hilos\Pages\Legal\DTO\LegalRevisionTextReplyDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;

/** The initial page answer owns its legal sections; history actions read only on request. */
final class ProfileAgreementsPageTest extends IntegrationTestCase
{
    private const string ACCEPT_KEY = 'legal-profile-accept';
    private const string TEST_AGENT = 'legal-profile-test';

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT);
        Hilos::$rt->connections->actions->clear();
        $this->userId = (int)Hilos::$db->users->actions->createWithName('Legal profile owner')->id;
        $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', $this->nameWithDataSet()), 0, 32));
        $session->actions->bindUser($this->userId);
        Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, $this->userId, $session->token, (int)$session->id);
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        Hilos::$rt->connections->actions->clear();
        RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT);
        Hilos::$sr = null;
        parent::tearDown();
    }

    /** @return iterable<string, array{class-string<AbstractPage>, ?string}> Legal sections owned by each page */
    public static function pages(): iterable
    {
        yield 'agreements' => [ProfileAgreementsPage::class, LegalAgreementsProjector::TEXTS_SECTION];
        yield 'history' => [ProfileAgreementsHistoryPage::class, LegalAgreementsProjector::REVISIONS_SECTION];
        yield 'profile root' => [ProfilePage::class, null];
    }

    /**
     * @param class-string<AbstractPage> $pageClass Subscribed page
     * @param ?string $extraSection Legal section beside lightweight state
     */
    #[DataProvider('pages')]
    public function testOneResponseCarriesTheWholePage(string $pageClass, ?string $extraSection): void
    {
        Hilos::$sr->subscribeToPage($pageClass::PAGE, new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, $pageClass::PAGE));
        ExecutionContext::run(new ExecutionFrame(acceptKey: self::ACCEPT_KEY), static function () use ($pageClass): void {
            new $pageClass(new ChatAgent())->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
        });
        $answers = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === SignalTypeConstants::PAGE_RESPONSE) {
                self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
                self::assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
                $answers[] = $signal->data->data->toArray();
            }
        }
        if ($pageClass === ProfilePage::class) {
            // The existing root also queues its browser snapshot; the legal section rides its page-data answer once.
            $answers = array_values(array_filter($answers, static fn (array $answer): bool =>
                isset($answer[PageResponseSignalData::payload][PagePayload::data][LegalAgreementsProjector::SECTION])));
        }
        self::assertCount(1, $answers);
        self::assertSame($pageClass::PAGE, $answers[0][PageResponseSignalData::page]);
        $sections = $answers[0][PageResponseSignalData::payload][PagePayload::data];
        self::assertSame(['none', 'none'], array_column($sections[LegalAgreementsProjector::SECTION]['documents'], 'standing'));
        self::assertSame('2026-10-01', $sections[LegalAgreementsProjector::SECTION]['documents'][0]['current']['revisionId']);
        if ($extraSection !== null) {
            self::assertCount(2, $sections[$extraSection]['documents']);
        }
        if ($extraSection === LegalAgreementsProjector::TEXTS_SECTION) {
            self::assertCount(6, $sections[$extraSection]['documents'][0]['current']);
        }
        if ($extraSection === LegalAgreementsProjector::REVISIONS_SECTION) {
            self::assertSame(
                ['2026-09-17', '2026-09-27', '2026-10-01'],
                array_column($sections[$extraSection]['documents'][0]['revisions'], 'revisionId'),
            );
            self::assertArrayNotHasKey(LegalAgreementsProjector::TEXTS_SECTION, $sections);
        }
        self::assertSame(
            LegalAgreementsGroup::forUser($this->userId),
            Hilos::$sr->groupSubscriptionName(self::ACCEPT_KEY, LegalAgreementsGroup::forUser($this->userId)),
        );
    }

    public function testTextAndComparisonRepliesUseTheDeclaredCatalog(): void
    {
        $page = new ProfileAgreementsHistoryPage(new ChatAgent());
        $text = $page->onAction(self::ACCEPT_KEY, HilosSignalConstants::HILOS_LEGAL_REVISION_TEXT,
            LegalRevisionTextActionDTO::fromArray(['document' => 'terms', 'revisionId' => '2026-09-27']));
        self::assertInstanceOf(LegalRevisionTextReplyDTO::class, $text);
        self::assertCount(6, $text->clauses);
        self::assertCount(3, array_filter($text->clauses, static fn (array $clause): bool => $clause['source'] === 'deviation'));
        self::assertSame($text->toArray(), LegalRevisionTextReplyDTO::fromArray($text->toArray())->toArray());
        $changes = $page->onAction(self::ACCEPT_KEY, HilosSignalConstants::HILOS_LEGAL_REVISION_CHANGES,
            LegalRevisionChangesActionDTO::fromArray(['document' => 'terms', 'revisionId' => '2026-09-27']));
        self::assertInstanceOf(LegalRevisionChangesReplyDTO::class, $changes);
        self::assertSame('2026-09-17', $changes->fromRevisionId);
        self::assertCount(1, $changes->changes);
        self::assertSame('standard.retention', $changes->changes[0]['clauseKey']);
        self::assertSame('changed', $changes->changes[0]['kind']);
        self::assertSame('deviation', $changes->changes[0]['before']['source']);
        self::assertSame('deviation', $changes->changes[0]['after']['source']);
        self::assertSame($changes->toArray(), LegalRevisionChangesReplyDTO::fromArray($changes->toArray())->toArray());
        self::assertNull(Hilos::$sr->getNextQueuedSignal(), 'A read answers directly without sending a companion signal');
    }

    public function testTheFirstRevisionCannotBeCompared(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('The first revision has nothing to compare with');
        new ProfileAgreementsHistoryPage(new ChatAgent())->onAction(self::ACCEPT_KEY, HilosSignalConstants::HILOS_LEGAL_REVISION_CHANGES,
            new LegalRevisionChangesActionDTO('terms', '2026-09-17'));
    }

    public function testAnUnknownDocumentIsUserInputNotACatalogFault(): void
    {
        $this->expectException(ValidationException::class);
        new ProfileAgreementsHistoryPage(new ChatAgent())->onAction(self::ACCEPT_KEY, HilosSignalConstants::HILOS_LEGAL_REVISION_TEXT,
            new LegalRevisionTextActionDTO('unknown', '2026-09-17'));
    }

    public function testAnUnknownRevisionIsUserInputNotACatalogFault(): void
    {
        $this->expectException(ValidationException::class);
        new ProfileAgreementsHistoryPage(new ChatAgent())->onAction(self::ACCEPT_KEY, HilosSignalConstants::HILOS_LEGAL_REVISION_TEXT,
            new LegalRevisionTextActionDTO('terms', 'unknown'));
    }

    public function testAnIncompleteActionPayloadIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);
        LegalRevisionTextActionDTO::fromArray(['document' => 'terms']);
    }
}
