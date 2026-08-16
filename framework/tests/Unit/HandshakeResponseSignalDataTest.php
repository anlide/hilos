<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\Session\SessionAck;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
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
                        'admin' => false,
                    ],
                    'impersonatedBy' => null,
                ],
                'data' => ['pendingAck' => null],
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
        $this->assertNull($restored->impersonatorId);
        $this->assertNull($restored->impersonatorName);
        $this->assertSame($data->toArray(), $restored->toArray());
    }

    public function testAnonymousPayloadCarriesNullCurrentUser(): void
    {
        $data = new HandshakeResponseSignalData();

        $this->assertNull($data->selfId);
        $this->assertSame(
            [
                'entities' => ['currentUser' => null, 'impersonatedBy' => null],
                'data' => ['pendingAck' => null],
            ],
            $data->toArray(),
        );
    }

    public function testAnonymousRoundtripStaysAnonymous(): void
    {
        $restored = HandshakeResponseSignalData::fromArray(new HandshakeResponseSignalData()->toArray());

        $this->assertNull($restored->selfId);
        $this->assertNull($restored->selfName);
        $this->assertNull($restored->impersonatorId);
        $this->assertNull($restored->impersonatorName);
    }

    public function testImpersonatedPayloadCarriesImpersonatedBySlot(): void
    {
        $data = new HandshakeResponseSignalData(
            selfId: 7,
            selfName: 'User 7',
            impersonatorId: 3,
            impersonatorName: 'Admin 3',
        );

        $this->assertSame(
            [
                'entities' => [
                    'currentUser' => [
                        'id' => 7,
                        'name' => 'User 7',
                        'admin' => false,
                    ],
                    'impersonatedBy' => [
                        'id' => 3,
                        'name' => 'Admin 3',
                    ],
                ],
                'data' => ['pendingAck' => null],
            ],
            $data->toArray(),
        );
    }

    public function testImpersonatedRoundtripPreservesImpersonator(): void
    {
        $data = new HandshakeResponseSignalData(
            selfId: 7,
            selfName: 'User 7',
            impersonatorId: 3,
            impersonatorName: 'Admin 3',
        );

        $restored = HandshakeResponseSignalData::fromArray($data->toArray());

        $this->assertSame(7, $restored->selfId);
        $this->assertSame('User 7', $restored->selfName);
        $this->assertSame(3, $restored->impersonatorId);
        $this->assertSame('Admin 3', $restored->impersonatorName);
        $this->assertSame($data->toArray(), $restored->toArray());
    }

    public function testPendingAckTravelsInTheDataSection(): void
    {
        $data = new HandshakeResponseSignalData(selfId: 7, selfName: 'User 7')
            ->withPendingAck(SessionAck::REGISTERED);

        $this->assertSame(
            ['pendingAck' => SessionAck::REGISTERED],
            $data->toArray()['data'],
        );
    }

    public function testReAddressingKeepsTheIdentityAndReplacesTheAck(): void
    {
        $data = new HandshakeResponseSignalData(
            selfId: 7,
            selfName: 'User 7',
            selfAdmin: true,
            impersonatorId: 3,
            impersonatorName: 'Admin 3',
            pendingAck: SessionAck::REGISTERED,
        );

        $reAddressed = $data->withPendingAck(null);

        $this->assertNull($reAddressed->pendingAck);
        $this->assertSame(7, $reAddressed->selfId);
        $this->assertSame('User 7', $reAddressed->selfName);
        $this->assertTrue($reAddressed->selfAdmin);
        $this->assertSame(3, $reAddressed->impersonatorId);
        $this->assertSame('Admin 3', $reAddressed->impersonatorName);
        $this->assertSame($data->toArray()['entities'], $reAddressed->toArray()['entities']);
    }

    public function testAnonymousRoundtripKeepsAPendingAck(): void
    {
        $data = new HandshakeResponseSignalData()->withPendingAck(SessionAck::PASSWORD_CHANGED);

        $restored = HandshakeResponseSignalData::fromArray($data->toArray());

        $this->assertNull($restored->selfId);
        $this->assertSame(SessionAck::PASSWORD_CHANGED, $restored->pendingAck);
        $this->assertSame($data->toArray(), $restored->toArray());
    }

    public function testAuthenticatedRoundtripKeepsAPendingAck(): void
    {
        $data = new HandshakeResponseSignalData(selfId: 7, selfName: 'User 7')
            ->withPendingAck(SessionAck::SIGNED_IN);

        $restored = HandshakeResponseSignalData::fromArray($data->toArray());

        $this->assertSame(SessionAck::SIGNED_IN, $restored->pendingAck);
        $this->assertSame($data->toArray(), $restored->toArray());
    }
}
