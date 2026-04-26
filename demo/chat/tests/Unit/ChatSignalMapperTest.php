<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\ChatSignalMapper;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Tables\TableChatContext;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\DTO\EmitDbChangeSignalData;
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
use Hilos\Core\Table\DTO\TableSourceEventDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\GenericTableRow;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see ChatSignalMapper}.
 */
final class ChatSignalMapperTest extends TestCase
{
    public function testMapChatUserRowUpdatedBuildsPendingMutationsFromRouterTableRoutes(): void
    {
        $sourceEvent = new TableSourceEventDTO(
            sourceKey: DbChatContext::users,
            sourceRowKey: 7,
            mutationType: TableMutationType::Update,
        );
        $emitData = new EmitDbChangeSignalData(
            sourceEvent: $sourceEvent,
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
                new TableSourceEventDTO(DbChatContext::users, 1, TableMutationType::Update),
            ),
        );

        $this->assertSame([], (new ChatSignalMapper($this->makeRouter(), $this->makeTableContext()))->mapDbEmit($signal));
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
                    ],
                ];
            }
        };
    }

    private function makeTableContext(): TableContext
    {
        $context = new class($this->makeTable('admin'), $this->makeTable('hilos')) extends TableContext {
            public function __construct(
                private readonly TableDefinition $adminUsers,
                private readonly TableDefinition $hilosUsers,
            ) {
            }

            public function configure(): void
            {
                $this->register(TableChatContext::adminUsers, $this->adminUsers);
                $this->register(TableChatContext::hilosUsers, $this->hilosUsers);
            }
        };
        $context->configure();

        return $context;
    }

    private function makeTable(string $label): TableDefinition
    {
        return new class($label) extends TableDefinition {
            public function __construct(private readonly string $label)
            {
                parent::__construct();
            }

            public function buildMutationForSourceEvent(TableSourceEventDTO $event): ?TableRowMutationDTO
            {
                if ($event->sourceKey !== DbChatContext::users) {
                    return null;
                }

                return new TableRowMutationDTO(
                    $event->mutationType,
                    $event->sourceRowKey,
                    GenericTableRow::fromArray([
                        'id' => (int) $event->sourceRowKey,
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
