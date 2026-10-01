<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\TermsPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Legal\LegalAgreementsGroup;
use Hilos\Legal\LegalAgreementsProjector;
use Hilos\Legal\LegalDocument;
use Hilos\Pages\Legal\DTO\LegalRevisionTextReplyDTO;
use Hilos\Pages\Legal\DTO\TermsRevisionTextActionDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;

/** The public Terms page answers every reader with the revision in force, and a signed-in one with their standing (HIL-501). */
final class TermsPageTest extends IntegrationTestCase
{
    private const string ACCEPT_KEY = 'legal-terms-accept';
    private const string TEST_AGENT = 'legal-terms-test';

    protected function setUp(): void
    {
        parent::setUp();
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT);
        Hilos::$rt->connections->actions->clear();
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        Hilos::$rt->connections->actions->clear();
        RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT);
        Hilos::$sr = null;
        parent::tearDown();
    }

    public function testAGuestReadsTheRevisionInForceAndTheHistoryWithoutAStanding(): void
    {
        Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, null);
        $sections = $this->subscribe();

        self::assertArrayNotHasKey(LegalAgreementsProjector::SECTION, $sections);
        $terms = $sections[LegalAgreementsProjector::TERMS_SECTION];
        self::assertSame('2026-10-01', $terms['current']['revisionId']);
        self::assertCount(6, $terms['clauses']);
        self::assertCount(4, array_filter($terms['clauses'], static fn (array $clause): bool => $clause['source'] === 'deviation'));
        self::assertSame(['2026-09-17', '2026-09-27', '2026-10-01'], array_column($terms['revisions'], 'revisionId'));
        self::assertSame(['first', 'project', 'project'], array_column($terms['revisions'], 'origin'));
        self::assertNull($terms['changes']);
        self::assertNull(Hilos::$sr->groupSubscriptionName(self::ACCEPT_KEY, LegalAgreementsGroup::NAME));
    }

    public function testAReaderHoldingTheRevisionInForceHasNothingToCompare(): void
    {
        $userId = $this->signIn('Terms reader in force');
        Hilos::$db->legalAcceptances->actions->accept($userId, LegalDocument::TERMS, '2026-10-01');
        $sections = $this->subscribe();

        self::assertNull($sections[LegalAgreementsProjector::TERMS_SECTION]['changes']);
        $standing = $sections[LegalAgreementsProjector::SECTION]['documents'];
        self::assertSame('terms', $standing[0]['document']);
        self::assertSame('covered', $standing[0]['standing']);
        self::assertSame(
            LegalAgreementsGroup::forUser($userId),
            Hilos::$sr->groupSubscriptionName(self::ACCEPT_KEY, LegalAgreementsGroup::forUser($userId)),
        );
    }

    public function testAReaderHoldingAnOlderRevisionReceivesItsComparisonWithTheOneInForce(): void
    {
        $userId = $this->signIn('Terms reader behind');
        Hilos::$db->legalAcceptances->actions->accept($userId, LegalDocument::TERMS, '2026-09-27');
        $changes = $this->subscribe()[LegalAgreementsProjector::TERMS_SECTION]['changes'];

        self::assertSame('2026-09-27', $changes['fromRevisionId']);
        self::assertSame('2026-10-01', $changes['toRevisionId']);
        self::assertCount(1, $changes['changes']);
        self::assertSame('changed', $changes['changes'][0]['kind']);
        self::assertSame('standard.availability', $changes['changes'][0]['clauseKey']);
    }

    public function testAnyReaderOpensAnOlderTermsRevision(): void
    {
        $text = new TermsPage(new ChatAgent())->onAction(self::ACCEPT_KEY, HilosSignalConstants::HILOS_TERMS_REVISION_TEXT,
            TermsRevisionTextActionDTO::fromArray(['document' => 'terms', 'revisionId' => '2026-09-17']));

        self::assertInstanceOf(LegalRevisionTextReplyDTO::class, $text);
        self::assertSame('terms', $text->document);
        self::assertSame('2026-09-17', $text->revisionId);
        self::assertCount(6, $text->clauses);
        self::assertNull(Hilos::$sr->getNextQueuedSignal(), 'A read answers directly without sending a companion signal');
    }

    public function testThePageReadsOnlyTheTerms(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unknown legal document');
        new TermsPage(new ChatAgent())->onAction(self::ACCEPT_KEY, HilosSignalConstants::HILOS_TERMS_REVISION_TEXT,
            new TermsRevisionTextActionDTO('privacy', '2026-09-17'));
    }

    public function testAnUnknownRevisionIsUserInputNotACatalogFault(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unknown legal revision');
        new TermsPage(new ChatAgent())->onAction(self::ACCEPT_KEY, HilosSignalConstants::HILOS_TERMS_REVISION_TEXT,
            new TermsRevisionTextActionDTO('terms', 'unknown'));
    }

    public function testAnIncompleteActionPayloadIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);
        TermsRevisionTextActionDTO::fromArray(['document' => 'terms']);
    }

    /**
     * @param string $name Display name of the reader
     * @return int Signed-in reader behind the test connection
     */
    private function signIn(string $name): int
    {
        $userId = (int)Hilos::$db->users->actions->createWithName($name)->id;
        Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, $userId);

        return $userId;
    }

    /** @return array<string, mixed> Data sections of the page's one answer */
    private function subscribe(): array
    {
        Hilos::$sr->subscribeToPage(TermsPage::PAGE, new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, TermsPage::PAGE));
        ExecutionContext::run(new ExecutionFrame(acceptKey: self::ACCEPT_KEY), static function (): void {
            new TermsPage(new ChatAgent())->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
        });
        $answers = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === SignalTypeConstants::PAGE_RESPONSE) {
                self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
                self::assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
                $answers[] = $signal->data->data->toArray();
            }
        }
        self::assertCount(1, $answers);
        self::assertSame(TermsPage::PAGE, $answers[0][PageResponseSignalData::page]);

        return $answers[0][PageResponseSignalData::payload][PagePayload::data];
    }
}
