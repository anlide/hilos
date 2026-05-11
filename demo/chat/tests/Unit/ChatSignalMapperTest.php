<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalMapper;
use Demo\Chat\Core\Router\DTO\UserPresenceEmitPayload;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Runtime\State\Item\BotAgentStatus as StateBotAgentStatus;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Demo\Chat\Tables\TableChatContext;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Projection\SourceChange;
use Hilos\Core\Router\DTO\EmitDbChangeSignalData;
use Hilos\Core\Router\DTO\EmitRtChangeSignalData;
use Hilos\Core\Router\DTO\FrontendChangesDTO;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\EmitFanoutDelivery;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Row\GenericTableRow;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see ChatSignalMapper}.
 */
final class ChatSignalMapperTest extends TestCase
{
    public function testMapChatUserRowUpdatedBuildsPendingMutationsFromRouterTableRoutes(): void
    {
        $sourceChange = SourceChange::dbUpdated(DbChatContext::users, '7', []);
        $emitData = new EmitDbChangeSignalData(
            sourceChange: $sourceChange,
            excludeAcceptKey: 'key-admin',
            actorUserId: 1,
        );

        $signal = new SignalDTO(
            new SignalSource(SignalSource::AGENT, 'chat', null),
            new SignalType(SignalTypeConstants::EMIT_DB_CHANGE),
            new SignalName(ChatSignalConstants::EMIT_CHAT_USER_ROW_UPDATED),
            $emitData,
        );

        $items = (new ChatSignalMapper($this->makeRouter(), $this->makeTableContext()))->mapDbEmit($signal);

        $this->assertCount(2, $items);
        $this->assertSame(EmitFanoutDelivery::AllExcept, $items[0]->delivery);
        $this->assertSame(ChatSignalConstants::TABLE_MUTATION_PENDING, $items[0]->wireSignalName);
        $this->assertSame('key-admin', $items[0]->excludeAcceptKey);
        $this->assertSame(TableChatContext::adminUsers, $items[0]->innerPayload->tableKey);
        $this->assertSame(TableChatContext::hilosUsers, $items[1]->innerPayload->tableKey);
        $this->assertArrayNotHasKey('excludeAcceptKey', $items[0]->innerPayload->toArray());
        $this->assertArrayNotHasKey('targetAcceptKey', $items[1]->innerPayload->toArray());
    }

    public function testUnknownEventKeyReturnsEmpty(): void
    {
        $signal = new SignalDTO(
            new SignalSource(SignalSource::AGENT, 'chat', null),
            new SignalType(SignalTypeConstants::EMIT_DB_CHANGE),
            new SignalName('unknown_emit_event'),
            new EmitDbChangeSignalData(
                SourceChange::dbUpdated(DbChatContext::users, '1', []),
            ),
        );

        $this->assertSame([], (new ChatSignalMapper($this->makeRouter(), $this->makeTableContext()))->mapDbEmit($signal));
    }

    public function testMapConfiguredBotTableEventBuildsPendingMutation(): void
    {
        $signal = new SignalDTO(
            new SignalSource(SignalSource::AGENT, 'chat', null),
            new SignalType(SignalTypeConstants::EMIT_DB_CHANGE),
            new SignalName(ChatSignalConstants::EMIT_CHAT_BOT_ROW_CHANGED),
            new EmitDbChangeSignalData(
                sourceChange: SourceChange::dbUpdated(DbChatContext::bots, '5', []),
                excludeAcceptKey: 'admin-ak',
            ),
        );

        $items = (new ChatSignalMapper($this->makeRouter(), $this->makeTableContext()))->mapDbEmit($signal);

        $this->assertCount(1, $items);
        $this->assertSame(EmitFanoutDelivery::AllExcept, $items[0]->delivery);
        $this->assertSame(ChatSignalConstants::TABLE_MUTATION_PENDING, $items[0]->wireSignalName);
        $this->assertSame('admin-ak', $items[0]->excludeAcceptKey);
        $this->assertSame(TableChatContext::bots, $items[0]->innerPayload->tableKey);
    }

