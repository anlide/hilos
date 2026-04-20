<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\ChatSignalMapper;
use Demo\Chat\Tables\TableChatContext;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\DTO\EmitDbChangeSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\EmitFanoutDelivery;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Table\DTO\TableMutationSignalData;
use Hilos\Core\Table\Mutation\TableMutationEntry;
use Hilos\Core\Table\Mutation\TableMutationType;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see ChatSignalMapper}.
 */
final class ChatSignalMapperTest extends TestCase
{
    public function testMapChatUserRowUpdatedProducesSingleAllExceptTableMutation(): void
    {
        $mutation = new TableMutationEntry(TableMutationType::Updated, 7, ['id' => 7, 'name' => 'N']);
        $tableSignal = new TableMutationSignalData(TableChatContext::users, $mutation);
        $emitData = EmitDbChangeSignalData::fromTableMutationSignal(
            entityId: '7',
            signal: $tableSignal,
            excludeAcceptKey: 'key-admin',
            actorUserId: 1,
        );

        $signal = new SignalDTO(
            new SignalSource(SignalSource::AGENT, 'chat', null),
            new SignalType(SignalTypeConstants::EMIT_DB_CHANGE),
            new SignalName(ChatSignalConstants::EMIT_CHAT_USER_ROW_UPDATED),
            $emitData,
        );

        $mapper = new ChatSignalMapper();
        $items = $mapper->mapDbEmit($signal);

        $this->assertCount(1, $items);
        $this->assertSame(EmitFanoutDelivery::AllExcept, $items[0]->delivery);
        $this->assertSame(ChatSignalConstants::TABLE_MUTATION, $items[0]->wireSignalName);
        $this->assertSame('key-admin', $items[0]->excludeAcceptKey);
        $this->assertInstanceOf(TableMutationSignalData::class, $items[0]->innerPayload);
        $this->assertSame(TableChatContext::users, $items[0]->innerPayload->tableKey);
    }

    public function testUnknownEventKeyReturnsEmpty(): void
    {
        $mutation = new TableMutationEntry(TableMutationType::Updated, 1, ['id' => 1]);
        $emitData = EmitDbChangeSignalData::fromTableMutationSignal(
            entityId: '1',
            signal: new TableMutationSignalData(TableChatContext::users, $mutation),
            excludeAcceptKey: null,
        );
        $signal = new SignalDTO(
            new SignalSource(SignalSource::AGENT, 'chat', null),
            new SignalType(SignalTypeConstants::EMIT_DB_CHANGE),
            new SignalName('unknown_emit_event'),
            $emitData,
        );

        $this->assertSame([], (new ChatSignalMapper())->mapDbEmit($signal));
    }
}
