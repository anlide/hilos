<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalData;
use Hilos\Moderation\DTO\ModerationCheckSignalData;
use Hilos\Moderation\DTO\ModerationVerdictSignalData;
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

    /**
     * HIL-1625: the shared moderation frames come back whole from their own payload and from the
     * agent envelope after the JSON trip between processes.
     */
    public function testModerationFramesRoundTripAndCrossTheProcessBoundary(): void
    {
        $check = new ModerationCheckSignalData('hilos_profile_photo', true);
        $verdict = new ModerationVerdictSignalData('agent-ak', 'hilos_profile_photo', 'f00d', false, 'nudity');

        $this->assertSame(['kind' => 'hilos_profile_photo', 'checking' => true], $check->toArray());
        $this->assertSame(
            ['acceptKey' => 'agent-ak', 'kind' => 'hilos_profile_photo', 'requestId' => 'f00d', 'allow' => false, 'reason' => 'nudity'],
            $verdict->toArray(),
        );
        foreach ([$check, $verdict] as $payload) {
            $this->assertEquals($payload, $payload::fromArray($payload->toArray()));

            $wire = json_decode(json_encode(new AgentSignalData($payload, 42)->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            $restored = AgentSignalData::fromArray($wire);

            $this->assertEquals($payload, $restored->data);
            $this->assertSame(42, $restored->receiptId);
        }
    }

    public function testModerationCheckFrameRefusesAFlagNobodyRaised(): void
    {
        $this->expectException(InvalidFormatException::class);
        ModerationCheckSignalData::fromArray(['kind' => 'hilos_profile_photo']);
    }

    public function testModerationVerdictRefusesAMissingRequestId(): void
    {
        $this->expectException(InvalidFormatException::class);
        ModerationVerdictSignalData::fromArray([
            'acceptKey' => 'agent-ak',
            'kind' => 'hilos_profile_photo',
            'allow' => true,
            'reason' => 'ok',
        ]);
    }

    public function testModerationVerdictRefusesAnAllowGivenAsText(): void
    {
        $this->expectException(InvalidFormatException::class);
        ModerationVerdictSignalData::fromArray([
            'acceptKey' => 'agent-ak',
            'kind' => 'hilos_profile_photo',
            'requestId' => 'f00d',
            'allow' => 'true',
            'reason' => 'ok',
        ]);
    }
}
