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

    /** The passkey policy as the stamp hands it - a yes, so it cannot pass for the unstamped null or the default no. */
    private const bool PASSKEY_ALLOWS_UNPROVEN = true;

    /**
     * A frozen person with a deletion scheduled: every fact named, the freeze shown (HIL-945), and the
     * privacy policy still inside its window beside the lapsed terms (HIL-500).
     */
    private const array STANDING = [
        'shown' => 'frozen',
        'blocked' => false,
        'frozen' => true,
        'deletionEffectiveAt' => 1_767_225_600_000,
        'lapsed' => [['document' => 'terms', 'deadline' => '2026-03-01']],
        'window' => [['document' => 'privacy', 'deadline' => '2026-11-10']],
    ];

    public function testPendingRegistrationConsentSurvivesTheTransportRoundtrip(): void
    {
        $accepted = ['terms' => 'terms-1', 'privacy' => 'privacy-1'];
        $step = self::PENDING_AUTH_STEP + [HandshakeResponseSignalData::acceptedRevisions => $accepted];
        $payload = new HandshakeResponseSignalData(pendingAuthStep: $step);
        $restored = HandshakeResponseSignalData::fromArray($payload->toArray());

        self::assertSame($step, $restored->pendingAuthStep);
        self::assertSame($payload->toArray(), $restored->toArray());
    }

    public function testPendingRegistrationWithoutConsentDoesNotMintAMap(): void
    {
        $payload = new HandshakeResponseSignalData(pendingAuthStep: self::PENDING_AUTH_STEP);
        $restored = HandshakeResponseSignalData::fromArray($payload->toArray());

        self::assertSame(self::PENDING_AUTH_STEP, $restored->pendingAuthStep);
        self::assertArrayNotHasKey(HandshakeResponseSignalData::acceptedRevisions, $restored->pendingAuthStep);
    }

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
                    'passkeyAllowsUnproven' => null,
                    'accountBlocked' => null,
                    'accountStanding' => null,
                    'adminViewMode' => null,
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
                    'passkeyAllowsUnproven' => null,
                    'accountBlocked' => null,
                    'accountStanding' => null,
                    'adminViewMode' => null,
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
                    'passkeyAllowsUnproven' => null,
                    'accountBlocked' => null,
                    'accountStanding' => null,
                    'adminViewMode' => null,
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
                'passkeyAllowsUnproven' => null,
                'accountBlocked' => null,
                'accountStanding' => null,
                'adminViewMode' => null,
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
            ->withSessionContext(
                self::SERVER_TIME_MS,
                self::PENDING_AUTH_STEP,
                self::CODE_DELIVERY,
                self::AUTH_METHODS,
                self::PASSKEY_ALLOWS_UNPROVEN,
            );

        $this->assertSame(
            [
                'pendingAck' => null,
                'serverTimeMs' => self::SERVER_TIME_MS,
                'pendingAuthStep' => self::PENDING_AUTH_STEP,
                'codeDelivery' => self::CODE_DELIVERY,
                'authMethods' => self::AUTH_METHODS,
                'passkeyAllowsUnproven' => self::PASSKEY_ALLOWS_UNPROVEN,
                'accountBlocked' => null,
                'accountStanding' => null,
                'adminViewMode' => null,
            ],
            $data->toArray()['data'],
        );
    }

    public function testSessionContextSurvivesReAddressingWithAnAck(): void
    {
        // The two re-address different halves of the same response and are applied
        // in this order on every send path, so the clock has to outlive the ack.
        $data = new HandshakeResponseSignalData(selfId: 7, selfName: 'User 7')
            ->withSessionContext(
                self::SERVER_TIME_MS,
                self::PENDING_AUTH_STEP,
                self::CODE_DELIVERY,
                self::AUTH_METHODS,
                self::PASSKEY_ALLOWS_UNPROVEN,
            )
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
            ->withSessionContext(
                self::SERVER_TIME_MS,
                self::PENDING_AUTH_STEP,
                self::CODE_DELIVERY,
                self::AUTH_METHODS,
                self::PASSKEY_ALLOWS_UNPROVEN,
            );

        $restored = HandshakeResponseSignalData::fromArray($data->toArray());

        $this->assertNull($restored->selfId);
        $this->assertSame(self::SERVER_TIME_MS, $restored->serverTimeMs);
        $this->assertSame(self::PENDING_AUTH_STEP, $restored->pendingAuthStep);
        $this->assertSame(self::CODE_DELIVERY, $restored->codeDelivery);
        $this->assertSame(self::AUTH_METHODS, $restored->authMethods);
        $this->assertSame(self::PASSKEY_ALLOWS_UNPROVEN, $restored->passkeyAllowsUnproven);
        $this->assertSame($data->toArray(), $restored->toArray());
    }

    public function testRoundtripCarriesASecondFactorStepThatNamesNoAddress(): void
    {
        // A sign-in held on its second factor names no address (HIL-494): the person proved
        // one on the way in and the code screen does not repeat it, so the node arrives with
        // the address members null and the second-factor member beside them.
        $step = [
            'identifier' => null,
            'kind' => null,
            'intent' => 'login',
            'step' => 'second_factor',
            'channel' => null,
            'expiresAt' => 1_760_000_900_000,
            'code' => null,
            'secondFactor' => ['trustDeviceDays' => 30, 'resetEffectiveAt' => null],
        ];
        $payload = new HandshakeResponseSignalData()
            ->withSessionContext(self::SERVER_TIME_MS, $step, self::CODE_DELIVERY, self::AUTH_METHODS, self::PASSKEY_ALLOWS_UNPROVEN)
            ->toArray();

        $restored = HandshakeResponseSignalData::fromArray($payload);

        $this->assertSame($step, $restored->pendingAuthStep);
    }

    public function testRoundtripRejectsAnAuthStepNodeWithoutItsStep(): void
    {
        // The member the node was widened to carry (HIL-648): without it the surface
        // cannot tell a code screen from a new-password one, which is the whole point
        // of the node reaching a tab that submitted nothing.
        $payload = new HandshakeResponseSignalData()
            ->withSessionContext(
                self::SERVER_TIME_MS,
                self::PENDING_AUTH_STEP,
                self::CODE_DELIVERY,
                self::AUTH_METHODS,
                self::PASSKEY_ALLOWS_UNPROVEN,
            )
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
            ->withSessionContext(self::SERVER_TIME_MS, self::TAKEN_AUTH_STEP, self::CODE_DELIVERY, self::AUTH_METHODS, self::PASSKEY_ALLOWS_UNPROVEN);

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
            ->withSessionContext(
                self::SERVER_TIME_MS,
                self::PENDING_AUTH_STEP,
                self::CODE_DELIVERY,
                self::AUTH_METHODS,
                self::PASSKEY_ALLOWS_UNPROVEN,
            )
            ->toArray();
        unset($payload['data']['codeDelivery']['phone']);

        $this->expectException(InvalidFormatException::class);

        HandshakeResponseSignalData::fromArray($payload);
    }

    public function testTheMethodSetSurvivesReAddressingWithAnAck(): void
    {
        // The set is stamped with the clock and has to outlive the ack the same way (HIL-427).
        $data = new HandshakeResponseSignalData(selfId: 7, selfName: 'User 7')
            ->withSessionContext(self::SERVER_TIME_MS, null, self::CODE_DELIVERY, self::AUTH_METHODS, self::PASSKEY_ALLOWS_UNPROVEN)
            ->withPendingAck(SessionAck::SIGNED_IN);

        $this->assertSame(self::AUTH_METHODS, $data->authMethods);
    }

    public function testThePasskeyPolicySurvivesReAddressingWithAnAck(): void
    {
        // Stamped beside the method set, it has to outlive the ack the same way (HIL-1105).
        $data = new HandshakeResponseSignalData(selfId: 7, selfName: 'User 7')
            ->withSessionContext(self::SERVER_TIME_MS, null, self::CODE_DELIVERY, self::AUTH_METHODS, self::PASSKEY_ALLOWS_UNPROVEN)
            ->withPendingAck(SessionAck::SIGNED_IN);

        $this->assertSame(self::PASSKEY_ALLOWS_UNPROVEN, $data->passkeyAllowsUnproven);
    }

    public function testTheBlockedCardTravelsInTheDataSectionOfAnAnonymousResponse(): void
    {
        $data = new HandshakeResponseSignalData()->withAccountBlocked(['identifier' => 'maria@example.com', 'dataExport' => null]);

        $this->assertNull($data->toArray()['entities']['currentUser']);
        $this->assertSame(['identifier' => 'maria@example.com', 'dataExport' => null], $data->toArray()['data']['accountBlocked']);
    }

    public function testTheBlockedCardSurvivesTheRoundtripWithAndWithoutAnAddress(): void
    {
        $named = new HandshakeResponseSignalData()->withAccountBlocked(['identifier' => '+380501234567', 'dataExport' => null]);
        $unnamed = new HandshakeResponseSignalData()->withAccountBlocked(['identifier' => null, 'dataExport' => null]);

        $this->assertSame(
            ['identifier' => '+380501234567', 'dataExport' => null],
            HandshakeResponseSignalData::fromArray($named->toArray())->accountBlocked,
        );
        $this->assertSame(['identifier' => null, 'dataExport' => null], HandshakeResponseSignalData::fromArray($unnamed->toArray())->accountBlocked);
        $this->assertNull(HandshakeResponseSignalData::fromArray(new HandshakeResponseSignalData()->toArray())->accountBlocked);
    }

    public function testTheBlockedCardSurvivesTheStampAndReAddressing(): void
    {
        // The card is stamped after the session context and the ack, and neither may take it off again.
        $data = new HandshakeResponseSignalData()
            ->withAccountBlocked(['identifier' => 'maria@example.com', 'dataExport' => null])
            ->withSessionContext(self::SERVER_TIME_MS, null, self::CODE_DELIVERY, self::AUTH_METHODS, self::PASSKEY_ALLOWS_UNPROVEN)
            ->withPendingAck(null);

        $this->assertSame(['identifier' => 'maria@example.com', 'dataExport' => null], $data->accountBlocked);
    }

    public function testTheStandingTravelsInTheDataSectionAndSurvivesTheRoundtrip(): void
    {
        $data = new HandshakeResponseSignalData(selfId: 41, selfName: 'Maria', selfAdmin: false)->withAccountStanding(self::STANDING);

        $this->assertSame(self::STANDING, $data->toArray()['data']['accountStanding']);
        $this->assertSame(self::STANDING, HandshakeResponseSignalData::fromArray($data->toArray())->accountStanding);
        $this->assertNull(HandshakeResponseSignalData::fromArray(new HandshakeResponseSignalData()->toArray())->accountStanding);
    }

    public function testTheStandingSurvivesTheStampReAddressingAndTheCard(): void
    {
        // Stamped last on the send path, and none of the other three axes may take it off again.
        $data = new HandshakeResponseSignalData(selfId: 41, selfName: 'Maria', selfAdmin: false)
            ->withAccountStanding(self::STANDING)
            ->withSessionContext(self::SERVER_TIME_MS, null, self::CODE_DELIVERY, self::AUTH_METHODS, self::PASSKEY_ALLOWS_UNPROVEN)
            ->withPendingAck(null)
            ->withAccountBlocked(null);

        $this->assertSame(self::STANDING, $data->accountStanding);
    }

    public function testRoundtripRejectsAStandingWithoutItsFacts(): void
    {
        $payload = new HandshakeResponseSignalData(selfId: 41, selfName: 'Maria', selfAdmin: false)->toArray();
        $payload['data']['accountStanding'] = ['shown' => 'frozen'];

        $this->expectException(InvalidFormatException::class);

        HandshakeResponseSignalData::fromArray($payload);
    }

    public function testTheViewModeTravelsInTheDataSectionOfAnAnonymousResponseAndSurvivesTheRoundtrip(): void
    {
        // A guest is the viewer the mode opens the admin section to, so the anonymous branch has to keep it (HIL-1253).
        $on = new HandshakeResponseSignalData()->withAdminViewMode(true);
        $off = new HandshakeResponseSignalData()->withAdminViewMode(false);

        $this->assertNull($on->toArray()['entities']['currentUser']);
        $this->assertTrue($on->toArray()['data']['adminViewMode']);
        $this->assertTrue(HandshakeResponseSignalData::fromArray($on->toArray())->adminViewMode);
        $this->assertFalse(HandshakeResponseSignalData::fromArray($off->toArray())->adminViewMode);
        $this->assertSame($on->toArray(), HandshakeResponseSignalData::fromArray($on->toArray())->toArray());
    }

    public function testTheViewModeSurvivesTheRoundtripOfASignedInResponse(): void
    {
        $data = new HandshakeResponseSignalData(selfId: 41, selfName: 'Maria', selfAdmin: true)->withAdminViewMode(true);

        $restored = HandshakeResponseSignalData::fromArray($data->toArray());

        $this->assertTrue($restored->selfAdmin);
        $this->assertTrue($restored->adminViewMode);
    }

    public function testAResponseThatNeverPassedTheStampCarriesNoViewMode(): void
    {
        // The key is written as null rather than left out, and a payload without it reads back as null too.
        $payload = new HandshakeResponseSignalData()->toArray();

        $this->assertArrayHasKey('adminViewMode', $payload['data']);
        $this->assertNull($payload['data']['adminViewMode']);
        unset($payload['data']['adminViewMode']);
        $this->assertNull(HandshakeResponseSignalData::fromArray($payload)->adminViewMode);
    }

    public function testTheViewModeSurvivesEveryOtherAxisOfTheStamp(): void
    {
        // Stamped last on the send path, and none of the other four axes may take it off again.
        $data = new HandshakeResponseSignalData(selfId: 41, selfName: 'Maria', selfAdmin: false)
            ->withAdminViewMode(true)
            ->withSessionContext(self::SERVER_TIME_MS, null, self::CODE_DELIVERY, self::AUTH_METHODS, self::PASSKEY_ALLOWS_UNPROVEN)
            ->withPendingAck(null)
            ->withAccountBlocked(null)
            ->withAccountStanding(self::STANDING);

        $this->assertTrue($data->adminViewMode);
    }

    public function testTheViewModeStampKeepsEveryOtherAxis(): void
    {
        $data = new HandshakeResponseSignalData(selfId: 41, selfName: 'Maria', selfAdmin: false)
            ->withSessionContext(self::SERVER_TIME_MS, null, self::CODE_DELIVERY, self::AUTH_METHODS, self::PASSKEY_ALLOWS_UNPROVEN)
            ->withPendingAck(SessionAck::SIGNED_IN)
            ->withAccountBlocked(['identifier' => 'maria@example.com', 'dataExport' => null])
            ->withAccountStanding(self::STANDING)
            ->withAdminViewMode(false);

        $this->assertFalse($data->adminViewMode);
        $this->assertSame(self::SERVER_TIME_MS, $data->serverTimeMs);
        $this->assertSame(SessionAck::SIGNED_IN, $data->pendingAck);
        $this->assertSame(['identifier' => 'maria@example.com', 'dataExport' => null], $data->accountBlocked);
        $this->assertSame(self::STANDING, $data->accountStanding);
    }

    public function testRoundtripRejectsAViewModeThatIsNotABoolean(): void
    {
        // The surface reads anything but true as off; the parse boundary does not let a string pass for the flag.
        $payload = new HandshakeResponseSignalData()->toArray();
        $payload['data']['adminViewMode'] = 'true';

        $this->expectException(InvalidFormatException::class);

        HandshakeResponseSignalData::fromArray($payload);
    }

    public function testRoundtripRejectsABlockedCardThatIsNotANode(): void
    {
        $payload = new HandshakeResponseSignalData()->toArray();
        $payload['data']['accountBlocked'] = 'maria@example.com';

        $this->expectException(InvalidFormatException::class);

        HandshakeResponseSignalData::fromArray($payload);
    }

    public function testAResponseThatNeverPassedTheStampCarriesNoMethodSet(): void
    {
        $restored = HandshakeResponseSignalData::fromArray(new HandshakeResponseSignalData()->toArray());

        $this->assertNull($restored->authMethods);
        $this->assertNull($restored->passkeyAllowsUnproven);
    }

    public function testRoundtripRejectsAPasskeyPolicyThatIsNotABoolean(): void
    {
        $payload = new HandshakeResponseSignalData()
            ->withSessionContext(self::SERVER_TIME_MS, null, self::CODE_DELIVERY, self::AUTH_METHODS, self::PASSKEY_ALLOWS_UNPROVEN)
            ->toArray();
        $payload['data']['passkeyAllowsUnproven'] = 'yes';

        $this->expectException(InvalidFormatException::class);

        HandshakeResponseSignalData::fromArray($payload);
    }

    public function testRoundtripRejectsAMethodEntryWithoutItsKey(): void
    {
        $payload = new HandshakeResponseSignalData()
            ->withSessionContext(self::SERVER_TIME_MS, null, self::CODE_DELIVERY, self::AUTH_METHODS, self::PASSKEY_ALLOWS_UNPROVEN)
            ->toArray();
        unset($payload['data']['authMethods'][1]['key']);

        $this->expectException(InvalidFormatException::class);

        HandshakeResponseSignalData::fromArray($payload);
    }

    public function testRoundtripRejectsAMethodEntryWithoutItsReadiness(): void
    {
        $payload = new HandshakeResponseSignalData()
            ->withSessionContext(self::SERVER_TIME_MS, null, self::CODE_DELIVERY, self::AUTH_METHODS, self::PASSKEY_ALLOWS_UNPROVEN)
            ->toArray();
        unset($payload['data']['authMethods'][1]['ready']);

        $this->expectException(InvalidFormatException::class);

        HandshakeResponseSignalData::fromArray($payload);
    }
}
