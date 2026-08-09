<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

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
}
