<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Tests\Unit;

use Demo\SimpleTodo\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Core\Router\SignalDataInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for handshake response transport.
 */
final class HandshakeResponseSignalDataTest extends TestCase
{
    public function testImplementsSignalDataInterface(): void
    {
        $data = new HandshakeResponseSignalData(selfId: 7, selfName: 'User 7', selfAdmin: false);

        $this->assertInstanceOf(SignalDataInterface::class, $data);
    }

    public function testPayloadCarriesCurrentUserEntityFragment(): void
    {
        $data = new HandshakeResponseSignalData(selfId: 7, selfName: 'User 7', selfAdmin: false);

        $this->assertSame(
            [
                'entities' => [
                    'currentUser' => [
                        'id' => 7,
                        'name' => 'User 7',
                        'admin' => false,
                    ],
                ],
            ],
            $data->toArray(),
        );
    }

    public function testRoundtripPreservesPayload(): void
    {
        $data = new HandshakeResponseSignalData(selfId: 7, selfName: 'User 7', selfAdmin: false);

        $restored = HandshakeResponseSignalData::fromArray($data->toArray());

        $this->assertSame(7, $restored->selfId);
        $this->assertSame('User 7', $restored->selfName);
        $this->assertFalse($restored->selfAdmin);
        $this->assertSame($data->toArray(), $restored->toArray());
    }

    public function testPayloadCarriesTheAdminFlagOfAnAdmin(): void
    {
        // Asserted on a granted user as well as on the default: a flag that is written
        // but never read back true would still pass every assertion above it.
        $data = new HandshakeResponseSignalData(selfId: 7, selfName: 'User 7', selfAdmin: true);

        $restored = HandshakeResponseSignalData::fromArray($data->toArray());

        $this->assertTrue($restored->selfAdmin);
        $this->assertSame($data->toArray(), $restored->toArray());
    }
}