    public function testMapChatUserPresenceUpdatedBuildsPageScopedFanout(): void
    {
        $router = $this->makeRouter();
        $router->subscribeToPage(PageConstants::MAIN, new WebSocketPageSubscribeSignalDTO(
            'main-ak',
            PageConstants::MAIN,
            [],
        ));
        $router->subscribeToPage(PageConstants::ADMIN_USERS, new WebSocketPageSubscribeSignalDTO(
            'admin-ak',
            PageConstants::ADMIN_USERS,
            [],
        ));
        $router->subscribeToPage(PageConstants::ADMIN_USERS, new WebSocketPageSubscribeSignalDTO(
            'excluded-ak',
            PageConstants::ADMIN_USERS,
            [],
        ));
        $router->subscribeToPage(HilosPageConstants::HILOS_USERS, new WebSocketPageSubscribeSignalDTO(
            'hilos-users-ak',
            HilosPageConstants::HILOS_USERS,
            [],
        ));
        $router->subscribeToPage(HilosPageConstants::HILOS_USER, new WebSocketPageSubscribeSignalDTO(
            'hilos-user-7-ak',
            HilosPageConstants::HILOS_USER,
            [HilosPageRouteParams::HILOS_USER_USER_ID => '7'],
        ));
        $router->subscribeToPage(HilosPageConstants::HILOS_USER, new WebSocketPageSubscribeSignalDTO(
            'hilos-user-8-ak',
            HilosPageConstants::HILOS_USER,
            [HilosPageRouteParams::HILOS_USER_USER_ID => '8'],
        ));

        $signal = new SignalDTO(
            new SignalSource(SignalSource::AGENT, 'chat', null),
            new SignalType(SignalTypeConstants::EMIT_RT_CHANGE),
            new SignalName(ChatSignalConstants::EMIT_CHAT_USER_PRESENCE_UPDATED),
            new EmitRtChangeSignalData(
                collectionKey: RtChatContext::connections,
                stateId: '7',
                payload: UserPresenceEmitPayload::fromFrontendChanges(
                    7,
                    new FrontendChangesDTO(updates: [
                        'userPresence' => [['userId' => 7, 'presence' => 'online']],
                    ]),
                    new FrontendChangesDTO(updates: [
                        'userPresence' => [['userId' => 7, 'presence' => 'online']],
                        'userConnectionStats' => [['userId' => 7, 'onlineSessionCount' => 2]],
                    ]),
                )->toArray(),
                excludeAcceptKey: 'excluded-ak',
            ),
        );

        $items = (new ChatSignalMapper($router, $this->makeTableContext()))->mapRtEmit($signal);

        $this->assertCount(4, $items);
        $this->assertSame(
            ['main-ak', 'admin-ak', 'hilos-users-ak', 'hilos-user-7-ak'],
            array_map(static fn ($item) => $item->targetAcceptKey, $items),
        );
        foreach ($items as $item) {
            $this->assertSame(EmitFanoutDelivery::Single, $item->delivery);
            $this->assertSame(ChatSignalConstants::USER_PRESENCE_UPDATE, $item->wireSignalName);
        }

        $this->assertArrayNotHasKey(
            'userConnectionStats',
            $items[0]->innerPayload->toArray()['frontend']['updates'],
        );
        $this->assertSame(
            [['userId' => 7, 'onlineSessionCount' => 2]],
            $items[1]->innerPayload->toArray()['frontend']['updates']['userConnectionStats'],
        );
        $this->assertSame(
            [['userId' => 7, 'onlineSessionCount' => 2]],
            $items[3]->innerPayload->toArray()['frontend']['updates']['userConnectionStats'],
        );
    }

