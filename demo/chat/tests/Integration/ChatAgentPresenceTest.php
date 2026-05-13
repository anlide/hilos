<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Browser\ChatBrowserTable;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\HttpHeaders;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Projection\ChatProjectionContext;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Projection\SourceChange;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration tests for ChatAgent runtime presence handling.
 */
final class ChatAgentPresenceTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    /**
     * Verifies handshake and close update runtime presence without writing history events.
     *
     * @throws HilosException When test setup or agent signal handling fails
     */
    public function testHandshakeAndCloseUpdatePresenceWithoutHistoryEvents(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$db->events->actions->deleteAll();

        $sessionToken = RandomHelper::hex(16);
        $user = Hilos::$db->users->actions->register($sessionToken);
        $agent = new ChatAgent();

        Hilos::initSignalRouter(new ChatSignalRouter());
        Hilos::initProjection(new ChatProjectionContext());
        Hilos::initBrowser();
        Hilos::$sr->subscribeToPage(PageConstants::MAIN, new WebSocketPageSubscribeSignalDTO(
            'presence-listener-ak',
            PageConstants::MAIN,
            [],
        ));

        try {
            $eventCountBeforeHandshake = count(Hilos::$db->events);

            $agent->onSignalHandshake(
                new WebSocketHandshakeSignalDTO(
                    headers: [],
                    acceptKey: 'presence-ak-1',
                    cookies: [],
                    clientIp: '127.0.0.1',
                    queryParams: new RequestQueryParams([HttpHeaders::SESSION_TOKEN => $sessionToken]),
                ),
                '',
                '',
            );

            $this->assertSame($eventCountBeforeHandshake, count(Hilos::$db->events));
            $this->assertSame(1, count(Hilos::$rt->connections->forUser($user->id)));
            $this->assertNoPresenceEventsInHistory();
            $this->assertContains(SignalConstants::RT_SYNC_CREATED, $this->drainQueuedSignalNames());

            $eventCountBeforeClose = count(Hilos::$db->events);
            Hilos::initSignalRouter(new ChatSignalRouter());
            Hilos::initProjection(new ChatProjectionContext());
            Hilos::initBrowser();
            Hilos::$sr->subscribeToPage(PageConstants::MAIN, new WebSocketPageSubscribeSignalDTO(
                'presence-listener-ak',
                PageConstants::MAIN,
                [],
            ));

            $this->closeConnection($agent, 'presence-ak-1');

            $this->assertSame($eventCountBeforeClose, count(Hilos::$db->events));
            $this->assertSame(0, count(Hilos::$rt->connections->forUser($user->id)));
            $this->assertNoPresenceEventsInHistory();
            $this->assertContains(SignalConstants::RT_SYNC_DELETED, $this->drainQueuedSignalNames());
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * Verifies connection changes update main browser user rows without legacy presence delivery.
     *
     * @throws HilosException When test setup or agent signal handling fails
     */
    public function testConnectionChangesEmitBrowserMainUsersWithoutLegacyPresence(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$db->events->actions->deleteAll();

        $sessionToken = RandomHelper::hex(16);
        $user = Hilos::$db->users->actions->register($sessionToken);
        $agent = new ChatAgent();

        Hilos::initSignalRouter(new ChatSignalRouter());
        Hilos::initProjection(new ChatProjectionContext());
        Hilos::initBrowser();
        Hilos::$sr->subscribeToPage(PageConstants::MAIN, new WebSocketPageSubscribeSignalDTO(
            'presence-main-listener-ak',
            PageConstants::MAIN,
            [],
        ));
        Hilos::$sr->subscribeToPage(PageConstants::ADMIN_USERS, new WebSocketPageSubscribeSignalDTO(
            'presence-stats-listener-ak',
            PageConstants::ADMIN_USERS,
            [],
        ));
        Hilos::$sr->subscribeToPage(PageConstants::HILOS_USERS, new WebSocketPageSubscribeSignalDTO(
            'hilos-presence-stats-listener-ak',
            PageConstants::HILOS_USERS,
            [],
        ));

        try {
            $agent->onSignalHandshake(
                new WebSocketHandshakeSignalDTO(
                    headers: [],
                    acceptKey: 'presence-ak-1',
                    cookies: [],
                    clientIp: '127.0.0.1',
                    queryParams: new RequestQueryParams([HttpHeaders::SESSION_TOKEN => $sessionToken]),
                ),
                '',
                '',
            );
            $this->assertSingleMainUserBrowserUpdate($user->id, 'online', 1);

            $agent->onSignalHandshake(
                new WebSocketHandshakeSignalDTO(
                    headers: [],
                    acceptKey: 'presence-ak-2',
                    cookies: [],
                    clientIp: '127.0.0.1',
                    queryParams: new RequestQueryParams([HttpHeaders::SESSION_TOKEN => $sessionToken]),
                ),
                '',
                '',
            );
            $this->assertSingleMainUserBrowserUpdate($user->id, 'online', 2);

            $this->closeConnection($agent, 'presence-ak-2');
            $this->assertSingleMainUserBrowserUpdate($user->id, 'online', 1);

            $this->closeConnection($agent, 'presence-ak-1');
            $this->assertSingleMainUserBrowserUpdate($user->id, 'offline', 0);
            $this->assertNoPresenceEventsInHistory();
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * Verifies closing a connection deletes only that connection's attachment drafts.
     *
     * @throws HilosException When runtime setup or connection close handling fails
     */
    public function testCloseDeletesSelfConnectionAttachmentDrafts(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->attachmentDrafts->actions->clear(deleteFiles: false);

        try {
            Hilos::$rt->connections->actions->register('closed-ak', 1);
            Hilos::$rt->attachmentDrafts->actions->create(
                'closed-draft',
                'closed-ak',
                1,
                '',
                'closed.txt',
                'text/plain',
                1,
                'closed.txt',
                time(),
            );
            Hilos::$rt->attachmentDrafts->actions->create(
                'other-draft',
                'other-ak',
                1,
                '',
                'other.txt',
                'text/plain',
                1,
                'other.txt',
                time(),
            );

            $this->closeConnection(new ChatAgent(), 'closed-ak');

            $this->assertNull(Hilos::$rt->connections['closed-ak']);
            $this->assertSame(0, count(Hilos::$rt->attachmentDrafts->forAcceptKey('closed-ak')));
            $this->assertSame(1, count(Hilos::$rt->attachmentDrafts->forAcceptKey('other-ak')));
        } finally {
            Hilos::$rt->connections->actions->clear();
            Hilos::$rt->attachmentDrafts->actions->clear(deleteFiles: false);
        }
    }

    /**
     * Verifies a connection exposes only its own attachment drafts.
     *
     * @throws HilosException When runtime setup fails
     */
    public function testConnectionAttachmentDraftsExposeOwnedDrafts(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->attachmentDrafts->actions->clear(deleteFiles: false);

        try {
            Hilos::$rt->connections->actions->register('owner-ak', 1);
            Hilos::$rt->connections->actions->register('other-ak', 1);
            Hilos::$rt->attachmentDrafts->actions->create(
                'draft-a',
                'owner-ak',
                1,
                '',
                'a.txt',
                'text/plain',
                1,
                'a.txt',
                time(),
            );
            Hilos::$rt->attachmentDrafts->actions->create(
                'draft-b',
                'owner-ak',
                1,
                '',
                'b.txt',
                'text/plain',
                1,
                'b.txt',
                time(),
            );
            Hilos::$rt->attachmentDrafts->actions->create(
                'draft-c',
                'other-ak',
                1,
                '',
                'c.txt',
                'text/plain',
                1,
                'c.txt',
                time(),
            );

            $connectionDraftIds = [];
            foreach (Hilos::$rt->connections['owner-ak']->attachmentDrafts as $draft) {
                $connectionDraftIds[] = $draft->draftId;
            }

            $this->assertSame(['draft-a', 'draft-b'], $connectionDraftIds);
        } finally {
            Hilos::$rt->connections->actions->clear();
            Hilos::$rt->attachmentDrafts->actions->clear(deleteFiles: false);
        }
    }

    /**
     * Drains queued signals and returns only their signal names.
     *
     * @return list<string>
     */
    private function drainQueuedSignalNames(): array
    {
        $names = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            $names[] = $signal->signalName->getName();
        }

        return $names;
    }

    /**
     * Runs a close signal inside the connection execution context.
     *
     * @param ChatAgent $agent Agent that owns the connection close handler
     * @param string $acceptKey Accept key to expose as the current connection
     * @throws HilosException When connection cleanup fails
     */
    private function closeConnection(ChatAgent $agent, string $acceptKey): void
    {
        ExecutionContext::run(
            new ExecutionFrame(acceptKey: $acceptKey),
            static function () use ($agent, $acceptKey): void {
                $agent->onSignalConnectionClose(new WebSocketCloseSignalDTO($acceptKey), '', '');
            },
        );
    }

    /**
     * Drains queued sync signals through browser/projection and returns main browser page signals.
     *
     * @return list<SignalDTO>
     */
    private function drainProjectedMainBrowserSignals(): array
    {
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            $this->recordFrontendSourceChange($signal);
        }

        Hilos::$projection?->flushToSignalRouter();
        Hilos::$browser?->flushToSignalRouter();

        $signals = [];
        $legacyPresenceSignals = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            if ($signal->signalName->getName() === ChatSignalConstants::SUBSCRIPTION_PAGE_MAIN) {
                $signals[] = $signal;
                continue;
            }
            if ($signal->signalName->getName() === ChatSignalConstants::USER_PRESENCE_UPDATE) {
                $legacyPresenceSignals[] = $signal;
            }
        }

        $this->assertSame([], $legacyPresenceSignals);

        return $signals;
    }

    /**
     * Asserts that one projected browser row update is delivered to the main page.
     *
     * @param int $userId User id expected in the presence update
     * @param string $presence Expected projected presence value
     * @param int $onlineSessionCount Expected projected online session count
     */
    private function assertSingleMainUserBrowserUpdate(
        int $userId,
        string $presence,
        int $onlineSessionCount,
    ): void
    {
        $signals = $this->drainProjectedMainBrowserSignals();
        $this->assertCount(1, $signals);
        $webSocketData = $signals[0]->data;
        $this->assertInstanceOf(WebSocketSignalData::class, $webSocketData);
        $this->assertSame('presence-main-listener-ak', $webSocketData->targetAcceptKey);
        $payload = $webSocketData->data;
        $this->assertInstanceOf(BrowserPageSignalData::class, $payload);
        $rows = $payload->toArray()[BrowserPageSignalData::tables][ChatBrowserTable::MAIN_USERS][BrowserPageSignalData::rows] ?? [];
        $row = $this->findMainUserBrowserRow($rows, $userId);

        $this->assertIsArray($row);
        $connection = $row[BrowserPageSignalData::sources][ChatRtContext::connections] ?? null;
        if ($onlineSessionCount === 0) {
            $this->assertNull($connection);
            return;
        }

        $this->assertIsArray($connection);
        $this->assertSame($presence, $connection['presence'] ?? null);
        $this->assertSame($onlineSessionCount, $connection['onlineSessionCount'] ?? null);
        $this->assertArrayNotHasKey('acceptKey', $connection);
    }

    /**
     * Finds a main browser user row by DB user id.
     *
     * @param list<array<string, mixed>> $rows Main browser user rows
     * @param int $userId User id to find
     * @return ?array<string, mixed> Matching row, or null
     */
    private function findMainUserBrowserRow(array $rows, int $userId): ?array
    {
        foreach ($rows as $row) {
            $user = $row[BrowserPageSignalData::sources][ChatDbContext::users] ?? null;
            if (is_array($user) && ($user['id'] ?? null) === $userId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Records one queued DB/RT sync signal as a projection source change.
     *
     * @param SignalDTO $signal Queued sync signal to inspect
     */
    private function recordFrontendSourceChange(SignalDTO $signal): void
    {
        $signalType = $signal->signalType->getType();
        $signalData = $signal->data;

        if ($signalType === SignalTypeConstants::RT_SYNC_CREATED && $signalData instanceof RtSyncCreatedSignalData) {
            $change = SourceChange::rtCreated($signalData->collectionKey, $signalData->stateId, $signalData->row);
            Hilos::$projection?->record($change);
            Hilos::$browser?->record($change);
            return;
        }

        if ($signalType === SignalTypeConstants::RT_SYNC_UPDATED && $signalData instanceof RtSyncUpdatedSignalData) {
            $change = SourceChange::rtUpdated($signalData->collectionKey, $signalData->stateId, $signalData->row);
            Hilos::$projection?->record($change);
            Hilos::$browser?->record($change);
            return;
        }

        if ($signalType === SignalTypeConstants::RT_SYNC_DELETED && $signalData instanceof RtSyncDeletedSignalData) {
            $change = SourceChange::rtDeleted($signalData->collectionKey, $signalData->stateId, $signalData->row);
            Hilos::$projection?->record($change);
            Hilos::$browser?->record($change);
        }
    }

    /**
     * Asserts that presence-only lifecycle changes did not create history events.
     */
    private function assertNoPresenceEventsInHistory(): void
    {
        foreach (Hilos::$db->events as $event) {
            $this->assertNotContains($event->type, ['user_online', 'user_offline']);
        }
    }
}
