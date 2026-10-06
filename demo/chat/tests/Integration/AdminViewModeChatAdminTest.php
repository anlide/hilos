<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosLegalAgent;
use Demo\Chat\Browser\Table\GuardianAgentStatusDetailBrowserTable;
use Demo\Chat\Browser\Table\GuardianAgentStatusesBrowserTable;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\AdminBotsPage;
use Demo\Chat\Pages\AdminModeratorPage;
use Demo\Chat\Pages\Hilos\Guardian\GuardianAgentPage;
use Demo\Chat\Pages\Hilos\GuardianPage;
use Demo\Chat\Runtime\State\Item\GuardianAgentStatus;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\Bot\BotTableRow;
use Demo\Chat\Tables\ChatTableContext;
use Demo\Chat\Tables\ModeratorPiece\ModeratorPromptPieceTableRow;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Hilos\GuardianRunStatus;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use JsonException;

/**
 * Integration coverage for chat admin surfaces under the admin view mode (HIL-1259).
 *
 * Verifies that on the chat demo's administrative pages (AdminBotsPage, AdminModeratorPage,
 * GuardianPage, GuardianAgentPage) non-personal fields and declarations are preserved for both
 * anonymous and signed-in non-admin viewers, and an admin receives those pages with no hidden
 * marks. A viewer's look at the people list lives with the framework users page.
 */
final class AdminViewModeChatAdminTest extends IntegrationTestCase
{
    private const string ANONYMOUS_KEY = 'admin-view-mode-chat-anonymous';
    private const string VISITOR_KEY = 'admin-view-mode-chat-visitor';
    private const string ADMIN_KEY = 'admin-view-mode-chat-admin';
    private const string TEST_AGENT = 'admin-view-mode-chat-admin-test';
    private const string GUARDIAN_AGENT_ID = 'test-guardian-agent-id';

    private int $botId;
    private int $pieceId;

