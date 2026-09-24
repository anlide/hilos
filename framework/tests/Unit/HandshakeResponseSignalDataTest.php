<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\Session\SessionAck;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for handshake response transport.
 */
final class HandshakeResponseSignalDataTest extends TestCase
{
    /** A server "now" no real clock will answer, so a leaked local time cannot pass for it. */
    private const int SERVER_TIME_MS = 1_700_000_000_000;

    /** The unfinished registration a session carries while it waits for its code. */
    private const array PENDING_AUTH_STEP = [
        'identifier' => 'ada@example.com',
        'kind' => 'email',
        'intent' => 'register',
        'step' => 'code',
        'channel' => null,
        'expiresAt' => 1_700_000_600_000,
        'code' => null,
    ];

    /**
     * The node a browser gets when the address it was registering became somebody else's
     * while it was away (HIL-833) - the two members that may be absent, both absent.
     */
    private const array TAKEN_AUTH_STEP = [
        'identifier' => 'ada@example.com',
        'kind' => 'email',
        'intent' => 'login',
        'step' => 'identifier',
        'channel' => null,
        'expiresAt' => null,
        'code' => 'identifier_taken',
    ];

    /** A deployment that can mail a code but has no phone channel - the asymmetric case. */
    private const array CODE_DELIVERY = ['email' => true, 'phone' => false];

