<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Files\Upload;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Files\Upload\DTO\UploadHoldSettleSignalData;
use Hilos\Files\Upload\UploadHoldOutcome;
use Hilos\Files\Upload\UploadsAgent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Internal settlement stays typed across IPC and never becomes a browser action. */
final class UploadHoldSettleTest extends TestCase
{
    public function testBothOutcomesRoundTripThroughTheAgentEnvelope(): void
    {
        foreach (UploadHoldOutcome::cases() as $outcome) {
            $payload = new UploadHoldSettleSignalData('connection', 'first-request', $outcome);
            self::assertSame([
                'acceptKey' => 'connection', 'uploadHoldId' => 'first-request', 'outcome' => $outcome->value,
            ], $payload->toArray());
            $envelope = new AgentSignalData($payload);
            self::assertEquals($envelope, AgentSignalData::fromJson($envelope->toJson()));
        }
        self::assertSame(UploadHoldSettleSignalData::class, UploadsAgent::AGENT_SIGNALS[HilosSignalConstants::HILOS_UPLOAD_HOLD_SETTLE]);
        self::assertArrayNotHasKey(HilosSignalConstants::HILOS_UPLOAD_HOLD_SETTLE, UploadsAgent::AGENT_ACTIONS);
    }

    /** @return iterable<string, array{array<string, mixed>}> Missing, mistyped and unknown required values */
    public static function malformedPayloads(): iterable
    {
        $valid = ['acceptKey' => 'connection', 'uploadHoldId' => 'token', 'outcome' => 'approved'];
        foreach (array_keys($valid) as $key) {
            yield 'missing ' . $key => [array_diff_key($valid, [$key => true])];
            yield 'null ' . $key => [[$key => null] + $valid];
            yield 'integer ' . $key => [[$key => 1] + $valid];
            yield 'empty ' . $key => [[$key => ''] + $valid];
        }
        yield 'unknown outcome' => [['outcome' => 'allow'] + $valid];
    }

    /** @param array<string, mixed> $payload Invalid internal settlement */
    #[DataProvider('malformedPayloads')]
    public function testMalformedSettlementIsRefused(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        UploadHoldSettleSignalData::fromArray($payload);
    }
}
