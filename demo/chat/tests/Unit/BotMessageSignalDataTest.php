<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Core\Router\DTO\BotMessageSignalData;
use Hilos\Core\Router\SignalDataInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for bot message signal transport.
 */
final class BotMessageSignalDataTest extends TestCase
{
    public function testImplementsSignalDataInterface(): void
    {
        $data = new BotMessageSignalData(botId: 42, message: 'hello');

        $this->assertInstanceOf(SignalDataInterface::class, $data);
    }

    public function testRoundtripPreservesPayload(): void
    {
        $data = new BotMessageSignalData(botId: 42, message: 'hello');

        $restored = BotMessageSignalData::fromArray($data->toArray());

        $this->assertSame(42, $restored->botId);
        $this->assertSame('hello', $restored->message);
        $this->assertSame($data->toArray(), $restored->toArray());
    }
}
