<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users;

use Hilos\Auth\Session\DTO\UserSessionsEndRequestSignalData;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Hilos;
use Hilos\Pages\Users\AbstractHilosUserPage;
use Hilos\Pages\Users\AbstractHilosUserSessionsPage;
use Hilos\Pages\Users\DTO\HilosUserSessionEndActionDTO;
use Hilos\Pages\Users\DTO\HilosUserSessionsEndAllActionDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Sessions tab of a person's card keeps its two endings behind the ADMIN gate and hands each to
 * the sessions library, answering only when the library does (HIL-1662).
 *
 * What the library does with the ask is pinned by AdminSessionsEndActionsTest; here, what the page
 * declares and what leaves it.
 */
final class HilosUserSessionsPageTest extends TestCase
{
    protected function setUp(): void
    {
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        parent::tearDown();
    }

    /** The tab answers its two endings and nothing of the card's: no rename, merge, rights, block, deletion or takeover. */
    public function testTheTabDeclaresItsTwoEndingsAndNoneOfTheCardsActions(): void
    {
        self::assertSame(PageAccessLevel::ADMIN, AbstractHilosUserSessionsPage::ACCESS_LEVEL);
        self::assertSame([
            HilosSignalConstants::HILOS_USER_SESSION_END => HilosUserSessionEndActionDTO::class,
            HilosSignalConstants::HILOS_USER_SESSIONS_END_ALL => HilosUserSessionsEndAllActionDTO::class,
        ], AbstractHilosUserSessionsPage::ACTIONS);
        self::assertSame([], array_intersect_key(AbstractHilosUserPage::ACTIONS, AbstractHilosUserSessionsPage::ACTIONS));
        self::assertSame([], AbstractHilosUserSessionsPage::READING_ACTIONS, 'Both endings write: a viewer is refused them');
        self::assertSame(
            [SignalTypeConstants::AGENT_SIGNAL => [HilosSignalConstants::HILOS_USER_SESSIONS_END_DONE => HandoverAnswerSignalData::class]],
            AbstractHilosUserSessionsPage::SIGNALS,
        );
    }

    public function testACardActionIsNotAnsweredOnTheTab(): void
    {
        $this->expectException(AgentUnknownActionException::class);
        self::page()->onAction('accept-1', HilosSignalConstants::HILOS_USER_BLOCK_SET, new HilosUserSessionsEndAllActionDTO(12));
    }

    /** @return array<string, array{string, array<string, int>, ?int}> Action, its payload, and the session the ask names */
    public static function endings(): array
    {
        return [
            'one session' => [HilosSignalConstants::HILOS_USER_SESSION_END, ['userId' => 12, 'sessionId' => 34], 34],
            'every session' => [HilosSignalConstants::HILOS_USER_SESSIONS_END_ALL, ['userId' => 12], null],
        ];
    }

    /**
     * A tracked ending leaves as one ask to the sessions library, keeps its correlation across the
     * process boundary, and the submit waits: nothing is answered on the way out.
     *
     * @param string $action Browser action
     * @param array<string, int> $payload Browser payload
     * @param ?int $sessionId Session the ask is to name, or null for every session
     */
    #[DataProvider('endings')]
    public function testATrackedEndingIsHandedToTheLibraryAndWaits(string $action, array $payload, ?int $sessionId): void
    {
        $page = self::page();
        $page->beginActionDispatch('request-1');
        $dtoClass = AbstractHilosUserSessionsPage::ACTIONS[$action];
        $dto = $dtoClass::fromArray([SignalPayloadConstants::FIELD_DATA => $payload]);

        self::assertSame($action, $dto->getAction());
        self::assertSame($payload, $dto->toArray());
        self::assertNull($page->onAction('accept-1', $action, $dto));
        self::assertTrue($page->actionReplyDeferred());

        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal);
        self::assertSame(HilosSignalConstants::HILOS_USER_SESSIONS_END_REQUEST, $signal->signalName->getName());
        self::assertInstanceOf(AgentSignalData::class, $signal->data);
        $ask = AgentSignalData::fromJson($signal->data->toJson())->data;
        self::assertInstanceOf(UserSessionsEndRequestSignalData::class, $ask);
        self::assertSame([
            UserSessionsEndRequestSignalData::userId => 12,
            UserSessionsEndRequestSignalData::sessionId => $sessionId,
            UserSessionsEndRequestSignalData::replySignal => HilosSignalConstants::HILOS_USER_SESSIONS_END_DONE,
            UserSessionsEndRequestSignalData::acceptKey => 'accept-1',
            UserSessionsEndRequestSignalData::requestId => 'request-1',
            UserSessionsEndRequestSignalData::action => $action,
            UserSessionsEndRequestSignalData::successMessage => null,
        ], $ask->toArray());
        self::assertNull(Hilos::$sr->getNextQueuedSignal(), 'No ack before the library answers');
    }

    public function testAnEndingCannotBorrowTheOtherEndingsPayload(): void
    {
        $this->expectException(InvalidActionPayloadException::class);
        self::page()->onAction('accept-1', HilosSignalConstants::HILOS_USER_SESSION_END, new HilosUserSessionsEndAllActionDTO(12));
    }

    public function testAnUnknownAnswerIsRefused(): void
    {
        $this->expectException(AgentUnknownSignalException::class);
        self::page()->onSignalAgent(new AgentSignalData(new SignalData([])), 'library', HilosSignalConstants::HILOS_IMPERSONATE_DONE);
    }

    /** A field naming the administrator is not part of either payload: whoever sends one, it is dropped before the ask. */
    public function testThePayloadNamesNoAdministrator(): void
    {
        $forged = ['userId' => 12, 'sessionId' => 34, 'by' => 7, 'adminUserId' => 7, 'acceptKey' => 'other'];

        self::assertSame(['userId' => 12, 'sessionId' => 34], HilosUserSessionEndActionDTO::fromArray($forged)->toArray());
        self::assertSame(['userId' => 12], HilosUserSessionsEndAllActionDTO::fromArray($forged)->toArray());
    }

    /** @return iterable<array{class-string, array<string, mixed>}> A payload that must not become an ending */
    public static function malformedPayloads(): iterable
    {
        foreach ([
            [], ['userId' => 0, 'sessionId' => 1], ['userId' => -1, 'sessionId' => 1], ['userId' => '12x', 'sessionId' => 1],
            ['userId' => 12], ['userId' => 12, 'sessionId' => 0], ['userId' => 12, 'sessionId' => -3], ['sessionId' => 34],
        ] as $payload) {
            yield [HilosUserSessionEndActionDTO::class, $payload];
        }
        foreach ([[], ['userId' => 0], ['userId' => -1], ['userId' => 'twelve'], ['sessionId' => 34]] as $payload) {
            yield [HilosUserSessionsEndAllActionDTO::class, $payload];
        }
    }

    /**
     * @param class-string $class Action DTO under test
     * @param array<string, mixed> $payload Malformed browser payload
     */
    #[DataProvider('malformedPayloads')]
    public function testAMalformedPayloadCannotBecomeAnEnding(string $class, array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        $class::fromArray($payload);
    }

    /** @return HilosUserSessionsTestPage A fresh page of the tab */
    private static function page(): HilosUserSessionsTestPage
    {
        return new HilosUserSessionsTestPage(new HilosUserSessionsTestPageAgent());
    }
}

final class HilosUserSessionsTestPage extends AbstractHilosUserSessionsPage
{
}

final class HilosUserSessionsTestPageAgent implements PageAgentInterface
{
    public function getId(): string
    {
        return 'user-sessions-test-agent';
    }

    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'user-sessions-test');
    }
}
