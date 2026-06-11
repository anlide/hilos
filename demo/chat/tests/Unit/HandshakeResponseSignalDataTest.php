<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Core\Router\SignalDataInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for handshake response transport.
 */
final class HandshakeResponseSignalDataTest extends TestCase
{
    public function testImplementsSignalDataInterface(): void
    {
        $data = new HandshakeResponseSignalData(selfId: 7, selfName: 'User 7');

        $this->assertInstanceOf(SignalDataInterface::class, $data);
    }

    public function testPayloadCarriesCurrentUserEntityFragment(): void
    {
        $data = new HandshakeResponseSignalData(selfId: 7, selfName: 'User 7');

        $this->assertSame(
            [
                'entities' => [
                    'currentUser' => [
                        'id' => 7,
                        'name' => 'User 7',
                    ],
                ],
            ],
            $data->toArray(),
        );
    }

    public function testRoundtripPreservesPayload(): void
    {
        $data = new HandshakeResponseSignalData(selfId: 7, selfName: 'User 7');

        $restored = HandshakeResponseSignalData::fromArray($data->toArray());

        $this->assertSame(7, $restored->selfId);
        $this->assertSame('User 7', $restored->selfName);
        $this->assertSame($data->toArray(), $restored->toArray());
    }
}
