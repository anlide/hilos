<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for agent signal wrapper metadata.
 */
final class AgentSignalDataTest extends TestCase
{
    public function testDelegatesAcceptKeyFromInnerPayload(): void
    {
        $data = new AgentSignalData(new WebSocketFrameSignalDTO('agent-ak', 'payload'));

        $this->assertSame('agent-ak', $data->getAcceptKey());
    }

    public function testRoundtripPreservesDelegatedAcceptKey(): void
    {
        $data = new AgentSignalData(new WebSocketFrameSignalDTO('agent-ak', 'payload'));

        $restored = AgentSignalData::fromArray($data->toArray());

        $this->assertSame('agent-ak', $restored->getAcceptKey());
    }

    public function testReturnsNoAcceptKeyWhenInnerPayloadHasNone(): void
    {
        $data = new AgentSignalData(new SignalData(['message' => 'ok']));

        $this->assertNull($data->getAcceptKey());
    }

    public function testRoundtripPreservesReceiptAndOmitsAbsentReceipt(): void
    {
        $withReceipt = new AgentSignalData(new SignalData(['message' => 'ok']), 42);
        $withoutReceipt = new AgentSignalData(new SignalData(['message' => 'ok']));

        $this->assertSame(42, $withReceipt->toArray()[SignalPayloadConstants::FIELD_RECEIPT]);
        $this->assertSame(42, AgentSignalData::fromArray($withReceipt->toArray())->receiptId);
        $this->assertArrayNotHasKey(SignalPayloadConstants::FIELD_RECEIPT, $withoutReceipt->toArray());
        $this->assertNull(AgentSignalData::fromArray($withoutReceipt->toArray())->receiptId);
    }

    public function testRejectsNonPositiveOrNonIntegerReceipt(): void
    {
        $envelope = new AgentSignalData(new SignalData(['message' => 'ok']))->toArray();

        foreach ([0, '5'] as $receipt) {
            $envelope[SignalPayloadConstants::FIELD_RECEIPT] = $receipt;
            $this->assertNull(AgentSignalData::fromArray($envelope)->receiptId);
        }
    }
}