    /**
     * Initializes the chat browser context, registers truth sources, enables the view mode, and seeds data.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT);
        Hilos::$rt->connections->actions->clear();
        Hilos::$sr = new AdminViewModeChatAdminRouter();
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);

        RtTruthSourceRegistry::register(ChatRtContext::guardianAgentStatuses, TruthSourceKeys::all(), self::TEST_AGENT);
        Hilos::$rt->guardianAgentStatuses->actions->create(self::GUARDIAN_AGENT_ID, GuardianRunStatus::FAILED);

        $bot = Hilos::$db->bots->actions->create(
            name: 'Helper Bot',
            description: 'Helpful assistant',
            style: 'formal',
            topics: 'support,help',
            personality: 'kind',
            active: true,
        );
        $this->botId = (int) $bot->id;

        $piece = Hilos::$db->moderatorPromptPieces->actions->create('name_rule', 'Hello, welcome to chat!');
        $this->pieceId = (int) $piece->id;

        $visitor = Hilos::$db->users->actions->createWithName('Visitor of the node');
        $visitorId = (int) $visitor->id;

        $admin = Hilos::$db->users->actions->createWithName('Admin of the node');
        $admin->actions->setAdmin(true);
        $adminId = (int) $admin->id;

        Hilos::$rt->connections->actions->register(self::ANONYMOUS_KEY, null);
        Hilos::$rt->connections->actions->register(self::VISITOR_KEY, $visitorId);
        Hilos::$rt->connections->actions->register(self::ADMIN_KEY, $adminId);
    }

    /**
     * Disables the view mode, clears runtime collections, and resets router and truth-source claims.
     */
    protected function tearDown(): void
    {
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->guardianAgentStatuses->actions->clear();
        Hilos::initBrowser();
        Hilos::$rt->connections->actions->clear();
        RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT);
        Hilos::$sr = null;
        parent::tearDown();
    }

    /**
     * A viewer (anonymous or visitor) receives the bots table with all 13 bot fields and status shown.
     */
    public function testAViewerSeesBotsWithAllFieldsShownAndStatusFromRuntime(): void
    {
        foreach ([self::ANONYMOUS_KEY, self::VISITOR_KEY] as $acceptKey) {
            $frames = $this->subscribe(
                AdminBotsPage::class,
                [],
                $acceptKey,
                [ChatTableContext::bots => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT)],
            );
            $rows = $this->window($frames, ChatTableContext::bots)[TableWindowSignalData::rows];
            $botRow = null;
            foreach ($rows as $row) {
                if ($row[BrowserPageSignalData::rowKey] === $this->botId) {
                    $botRow = $row;
                    break;
                }
            }
            self::assertNotNull($botRow, "Bot row not found for {$acceptKey}");
            $slots = $botRow[PagePayload::slots];
            $botSlot = $slots[ChatDbContext::bots];
            self::assertSame($this->botId, $botSlot[BotTableRow::id]);
            self::assertSame('Helper Bot', $botSlot[BotTableRow::name]);
            self::assertSame('Helpful assistant', $botSlot[BotTableRow::description]);
            self::assertSame('formal', $botSlot[BotTableRow::style]);
            self::assertSame('support,help', $botSlot[BotTableRow::topics]);
            self::assertSame('kind', $botSlot[BotTableRow::personality]);
            self::assertTrue($botSlot[BotTableRow::active]);
            self::assertSame(5, $botSlot[BotTableRow::reactionDelayMin]);
            self::assertSame(30, $botSlot[BotTableRow::reactionDelayMax]);
            self::assertSame(80, $botSlot[BotTableRow::reactionChance]);
            self::assertTrue($botSlot[BotTableRow::topicMatchRequired]);
            self::assertSame(60, $botSlot[BotTableRow::cooldownAfterMessage]);
            self::assertSame(0, $botSlot[BotTableRow::priority]);
            self::assertCount(13, $botSlot);
            foreach ($botSlot as $field => $value) {
                self::assertFalse(HiddenValue::isMark($value), "field {$field} is mark for {$acceptKey}");
            }
            $statusSlot = $slots[ChatRtContext::botAgentStatuses];
            self::assertArrayHasKey(BotTableRow::status, $statusSlot);
            self::assertFalse(HiddenValue::isMark($statusSlot[BotTableRow::status]), "status is mark for {$acceptKey}");
        }
    }

    /**
     * A viewer (anonymous or visitor) receives moderator prompt pieces with all fields shown.
     */
    public function testAViewerSeesModeratorPromptPiecesWithAllFieldsShown(): void
    {
        foreach ([self::ANONYMOUS_KEY, self::VISITOR_KEY] as $acceptKey) {
            $frames = $this->subscribe(
                AdminModeratorPage::class,
                [],
                $acceptKey,
                [ChatTableContext::moderatorPromptPieces => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT)],
            );
            $rows = $this->window($frames, ChatTableContext::moderatorPromptPieces)[TableWindowSignalData::rows];
            $pieceRow = null;
            foreach ($rows as $row) {
                if ($row[BrowserPageSignalData::rowKey] === $this->pieceId) {
                    $pieceRow = $row;
                    break;
                }
            }
            self::assertNotNull($pieceRow, "Prompt piece row not found for {$acceptKey}");
            $pieceSlot = $pieceRow[PagePayload::slots][ChatDbContext::moderatorPromptPieces];
            self::assertSame($this->pieceId, $pieceSlot[ModeratorPromptPieceTableRow::id]);
            self::assertSame('name_rule', $pieceSlot[ModeratorPromptPieceTableRow::section]);
            self::assertSame('Hello, welcome to chat!', $pieceSlot[ModeratorPromptPieceTableRow::promptPiece]);
            self::assertFalse(HiddenValue::isMark($pieceSlot[ModeratorPromptPieceTableRow::id]));
            self::assertFalse(HiddenValue::isMark($pieceSlot[ModeratorPromptPieceTableRow::section]));
            self::assertFalse(HiddenValue::isMark($pieceSlot[ModeratorPromptPieceTableRow::promptPiece]));
        }
    }

    /**
     * A viewer receives guardian agent statuses (both list and detail) with all fields shown.
     */
    public function testAViewerSeesGuardianStatusesWithAllFieldsShown(): void
    {
        foreach ([self::ANONYMOUS_KEY, self::VISITOR_KEY] as $acceptKey) {
            $listFrames = $this->subscribe(GuardianPage::class, [], $acceptKey);
            $listSlots = $this->declarativeRow($listFrames, GuardianAgentStatusesBrowserTable::TABLE)[PagePayload::slots];
            $listGuardian = $listSlots[ChatRtContext::guardianAgentStatuses];
            self::assertSame(self::GUARDIAN_AGENT_ID, $listGuardian[GuardianAgentStatus::agentId]);
            self::assertSame(GuardianRunStatus::FAILED->value, $listGuardian[GuardianAgentStatus::status]);
            self::assertFalse(HiddenValue::isMark($listGuardian[GuardianAgentStatus::agentId]));
            self::assertFalse(HiddenValue::isMark($listGuardian[GuardianAgentStatus::status]));
            self::assertFalse(HiddenValue::isMark($listGuardian[GuardianAgentStatus::updatedAt]));

            $detailFrames = $this->subscribe(
                GuardianAgentPage::class,
                [HilosPageRouteParams::HILOS_GUARDIAN_AGENT_AGENT_ID => self::GUARDIAN_AGENT_ID],
                $acceptKey,
            );
            $detailSlots = $this->declarativeRow($detailFrames, GuardianAgentStatusDetailBrowserTable::TABLE)[PagePayload::slots];
            $detailGuardian = $detailSlots[ChatRtContext::guardianAgentStatuses];
            self::assertSame(self::GUARDIAN_AGENT_ID, $detailGuardian[GuardianAgentStatus::agentId]);
            self::assertSame(GuardianRunStatus::FAILED->value, $detailGuardian[GuardianAgentStatus::status]);
            self::assertFalse(HiddenValue::isMark($detailGuardian[GuardianAgentStatus::agentId]));
            self::assertFalse(HiddenValue::isMark($detailGuardian[GuardianAgentStatus::status]));
            self::assertFalse(HiddenValue::isMark($detailGuardian[GuardianAgentStatus::updatedAt]));
        }
    }

    /**
     * An admin receives the chat admin pages with real data and no hidden marks.
     *
     * @throws JsonException When JSON serialization fails
     */
    public function testAnAdminIsSentThePagesWithoutASingleMark(): void
    {
        $frames = [
            ...$this->subscribe(AdminBotsPage::class, [], self::ADMIN_KEY, [
                ChatTableContext::bots => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
            ]),
            ...$this->subscribe(AdminModeratorPage::class, [], self::ADMIN_KEY, [
                ChatTableContext::moderatorPromptPieces => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
            ]),
            ...$this->subscribe(GuardianPage::class, [], self::ADMIN_KEY),
            ...$this->subscribe(
                GuardianAgentPage::class,
                [HilosPageRouteParams::HILOS_GUARDIAN_AGENT_AGENT_ID => self::GUARDIAN_AGENT_ID],
                self::ADMIN_KEY,
            ),
        ];

        self::assertStringNotContainsString(HiddenValue::KEY, json_encode(self::payloads($frames), JSON_THROW_ON_ERROR));
    }

    /**
     * Subscribes to a page on behalf of a connection and collects queued WebSocket frames.
     *
     * @param class-string<AbstractPage> $pageClass Page being opened
     * @param array<string, string> $params Route parameters
     * @param string $acceptKey Connection accept key
     * @param array<string, TableWindowDescriptorDTO> $tableWindows Windows the tab holds, by table key
     * @return list<array{name: string, data: SignalDataInterface}> Queued browser frames in delivery order
     */
    private function subscribe(
        string $pageClass,
        array $params = [],
        string $acceptKey = self::ANONYMOUS_KEY,
        array $tableWindows = [],
    ): array {
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        Hilos::$sr->subscribeToPage($pageClass::PAGE, new WebSocketPageSubscribeSignalDTO($acceptKey, $pageClass::PAGE, $params, $tableWindows));
        if ($tableWindows !== []) {
            Hilos::$sr->reportTableWindows($acceptKey, $tableWindows);
        }
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), static function () use ($pageClass, $params, $acceptKey): void {
            new $pageClass(new DemoHilosLegalAgent())->onSubscribe($acceptKey, new PageRouteParams($params));
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
     * Extracts the single row of a declarative browser table from page response payloads.
     *
     * @param list<array{name: string, data: SignalDataInterface}> $frames Frames of one subscription
     * @param string $tableKey Declarative table identifier
     * @return array<string, mixed> The one row of the declarative table
     */
    private function declarativeRow(array $frames, string $tableKey): array
    {
        foreach (self::payloads($frames) as $payload) {
            $rows = $payload[PageResponseSignalData::payload][PagePayload::tables][$tableKey][PagePayload::rows] ?? null;
            if (is_array($rows)) {
                self::assertCount(1, $rows);

                return $rows[0];
            }
        }
        self::fail("No page answer carried the {$tableKey} table");
    }

    /**
     * Extracts the window payload of a viewport table from page response payloads.
     *
     * @param list<array{name: string, data: SignalDataInterface}> $frames Frames of one subscription
     * @param string $tableKey Viewport table whose window is read
     * @return array<string, mixed> The window section of that table
     */
    private function window(array $frames, string $tableKey): array
    {
        foreach (self::payloads($frames) as $payload) {
            $window = $payload[PageResponseSignalData::payload][PagePayload::windows][$tableKey] ?? null;
            if (is_array($window)) {
                return $window;
            }
        }
        self::fail("No page answer carried the window of {$tableKey}");
    }

    /**
     * Extracts wire payload arrays from page response frames.
     *
     * @param list<array{name: string, data: SignalDataInterface}> $frames Frames of one subscription
     * @return list<array<string, mixed>> Wire arrays of the page answers among them
     */
    private static function payloads(array $frames): array
    {
        return array_values(array_map(
            static fn(array $frame): array => $frame['data']->toArray(),
            array_filter($frames, static fn(array $frame): bool => $frame['name'] === SignalTypeConstants::PAGE_RESPONSE),
        ));
    }
}

/**
 * Router answering from the chat demo's topology rather than the framework's bare one.
 */
final class AdminViewModeChatAdminRouter extends SignalRouter
{
    /**
     * @return class-string<Hilos> The chat demo's facade
     */
    protected function hilosClass(): string
    {
        return Hilos::class;
    }
}