    /** Enabled sign-in methods as the stamp hands them: a provider carries its name, the rest null; each says if it is ready. */
    private const array AUTH_METHODS = [
        ['key' => 'password', 'name' => null, 'ready' => true],
        ['key' => 'oauth:github', 'name' => 'GitHub', 'ready' => false],
    ];

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
                'data' => [
                    'pendingAck' => null,
                    'serverTimeMs' => null,
                    'pendingAuthStep' => null,
                    'codeDelivery' => null,
                    'authMethods' => null,
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
                'data' => [
                    'pendingAck' => null,
                    'serverTimeMs' => null,
                    'pendingAuthStep' => null,
                    'codeDelivery' => null,
                    'authMethods' => null,
                ],
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
                'data' => [
                    'pendingAck' => null,
                    'serverTimeMs' => null,
                    'pendingAuthStep' => null,
                    'codeDelivery' => null,
                    'authMethods' => null,
                ],
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
            [
                'pendingAck' => SessionAck::REGISTERED,
                'serverTimeMs' => null,
                'pendingAuthStep' => null,
                'codeDelivery' => null,
                'authMethods' => null,
            ],
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

    public function testSessionContextTravelsInTheDataSection(): void
    {
        $data = new HandshakeResponseSignalData(selfId: 7, selfName: 'User 7')
            ->withSessionContext(self::SERVER_TIME_MS, self::PENDING_AUTH_STEP, self::CODE_DELIVERY, self::AUTH_METHODS);

        $this->assertSame(
            [
                'pendingAck' => null,
                'serverTimeMs' => self::SERVER_TIME_MS,
                'pendingAuthStep' => self::PENDING_AUTH_STEP,
                'codeDelivery' => self::CODE_DELIVERY,
                'authMethods' => self::AUTH_METHODS,
            ],
            $data->toArray()['data'],
        );
    }

    public function testSessionContextSurvivesReAddressingWithAnAck(): void
    {
        // The two re-address different halves of the same response and are applied
        // in this order on every send path, so the clock has to outlive the ack.
        $data = new HandshakeResponseSignalData(selfId: 7, selfName: 'User 7')
            ->withSessionContext(self::SERVER_TIME_MS, self::PENDING_AUTH_STEP, self::CODE_DELIVERY, self::AUTH_METHODS)
            ->withPendingAck(SessionAck::SIGNED_IN);

        $this->assertSame(self::SERVER_TIME_MS, $data->serverTimeMs);
        $this->assertSame(self::PENDING_AUTH_STEP, $data->pendingAuthStep);
        $this->assertSame(SessionAck::SIGNED_IN, $data->pendingAck);
    }

    public function testAnonymousRoundtripKeepsTheSessionContext(): void
    {
        // The anonymous branch is the one that matters here: a session halfway
        // through registration or recovery has no user yet.
        $data = new HandshakeResponseSignalData()
            ->withSessionContext(self::SERVER_TIME_MS, self::PENDING_AUTH_STEP, self::CODE_DELIVERY, self::AUTH_METHODS);

        $restored = HandshakeResponseSignalData::fromArray($data->toArray());

        $this->assertNull($restored->selfId);
        $this->assertSame(self::SERVER_TIME_MS, $restored->serverTimeMs);
        $this->assertSame(self::PENDING_AUTH_STEP, $restored->pendingAuthStep);
        $this->assertSame(self::CODE_DELIVERY, $restored->codeDelivery);
        $this->assertSame(self::AUTH_METHODS, $restored->authMethods);
        $this->assertSame($data->toArray(), $restored->toArray());
    }

    public function testRoundtripRejectsAnAuthStepNodeWithoutItsIdentifier(): void
    {
        $payload = new HandshakeResponseSignalData()
            ->withSessionContext(self::SERVER_TIME_MS, self::PENDING_AUTH_STEP, self::CODE_DELIVERY, self::AUTH_METHODS)
            ->toArray();
        unset($payload['data']['pendingAuthStep']['identifier']);

        $this->expectException(InvalidFormatException::class);

        HandshakeResponseSignalData::fromArray($payload);
    }

    public function testRoundtripRejectsAnAuthStepNodeWithoutItsStep(): void
    {
        // The member the node was widened to carry (HIL-648): without it the surface
        // cannot tell a code screen from a new-password one, which is the whole point
        // of the node reaching a tab that submitted nothing.
        $payload = new HandshakeResponseSignalData()
            ->withSessionContext(self::SERVER_TIME_MS, self::PENDING_AUTH_STEP, self::CODE_DELIVERY, self::AUTH_METHODS)
            ->toArray();
        unset($payload['data']['pendingAuthStep']['step']);

        $this->expectException(InvalidFormatException::class);

        HandshakeResponseSignalData::fromArray($payload);
    }

    public function testARollbackNodeSurvivesTheRoundtripWithItsReasonAndNoExpiry(): void
    {
        // The identifier step is the one the handshake was widened for (HIL-833): it
        // stands on no code, so there is no moment to promise, and without the reason
        // travelling beside it the surface could not tell "your address was taken" from
        // "you were never in a flow" - both of which look like the address field.
        $data = new HandshakeResponseSignalData()
            ->withSessionContext(self::SERVER_TIME_MS, self::TAKEN_AUTH_STEP, self::CODE_DELIVERY, self::AUTH_METHODS);

        $restored = HandshakeResponseSignalData::fromArray($data->toArray());

        $this->assertSame(self::TAKEN_AUTH_STEP, $restored->pendingAuthStep);
        $this->assertSame($data->toArray(), $restored->toArray());
    }

    public function testRoundtripRejectsADeliveryNodeMissingAKind(): void
    {
        // Half an answer is worse than none: the surface reads a missing node as
        // "everything is deliverable", so a node arriving without one of its two
        // flags has to be refused rather than read as a false (HIL-830).
        $payload = new HandshakeResponseSignalData()
            ->withSessionContext(self::SERVER_TIME_MS, self::PENDING_AUTH_STEP, self::CODE_DELIVERY, self::AUTH_METHODS)
            ->toArray();
        unset($payload['data']['codeDelivery']['phone']);

        $this->expectException(InvalidFormatException::class);

        HandshakeResponseSignalData::fromArray($payload);
    }

    public function testTheMethodSetSurvivesReAddressingWithAnAck(): void
    {
        // The set is stamped with the clock and has to outlive the ack the same way (HIL-427).
        $data = new HandshakeResponseSignalData(selfId: 7, selfName: 'User 7')
            ->withSessionContext(self::SERVER_TIME_MS, null, self::CODE_DELIVERY, self::AUTH_METHODS)
            ->withPendingAck(SessionAck::SIGNED_IN);

        $this->assertSame(self::AUTH_METHODS, $data->authMethods);
    }

    public function testAResponseThatNeverPassedTheStampCarriesNoMethodSet(): void
    {
        $restored = HandshakeResponseSignalData::fromArray(new HandshakeResponseSignalData()->toArray());

        $this->assertNull($restored->authMethods);
    }

    public function testRoundtripRejectsAMethodEntryWithoutItsKey(): void
    {
        $payload = new HandshakeResponseSignalData()
            ->withSessionContext(self::SERVER_TIME_MS, null, self::CODE_DELIVERY, self::AUTH_METHODS)
            ->toArray();
        unset($payload['data']['authMethods'][1]['key']);

        $this->expectException(InvalidFormatException::class);

        HandshakeResponseSignalData::fromArray($payload);
    }

    public function testRoundtripRejectsAMethodEntryWithoutItsReadiness(): void
    {
        $payload = new HandshakeResponseSignalData()
            ->withSessionContext(self::SERVER_TIME_MS, null, self::CODE_DELIVERY, self::AUTH_METHODS)
            ->toArray();
        unset($payload['data']['authMethods'][1]['ready']);

        $this->expectException(InvalidFormatException::class);

        HandshakeResponseSignalData::fromArray($payload);
    }
}
