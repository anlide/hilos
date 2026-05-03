<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\HttpHeaders;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Core\Router\DTO\UserPresenceSignalData;
use Demo\Chat\Frontend\ChatFrontendProjection;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Frontend\SourceChange;
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
        Hilos::$frontend = new ChatFrontendProjection();
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
            Hilos::$frontend = new ChatFrontendProjection();
            Hilos::$sr->subscribeToPage(PageConstants::MAIN, new WebSocketPageSubscribeSignalDTO(
                'presence-listener-ak',
                PageConstants::MAIN,
                [],
            ));

            $agent->onSignalConnectionClose(new WebSocketCloseSignalDTO('presence-ak-1'), '', '');

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
        Hilos::$frontend = new ChatFrontendProjection();
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

            $agent->onSignalConnectionClose(new WebSocketCloseSignalDTO('presence-ak-2'), '', '');
            $this->assertSinglePresenceEmitStatsCount($user->id, 1);

            $agent->onSignalConnectionClose(new WebSocketCloseSignalDTO('presence-ak-1'), '', '');
            $this->assertSinglePresenceEmitStatsCount($user->id, 0);
            $this->assertNoPresenceEventsInHistory();
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    public function testCloseDeletesAttachmentDraftsWhenConnectionAlreadyMissing(): void
    {
        Hilos::$rt->attachmentDrafts->actions->clear(deleteFiles: false);

        try {
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

            (new ChatAgent())->onSignalConnectionClose(new WebSocketCloseSignalDTO('closed-ak'), '', '');

            $this->assertSame(0, count(Hilos::$rt->attachmentDrafts->forAcceptKey('closed-ak')));
            $this->assertSame(1, count(Hilos::$rt->attachmentDrafts->forAcceptKey('other-ak')));
        } finally {
            Hilos::$rt->attachmentDrafts->actions->clear(deleteFiles: false);
        }
    }

    public function testAttachmentDraftFiltersCanChainAcceptKeyAndDraftIds(): void
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

            $draftIds = [];
            foreach (
                Hilos::$rt->attachmentDrafts->forAcceptKey('owner-ak')->forDraftIds(
                    ['draft-b', 'draft-c', 'draft-a'],
                ) as $draft
            ) {
                $draftIds[] = $draft->draftId;
            }

            $this->assertSame(['draft-a', 'draft-b'], $connectionDraftIds);
            $this->assertSame(['draft-b', 'draft-a'], $draftIds);
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

    /**
     * @return list<SignalDTO>
     */
    private function drainProjectedPresenceSignals(): array
    {
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            $this->recordProjectionSourceChange($signal);
        }

        Hilos::$frontend?->flushToSignalRouter();

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
            Hilos::$frontend?->record(SourceChange::rtCreated($signalData->collectionKey, $signalData->stateId, $signalData->row));
            return;
        }

        if ($signalType === SignalTypeConstants::RT_SYNC_UPDATED && $signalData instanceof RtSyncUpdatedSignalData) {
            Hilos::$frontend?->record(SourceChange::rtUpdated($signalData->collectionKey, $signalData->stateId, $signalData->row));
            return;
        }

        if ($signalType === SignalTypeConstants::RT_SYNC_DELETED && $signalData instanceof RtSyncDeletedSignalData) {
            Hilos::$frontend?->record(SourceChange::rtDeleted($signalData->collectionKey, $signalData->stateId, $signalData->row));
        }
    }

    private function assertNoPresenceEventsInHistory(): void
    {
        foreach (Hilos::$db->events as $event) {
            $this->assertNotContains($event->type, ['user_online', 'user_offline']);
        }
    }
}