    public function testMapBotAgentStatusUpdatedBuildsLifecycleFanout(): void
    {
        $signal = new SignalDTO(
            new SignalSource(SignalSource::AGENT, 'chat', null),
            new SignalType(SignalTypeConstants::EMIT_RT_CHANGE),
            new SignalName(ChatSignalConstants::EMIT_CHAT_BOT_AGENT_STATUS_UPDATED),
            new EmitRtChangeSignalData(
                collectionKey: RtChatContext::botAgentStatuses,
                stateId: '9',
                payload: [
                    StateBotAgentStatus::botId => 9,
                    StateBotAgentStatus::status => StateBotAgentStatus::STATUS_JOINED,
                ],
            ),
        );

        $items = (new ChatSignalMapper($this->makeRouter(), $this->makeTableContext()))->mapRtEmit($signal);

        $this->assertCount(1, $items);
        $this->assertSame(EmitFanoutDelivery::AllExcept, $items[0]->delivery);
        $this->assertSame(ChatSignalConstants::BOT_JOINED, $items[0]->wireSignalName);
        $this->assertSame(
            [['botId' => 9, 'presence' => 'online']],
            $items[0]->innerPayload->toArray()['frontend']['updates']['botPresence'],
        );
    }

    public function testMapGuardianAgentStatusUpdatedBuildsGuardianFanout(): void
    {
        $signal = new SignalDTO(
            new SignalSource(SignalSource::AGENT, 'hilos_guardian', null),
            new SignalType(SignalTypeConstants::EMIT_RT_CHANGE),
            new SignalName(HilosSignalConstants::EMIT_HILOS_GUARDIAN_AGENT_STATUS_UPDATED),
            new EmitRtChangeSignalData(
                collectionKey: RtChatContext::guardianAgentStatuses,
                stateId: 'code_quality',
                payload: [
                    'agentId' => 'code_quality',
                    'status' => 'done',
                ],
            ),
        );

        $items = (new ChatSignalMapper($this->makeRouter(), $this->makeTableContext()))->mapRtEmit($signal);

        $this->assertCount(1, $items);
        $this->assertSame(EmitFanoutDelivery::AllExcept, $items[0]->delivery);
        $this->assertSame(HilosSignalConstants::GUARDIAN_AGENT_STATUS_UPDATE, $items[0]->wireSignalName);
        $this->assertSame(
            ['agentId' => 'code_quality', 'status' => 'done'],
            $items[0]->innerPayload->toArray(),
        );
    }

    private function makeRouter(): SignalRouter
    {
        return new class extends SignalRouter {
            public function __construct()
            {
                parent::__construct();

                $this->config = [
                    'table_event_routes' => [
                        ChatSignalConstants::EMIT_CHAT_USER_ROW_UPDATED => [
                            TableChatContext::adminUsers,
                            TableChatContext::hilosUsers,
                        ],
                        ChatSignalConstants::EMIT_CHAT_BOT_ROW_CHANGED => [
                            TableChatContext::bots,
                        ],
                    ],
                ];
            }
        };
    }

    private function makeTableContext(): TableContext
    {
        $context = new class(
            $this->makeTable('admin'),
            $this->makeTable('hilos'),
            $this->makeTable('bots', DbChatContext::bots),
        ) extends TableContext {
            public function __construct(
                private readonly TableDefinition $adminUsers,
                private readonly TableDefinition $hilosUsers,
                private readonly TableDefinition $bots,
            ) {
            }

            public function configure(): void
            {
                $this->register(TableChatContext::adminUsers, $this->adminUsers);
                $this->register(TableChatContext::hilosUsers, $this->hilosUsers);
                $this->register(TableChatContext::bots, $this->bots);
            }
        };
        $context->configure();

        return $context;
    }

    private function makeTable(string $label, string $sourceKey = DbChatContext::users): TableDefinition
    {
        return new class($label, $sourceKey) extends TableDefinition {
            public function __construct(
                private readonly string $label,
                private readonly string $sourceKey,
            ) {
                parent::__construct();
            }

            public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
            {
                if ($change->sourceKey !== $this->sourceKey) {
                    return null;
                }

                return new TableRowMutationDTO(
                    $change->mutationType,
                    $change->sourceId,
                    GenericTableRow::fromArray([
                        'id' => (int) $change->sourceId,
                        'label' => $this->label,
                    ]),
                );
            }

            protected function query(TableQueryDTO $query): TableSnapshotDTO
            {
                return new TableSnapshotDTO();
            }
        };
    }
}
