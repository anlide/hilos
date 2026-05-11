<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\HttpHeaders;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Core\Router\DTO\UserPresenceSignalData;
use Demo\Chat\Projection\ChatProjectionContext;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Projection\SourceChange;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
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

    public function testHandshakeAndCloseUpdatePresenceWithoutHistoryEvents(): void
    {
        RtTruthSourceRegistry::register(RtChatContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$db->events->actions->deleteAll();

        $sessionToken = RandomHelper::hex(16);
        $user = Hilos::$db->users->actions->register($sessionToken);
        $agent = new ChatAgent();

        Hilos::initSignalRouter(new ChatSignalRouter());
        Hilos::initProjection(new ChatProjectionContext());
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

    public function testEveryConnectionCountChangeEmitsPresenceStats(): void
    {
        RtTruthSourceRegistry::register(RtChatContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$db->events->actions->deleteAll();

        $sessionToken = RandomHelper::hex(16);
        $user = Hilos::$db->users->actions->register($sessionToken);
        $agent = new ChatAgent();

        Hilos::initSignalRouter(new ChatSignalRouter());
        Hilos::initProjection(new ChatProjectionContext());
        Hilos::$sr->subscribeToPage(PageConstants::ADMIN_USERS, new WebSocketPageSubscribeSignalDTO(
            'presence-stats-listener-ak',
            PageConstants::ADMIN_USERS,
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
            $this->assertSinglePresenceEmitStatsCount($user->id, 1);

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
            $this->assertSinglePresenceEmitStatsCount($user->id, 2);

            $this->closeConnection($agent, 'presence-ak-2');
            $this->assertSinglePresenceEmitStatsCount($user->id, 1);

            $this->closeConnection($agent, 'presence-ak-1');
            $this->assertSinglePresenceEmitStatsCount($user->id, 0);
            $this->assertNoPresenceEventsInHistory();
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    public function testCloseDeletesSelfConnectionAttachmentDrafts(): void
    {
        RtTruthSourceRegistry::register(RtChatContext::connections, true, self::TEST_AGENT_ID);
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

    public function testConnectionAttachmentDraftsExposeOwnedDrafts(): void
    {
        RtTruthSourceRegistry::register(RtChatContext::connections, true, self::TEST_AGENT_ID);
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
     * @return list<SignalDTO>
     */
    private function drainProjectedPresenceSignals(): array
    {
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            $this->recordProjectionSourceChange($signal);
        }

        Hilos::$projection?->flushToSignalRouter();

        $signals = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            if ($signal->signalName->getName() === ChatSignalConstants::USER_PRESENCE_UPDATE) {
                $signals[] = $signal;
            }
        }

        return $signals;
    }

    private function assertSinglePresenceEmitStatsCount(int $userId, int $onlineSessionCount): void
    {
        $signals = $this->drainProjectedPresenceSignals();
        $this->assertCount(1, $signals);
        $webSocketData = $signals[0]->data;
        $this->assertInstanceOf(WebSocketSignalData::class, $webSocketData);
        $payload = $webSocketData->data;
        $this->assertInstanceOf(UserPresenceSignalData::class, $payload);
        $frontend = $payload->toArray()['frontend'];

        $this->assertSame(
            [['userId' => $userId, 'onlineSessionCount' => $onlineSessionCount]],
            $frontend['updates']['userConnectionStats'],
        );
    }

    private function recordProjectionSourceChange(SignalDTO $signal): void
    {
        $signalType = $signal->signalType->getType();
        $signalData = $signal->data;

        if ($signalType === SignalTypeConstants::RT_SYNC_CREATED && $signalData instanceof RtSyncCreatedSignalData) {
            Hilos::$projection?->record(SourceChange::rtCreated($signalData->collectionKey, $signalData->stateId, $signalData->row));
            return;
        }

        if ($signalType === SignalTypeConstants::RT_SYNC_UPDATED && $signalData instanceof RtSyncUpdatedSignalData) {
            Hilos::$projection?->record(SourceChange::rtUpdated($signalData->collectionKey, $signalData->stateId, $signalData->row));
            return;
        }

        if ($signalType === SignalTypeConstants::RT_SYNC_DELETED && $signalData instanceof RtSyncDeletedSignalData) {
            Hilos::$projection?->record(SourceChange::rtDeleted($signalData->collectionKey, $signalData->stateId, $signalData->row));
        }
    }

    private function assertNoPresenceEventsInHistory(): void
    {
        foreach (Hilos::$db->events as $event) {
            $this->assertNotContains($event->type, ['user_online', 'user_offline']);
        }
    }
}
