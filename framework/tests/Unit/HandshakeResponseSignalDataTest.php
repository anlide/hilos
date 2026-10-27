<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\Session\SessionAck;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Users\DTO\AccountStandingStateSignalData;
use PHPUnit\Framework\Attributes\DataProvider;
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
        'message' => null,
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
        'message' => 'That address already has an account — sign in instead.',
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
        'deletionGraceDays' => 30,
        'lapsed' => [['document' => 'terms', 'deadline' => '2026-03-01']],
        'window' => [['document' => 'privacy', 'deadline' => '2026-11-10']],
        'mergedInto' => null,
        'mergedIntoName' => null,
    ];

    public function testLegacyStandingWithoutDurationKeepsTheDeletion(): void
    {
        $standing = self::STANDING;
        unset($standing['deletionGraceDays']);
        $frame = new HandshakeResponseSignalData(accountStanding: $standing);
        $read = HandshakeResponseSignalData::fromArray($frame->toArray());

        self::assertSame(self::STANDING['deletionEffectiveAt'], $read->accountStanding['deletionEffectiveAt']);
        self::assertNull($read->accountStanding['deletionGraceDays']);
    }

    public function testTheCardStandingUsesTheSameNullableDurationReader(): void
    {
        $frame = new AccountStandingStateSignalData(7, self::STANDING);
        self::assertEquals($frame, AccountStandingStateSignalData::fromArray($frame->toArray()));

        $standing = self::STANDING;
        $standing['deletionGraceDays'] = null;
        self::assertSame($standing, HandshakeResponseSignalData::readAccountStanding(['accountStanding' => $standing]));
        unset($standing['deletionGraceDays']);
        $legacy = AccountStandingStateSignalData::fromArray(['userId' => 7, 'accountStanding' => $standing]);
        self::assertNull($legacy->accountStanding['deletionGraceDays']);
        self::assertSame(self::STANDING['deletionEffectiveAt'], $legacy->accountStanding['deletionEffectiveAt']);
    }

    /** @param mixed $duration Invalid duration carried by the frame */
    #[DataProvider('invalidDeletionDurations')]
    public function testInvalidDeletionDurationIsRefused(mixed $duration): void
    {
        $standing = self::STANDING;
        $standing['deletionGraceDays'] = $duration;
        $this->expectException(InvalidFormatException::class);

        HandshakeResponseSignalData::readAccountStanding(['accountStanding' => $standing]);
    }

    /** @return array<string, array{mixed}> Invalid duration forms */
    public static function invalidDeletionDurations(): array
    {
        return ['zero' => [0], 'negative' => [-1], 'fraction' => [1.5], 'string' => ['30'], 'boolean' => [true]];
    }

    /** @return array<string, array{?string}> Reasons and an explicit absence */
    public static function accessLossReasons(): array
    {
        return [
            'remote end' => ['session_ended'],
            'live block' => ['account_blocked'],
            'no loss' => [null],
        ];
    }

    /** @param ?string $reason Live explanation carried by an anonymous response */
    #[DataProvider('accessLossReasons')]
    public function testAccessLossReasonSurvivesEachStampAndRoundtrip(?string $reason): void
    {
        $frame = new HandshakeResponseSignalData(
            pendingAck: 'auth_registered',
            serverTimeMs: self::SERVER_TIME_MS,
            pendingAuthStep: self::PENDING_AUTH_STEP,
            codeDelivery: self::CODE_DELIVERY,
            authMethods: self::AUTH_METHODS,
            passkeyAllowsUnproven: true,
            accountBlocked: ['identifier' => 'ada@example.test', 'dataExport' => null],
            adminViewMode: true,
            impersonationPolicy: [
                'viewOnly' => true, 'carryAdmin' => false, 'allowed' => true, 'accountAccess' => true,
                'blocked' => false, 'frozen' => false, 'equal' => false,
            ],
            themeSettings: ['switchingEnabled' => true, 'defaultTheme' => 'dark'],
            accessLossReason: $reason,
        );
        $copies = [
            $frame->withPendingAck($frame->pendingAck),
            $frame->withAccountBlocked($frame->accountBlocked),
            $frame->withAccountStanding(null),
            $frame->withAdminViewMode(true),
            $frame->withImpersonationPolicy($frame->impersonationPolicy),
            $frame->withThemeSettings($frame->themeSettings),
            $frame->withSessionContext(self::SERVER_TIME_MS, self::PENDING_AUTH_STEP, self::CODE_DELIVERY, self::AUTH_METHODS, true),
            $frame->withAccessLossReason($reason),
        ];
        foreach ($copies as $copy) {
            self::assertEquals($frame, $copy);
            self::assertSame($reason, $copy->toArray()['data']['accessLossReason']);
            self::assertEquals($frame, HandshakeResponseSignalData::fromArray($copy->toArray()));
        }
        self::assertNull($frame->withAccessLossReason(null)->accessLossReason);
        self::assertSame($reason, $frame->accessLossReason);

        $older = $frame->toArray();
        unset($older['data']['accessLossReason']);
        self::assertNull(HandshakeResponseSignalData::fromArray($older)->accessLossReason);
    }

    public function testAccessLossStampKeepsEveryAuthenticatedIdentityField(): void
    {
        $frame = new HandshakeResponseSignalData(
            selfId: 41,
            selfName: 'Ada',
            selfAdmin: true,
            selfPhoto: '/photo',
            impersonatorId: 7,
            impersonatorName: 'Admin',
            selfThemePick: 'dark',
            selfThemePickInitialized: true,
            selfTimeZone: 'Europe/Paris',
            accountStanding: self::STANDING,
        );

        self::assertEquals($frame, $frame->withAccessLossReason(null));
        self::assertEquals($frame, HandshakeResponseSignalData::fromArray($frame->withAccessLossReason(null)->toArray()));
    }

    /** @return array<string, array{mixed, class-string}> Invalid reasons and their refusal family */
    public static function malformedAccessLossReasons(): array
    {
        return [
            'unknown' => ['expired', InvalidArgumentException::class],
            'empty' => ['', InvalidArgumentException::class],
            'integer' => [42, InvalidFormatException::class],
            'boolean' => [false, InvalidFormatException::class],
            'array' => [[], InvalidFormatException::class],
        ];
    }

    /**
     * @param mixed $reason Invalid wire value
     * @param class-string $exception Expected refusal family
     */
    #[DataProvider('malformedAccessLossReasons')]
    public function testMalformedAccessLossReasonIsRefused(mixed $reason, string $exception): void
    {
        $this->expectException($exception);

        HandshakeResponseSignalData::fromArray(['data' => ['accessLossReason' => $reason]]);
    }

    public function testAccessLossReasonCannotBeAttachedToAnAuthenticatedIdentity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HandshakeResponseSignalData(selfId: 41)->withAccessLossReason('session_ended');
    }

    public function testParserRefusesAccessLossReasonBesideAnAuthenticatedIdentity(): void
    {
        $payload = new HandshakeResponseSignalData(selfId: 41)->toArray();
        $payload['data']['accessLossReason'] = 'account_blocked';
        $this->expectException(InvalidArgumentException::class);

        HandshakeResponseSignalData::fromArray($payload);
    }

    /**
     * @param array<string, mixed> $messageField Missing or mistyped required message
     */
    #[DataProvider('malformedPendingMessages')]
    public function testPendingStepRequiresNullableStringMessage(array $messageField): void
    {
        $step = self::PENDING_AUTH_STEP;
        unset($step['message']);
        $this->expectException(InvalidFormatException::class);

        HandshakeResponseSignalData::fromArray(['data' => ['pendingAuthStep' => $step + $messageField]]);
    }

    /** @return array<string, array{array<string, mixed>}> Invalid nested message fields */
    public static function malformedPendingMessages(): array
    {
        return [
            'missing' => [[]],
            'integer' => [['message' => 42]],
            'boolean' => [['message' => false]],
            'array' => [['message' => []]],
        ];
    }

    public function testMissingOrNullPendingNodeStaysAbsent(): void
    {
        self::assertNull(HandshakeResponseSignalData::fromArray([])->pendingAuthStep);
        self::assertNull(HandshakeResponseSignalData::fromArray(['data' => ['pendingAuthStep' => null]])->pendingAuthStep);
    }

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
                        'photo' => null,
                        'themePick' => null,
                        'themePickInitialized' => false,
                        'timeZone' => null,
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
                    'accessLossReason' => null,
                    'adminViewMode' => null,
                    'impersonationPolicy' => null,
                    'themeSettings' => null,
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

    /** Both chosen and unchosen account themes survive the wire and every stamp. */
    public function testThemePickSurvivesRoundtripAndEveryResponseStamp(): void
    {
        foreach (['light', 'dark', 'system', null] as $pick) {
            $data = new HandshakeResponseSignalData(selfId: 7, selfName: 'User 7', selfThemePick: $pick, selfThemePickInitialized: true);
            $stamped = $data
                ->withPendingAck(null)
                ->withAccountBlocked(null)
                ->withAccountStanding(null)
                ->withAdminViewMode(false)
                ->withImpersonationPolicy([
                    'viewOnly' => true,
                    'carryAdmin' => false,
                    'allowed' => true,
                    'accountAccess' => true,
                    'blocked' => false,
                    'frozen' => false,
                    'equal' => false,
                ])
                ->withThemeSettings(['switchingEnabled' => true, 'defaultTheme' => 'system'])
                ->withSessionContext(123, null, ['email' => true, 'phone' => false], [], false);

            self::assertTrue($stamped->selfThemePickInitialized);
            self::assertTrue(HandshakeResponseSignalData::fromArray($stamped->toArray())->selfThemePickInitialized);
            self::assertTrue($stamped->toArray()['entities']['currentUser']['themePickInitialized']);
            self::assertArrayHasKey('themePick', $stamped->toArray()['entities']['currentUser']);
            self::assertSame($pick, $stamped->toArray()['entities']['currentUser']['themePick']);
            self::assertSame($pick, HandshakeResponseSignalData::fromArray($stamped->toArray())->selfThemePick);
            self::assertSame($pick, HandshakeResponseSignalData::fromArray($data->toArray())->selfThemePick);
        }
    }

    public function testBothNullThemeStatesSurviveTheTransport(): void
    {
        foreach ([false, true] as $initialized) {
            $data = new HandshakeResponseSignalData(selfId: 7, selfThemePick: null, selfThemePickInitialized: $initialized);
            $restored = HandshakeResponseSignalData::fromArray($data->toArray());

            self::assertNull($restored->selfThemePick);
            self::assertSame($initialized, $restored->selfThemePickInitialized);
            self::assertSame($data->toArray(), $restored->toArray());
        }
    }

    public function testSignedInThemeInitializationFlagIsRequiredAndStrictlyBoolean(): void
    {
        foreach ([null, 0, 1, 'true', []] as $invalid) {
            $payload = new HandshakeResponseSignalData(selfId: 7)->toArray();
            $payload['entities']['currentUser']['themePickInitialized'] = $invalid;
            try {
                HandshakeResponseSignalData::fromArray($payload);
                self::fail('Mistyped initialization flag must be refused');
            } catch (InvalidFormatException $failure) {
                self::assertStringContainsString('themePickInitialized', $failure->getMessage());
            }
        }

        $payload = new HandshakeResponseSignalData(selfId: 7)->toArray();
        unset($payload['entities']['currentUser']['themePickInitialized']);
        $this->expectException(InvalidFormatException::class);
        HandshakeResponseSignalData::fromArray($payload);
    }

    /** A guest has no current-user node to carry a theme choice. */
    public function testAnonymousResponseDoesNotExposeAThemePick(): void
    {
        $data = new HandshakeResponseSignalData(selfThemePick: 'dark');

        self::assertNull($data->toArray()['entities']['currentUser']);
        self::assertNull(HandshakeResponseSignalData::fromArray($data->toArray())->selfThemePick);
    }

    /** A photo URL remains on the user through each response stamp. */
    public function testPhotoSurvivesResponseStamps(): void
    {
        $url = '/_hilos/file?id=9&variant=hilos_avatar';
        $data = new HandshakeResponseSignalData(selfId: 7, selfName: 'User 7', selfPhoto: $url);
        $stamped = $data
            ->withPendingAck(null)
            ->withAccountBlocked(null)
            ->withAccountStanding(null)
            ->withAdminViewMode(false)
            ->withImpersonationPolicy([
                'viewOnly' => true,
                'carryAdmin' => false,
                'allowed' => true,
                'accountAccess' => true,
                'blocked' => false,
                'frozen' => false,
                'equal' => false,
            ])
            ->withSessionContext(123, null, ['email' => true, 'phone' => false], [], false);

        $this->assertSame($url, $stamped->toArray()['entities']['currentUser']['photo']);
        $this->assertSame($url, HandshakeResponseSignalData::fromArray($stamped->toArray())->selfPhoto);
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
                    'accessLossReason' => null,
                    'adminViewMode' => null,
                    'impersonationPolicy' => null,
                    'themeSettings' => null,
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
                        'photo' => null,
                        'themePick' => null,
                        'themePickInitialized' => false,
                        'timeZone' => null,
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
                    'accessLossReason' => null,
                    'adminViewMode' => null,
                    'impersonationPolicy' => null,
                    'themeSettings' => null,
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
                'accessLossReason' => null,
                'adminViewMode' => null,
                'impersonationPolicy' => null,
                'themeSettings' => null,
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
                'accessLossReason' => null,
                'adminViewMode' => null,
                'impersonationPolicy' => null,
                'themeSettings' => null,
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
            'message' => null,
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

    public function testTheImpersonationPolicyTravelsInTheDataSectionAndSurvivesTheRoundtrip(): void
    {
        // The installation's fact rides every response, the anonymous one included (HIL-1170, HIL-1307).
        $policy = [
            'viewOnly' => true,
            'carryAdmin' => false,
            'allowed' => true,
            'accountAccess' => false,
            'blocked' => true,
            'frozen' => true,
            'equal' => true,
        ];
        $anonymous = new HandshakeResponseSignalData()->withImpersonationPolicy($policy);
        $signedIn = new HandshakeResponseSignalData(selfId: 41, selfName: 'Maria', selfAdmin: false, impersonatorId: 7, impersonatorName: 'Root')
            ->withImpersonationPolicy($policy);

        $this->assertSame($policy, $anonymous->toArray()['data']['impersonationPolicy']);
        $this->assertSame($policy, HandshakeResponseSignalData::fromArray($anonymous->toArray())->impersonationPolicy);
        $this->assertSame($policy, HandshakeResponseSignalData::fromArray($signedIn->toArray())->impersonationPolicy);
    }

    public function testThemeSettingsTravelInGuestAndSignedInHandshakes(): void
    {
        $settings = ['switchingEnabled' => false, 'defaultTheme' => 'dark'];
        $guest = new HandshakeResponseSignalData()->withThemeSettings($settings);
        $signedIn = new HandshakeResponseSignalData(selfId: 41, selfName: 'Maria')->withThemeSettings($settings);

        $this->assertSame($settings, $guest->toArray()['data']['themeSettings']);
        $this->assertSame($settings, HandshakeResponseSignalData::fromArray($guest->toArray())->themeSettings);
        $this->assertSame($settings, HandshakeResponseSignalData::fromArray($signedIn->toArray())->themeSettings);
        $this->assertNull((new HandshakeResponseSignalData())->themeSettings);
    }

    public function testThemeSettingsSurviveEveryOtherStamp(): void
    {
        $settings = ['switchingEnabled' => false, 'defaultTheme' => 'light'];
        $data = new HandshakeResponseSignalData(selfId: 41, selfName: 'Maria')
            ->withThemeSettings($settings)
            ->withSessionContext(self::SERVER_TIME_MS, null, self::CODE_DELIVERY, self::AUTH_METHODS, self::PASSKEY_ALLOWS_UNPROVEN)
            ->withPendingAck(null)
            ->withAccountBlocked(null)
            ->withAccountStanding(self::STANDING)
            ->withAdminViewMode(true)
            ->withImpersonationPolicy([
                'viewOnly' => false,
                'carryAdmin' => true,
                'allowed' => true,
                'accountAccess' => false,
                'blocked' => true,
                'frozen' => true,
                'equal' => true,
            ]);

        $this->assertSame($settings, $data->themeSettings);
        $this->assertSame($settings, HandshakeResponseSignalData::fromArray($data->toArray())->themeSettings);
    }

    public function testRoundtripRejectsAnIncompleteThemeSettingsNode(): void
    {
        $payload = new HandshakeResponseSignalData()->toArray();
        $payload['data']['themeSettings'] = ['switchingEnabled' => true];

        $this->expectException(InvalidFormatException::class);

        HandshakeResponseSignalData::fromArray($payload);
    }

    /** @return iterable<string, array{?string}> Stored time zones and an explicit reset */
    public static function personalTimeZones(): iterable
    {
        yield 'IANA zone' => ['Europe/Warsaw'];
        yield 'historical alias' => ['Europe/Kiev'];
        yield 'automatic' => [null];
    }

    /** @param ?string $timeZone Personal choice or automatic selection */
    #[DataProvider('personalTimeZones')]
    public function testPersonalTimeZoneSurvivesRoundtripAndEachResponseStamp(?string $timeZone): void
    {
        $identity = new HandshakeResponseSignalData(
            selfId: 7,
            selfName: 'Person',
            impersonatorId: 9,
            impersonatorName: 'Admin',
            selfTimeZone: $timeZone,
        );
        $stamps = [
            static fn ($dto) => $dto->withAccessLossReason(null),
            static fn ($dto) => $dto->withPendingAck(SessionAck::SIGNED_IN),
            static fn ($dto) => $dto->withAccountBlocked(null),
            static fn ($dto) => $dto->withAccountStanding(self::STANDING),
            static fn ($dto) => $dto->withAdminViewMode(true),
            static fn ($dto) => $dto->withImpersonationPolicy([
                'viewOnly' => false, 'carryAdmin' => true, 'allowed' => true, 'accountAccess' => false,
                'blocked' => true, 'frozen' => true, 'equal' => true,
            ]),
            static fn ($dto) => $dto->withThemeSettings(['switchingEnabled' => true, 'defaultTheme' => 'system']),
            static fn ($dto) => $dto->withSessionContext(
                self::SERVER_TIME_MS, null, self::CODE_DELIVERY, self::AUTH_METHODS, self::PASSKEY_ALLOWS_UNPROVEN,
            ),
        ];
        $responses = [$identity];
        $chain = $identity;
        foreach ($stamps as $stamp) {
            $responses[] = $stamp($identity);
            $chain = $stamp($chain);
            $responses[] = $chain;
        }

        foreach ($responses as $response) {
            $payload = $response->toArray();
            self::assertSame([
                'id' => 7,
                'name' => 'Person',
                'admin' => false,
                'photo' => null,
                'themePick' => null,
                'themePickInitialized' => false,
                'timeZone' => $timeZone,
            ], $payload['entities']['currentUser']);
            self::assertSame(['id' => 9, 'name' => 'Admin'], $payload['entities']['impersonatedBy']);
            self::assertArrayNotHasKey('timeZone', $payload['data']);
            self::assertSame($timeZone, $response->selfTimeZone);
            $restored = HandshakeResponseSignalData::fromArray($payload);
            self::assertSame($timeZone, $restored->selfTimeZone);
            self::assertSame($payload, $restored->toArray());
        }
    }

    public function testOlderCurrentUserWithoutTimeZoneReadsAsAutomatic(): void
    {
        $payload = new HandshakeResponseSignalData(selfId: 7)->toArray();
        unset($payload['entities']['currentUser']['timeZone']);

        $restored = HandshakeResponseSignalData::fromArray($payload);

        self::assertNull($restored->selfTimeZone);
        self::assertArrayHasKey('timeZone', $restored->toArray()['entities']['currentUser']);
        self::assertNull($restored->toArray()['entities']['currentUser']['timeZone']);
    }

    public function testGuestDoesNotExposeAPersonalTimeZone(): void
    {
        $payload = new HandshakeResponseSignalData(selfTimeZone: 'Europe/Warsaw')->toArray();

        self::assertNull($payload['entities']['currentUser']);
        self::assertNull($payload['entities']['impersonatedBy']);
        self::assertArrayNotHasKey('timeZone', $payload['data']);
        $restored = HandshakeResponseSignalData::fromArray($payload);
        self::assertNull($restored->selfTimeZone);
        self::assertSame($payload, $restored->toArray());
    }

    /** @return iterable<string, array{mixed}> Values that cannot carry a nullable string */
    public static function malformedTimeZones(): iterable
    {
        yield 'integer' => [42];
        yield 'boolean' => [false];
        yield 'array' => [[]];
    }

    /** @param mixed $timeZone Invalid wire type */
    #[DataProvider('malformedTimeZones')]
    public function testPersonalTimeZoneRejectsOtherWireTypes(mixed $timeZone): void
    {
        $payload = new HandshakeResponseSignalData(selfId: 7)->toArray();
        $payload['entities']['currentUser']['timeZone'] = $timeZone;

        $this->expectException(InvalidFormatException::class);

        HandshakeResponseSignalData::fromArray($payload);
    }

    public function testTheImpersonationPolicySurvivesEveryOtherAxisOfTheStamp(): void
    {
        $policy = [
            'viewOnly' => false,
            'carryAdmin' => true,
            'allowed' => true,
            'accountAccess' => false,
            'blocked' => true,
            'frozen' => true,
            'equal' => true,
        ];
        $data = new HandshakeResponseSignalData(selfId: 41, selfName: 'Maria', selfAdmin: false)
            ->withImpersonationPolicy($policy)
            ->withSessionContext(self::SERVER_TIME_MS, null, self::CODE_DELIVERY, self::AUTH_METHODS, self::PASSKEY_ALLOWS_UNPROVEN)
            ->withPendingAck(null)
            ->withAccountBlocked(null)
            ->withAccountStanding(self::STANDING)
            ->withAdminViewMode(true);

        $this->assertSame($policy, $data->impersonationPolicy);
        $this->assertTrue($data->adminViewMode);
    }

    public function testRoundtripRejectsAnImpersonationPolicyWithoutItsSevenFlags(): void
    {
        $payload = new HandshakeResponseSignalData()->toArray();
        $payload['data']['impersonationPolicy'] = ['viewOnly' => true];

        $this->expectException(InvalidFormatException::class);

        HandshakeResponseSignalData::fromArray($payload);
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
