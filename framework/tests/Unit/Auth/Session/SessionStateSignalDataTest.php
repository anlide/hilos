<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Session;

use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the session row id and the "Access closed" card carried from the session holder to project agents.
 */
final class SessionStateSignalDataTest extends TestCase
{
    /** @return array<string, array{?string}> Reasons and an explicit absence */
    public static function accessLossReasons(): array
    {
        return [
            'administrator' => ['ended_by_administrator'],
            'another browser' => ['ended_from_another_browser'],
            'password changed' => ['password_changed'],
            'password recovered' => ['password_recovered'],
            'live block' => ['account_blocked'],
            'no loss' => [null],
        ];
    }

    /** @param ?string $reason Live explanation, independent of addressing and action metadata */
    #[DataProvider('accessLossReasons')]
    public function testAccessLossReasonSurvivesEveryCopyAndRoundtrip(?string $reason): void
    {
        foreach ([[], ['socket-a'], ['socket-a', 'socket-b']] as $keys) {
            $frame = new SessionStateSignalData(
                sessionToken: 'token',
                sessionId: 17,
                userId: null,
                acceptKeys: $keys,
                pendingAck: 'auth_registered',
                rotationTicket: count($keys) <= 1 ? 'ticket' : null,
                requestId: count($keys) <= 1 ? 'request' : null,
                action: count($keys) <= 1 ? 'action' : null,
                outcome: count($keys) <= 1 ? ['ok' => true] : null,
                accountBlocked: ['identifier' => 'ada@example.test', 'dataExport' => null],
                accessLossReason: $reason,
            );
            $copies = [
                $frame->withAccountBlocked($frame->accountBlocked),
                $frame->withAccountStanding(null),
                $frame->withAccessLossReason($reason),
            ];
            foreach ($copies as $copy) {
                self::assertEquals($frame, $copy);
                self::assertSame($reason, $copy->toArray()['accessLossReason']);
                self::assertEquals($frame, SessionStateSignalData::fromArray($copy->toArray()));
            }
            self::assertNull($frame->withAccessLossReason(null)->accessLossReason);
            self::assertSame($reason, $frame->accessLossReason);
        }
    }

    public function testMissingAccessLossReasonIsRefused(): void
    {
        $frame = new SessionStateSignalData('token', 17, null, ['socket']);
        $payload = $frame->toArray();
        unset($payload['accessLossReason']);
        $this->expectException(InvalidFormatException::class);

        SessionStateSignalData::fromArray($payload);
    }

    /** @return array<string, array{mixed, class-string}> Invalid reasons and their refusal family */
    public static function malformedAccessLossReasons(): array
    {
        return [
            'session ended' => ['session_ended', InvalidArgumentException::class],
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

        SessionStateSignalData::fromArray(['sessionToken' => 'token', 'sessionId' => 17, 'accessLossReason' => $reason]);
    }

    public function testAccessLossReasonCannotBeAttachedToAnAuthenticatedIdentity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SessionStateSignalData('token', 17, 41, ['socket'])->withAccessLossReason('ended_by_administrator');
    }

    public function testParserRefusesAccessLossReasonBesideAnAuthenticatedIdentity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SessionStateSignalData::fromArray([
            'sessionToken' => 'token',
            'sessionId' => 17,
            'userId' => 41,
            'accessLossReason' => 'account_blocked',
        ]);
    }

    /**
     * @param ?string $message Server words, or an explicit quiet resume
     */
    #[DataProvider('pendingMessages')]
    public function testPendingMessageReachesTheHandshakeThroughEveryCopy(?string $message): void
    {
        $step = [
            'identifier' => 'ada@example.com',
            'kind' => 'email',
            'intent' => 'register',
            'step' => 'code',
            'channel' => null,
            'expiresAt' => 1_700_000_600_000,
            'code' => null,
            'message' => $message,
            'acceptedRevisions' => ['terms' => 'terms-1'],
        ];
        $state = SessionStateSignalData::fromArray(
            new SessionStateSignalData('token', 17, null, ['socket-a', 'socket-b'], pendingAuthStep: $step)
                ->withAccessLossReason(null)
                ->withAccountBlocked(null)
                ->withAccountStanding(null)
                ->toArray(),
        );
        self::assertSame(['socket-a', 'socket-b'], $state->acceptKeys);
        self::assertSame($step, $state->pendingAuthStep);

        // sendHandshakeResponse stamps the session's node onto the identity with this same chain.
        foreach ([null, 41] as $userId) {
            $handshake = new HandshakeResponseSignalData(selfId: $userId)
                ->withSessionContext(1_700_000_000_000, $state->pendingAuthStep, ['email' => true, 'phone' => false], [], false)
                ->withPendingAck(null)
                ->withAccountBlocked(null)
                ->withAccountStanding(null)
                ->withAdminViewMode(false)
                ->withImpersonationPolicy([
                    'viewOnly' => true,
                    'carryAdmin' => false,
                    'allowed' => false,
                    'accountAccess' => false,
                    'blocked' => false,
                    'frozen' => false,
                    'equal' => false,
                ])
                ->withThemeSettings(['switchingEnabled' => true, 'defaultTheme' => 'light']);

            self::assertSame($step, $handshake->pendingAuthStep);
            self::assertSame($step, HandshakeResponseSignalData::fromArray($handshake->toArray())->pendingAuthStep);
        }
    }

    /** @return array<string, array{?string}> Notice and quiet resume */
    public static function pendingMessages(): array
    {
        return [
            'server notice' => ['A server-authored notice without a client dictionary entry.'],
            'quiet resume' => [null],
        ];
    }

    /**
     * @param array<string, mixed> $messageField Missing or mistyped message
     */
    #[DataProvider('malformedPendingMessages')]
    public function testMalformedPendingMessageIsRefused(array $messageField): void
    {
        $this->expectException(InvalidFormatException::class);

        SessionStateSignalData::fromArray([
            'sessionToken' => 'token',
            'sessionId' => 17,
            'pendingAuthStep' => ['step' => 'identifier', 'code' => 'identifier_taken'] + $messageField,
            'accessLossReason' => null,
        ]);
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

    public function testRoundtripKeepsTheSessionId(): void
    {
        $frame = new SessionStateSignalData('token', 17, 41, ['accept-key']);

        self::assertEquals($frame, SessionStateSignalData::fromArray($frame->toArray()));
    }

    public function testNullSessionIdIsStillAnExplicitField(): void
    {
        $frame = new SessionStateSignalData('token', null, null, ['accept-key']);

        self::assertArrayHasKey(SessionStateSignalData::sessionId, $frame->toArray());
        self::assertNull(SessionStateSignalData::fromArray($frame->toArray())->sessionId);
    }

    public function testRoundtripKeepsTheBlockedCard(): void
    {
        $frame = new SessionStateSignalData('token', 17, null, ['accept-key'])
            ->withAccountBlocked(['identifier' => 'maria@example.com', 'dataExport' => null]);

        $read = SessionStateSignalData::fromArray($frame->toArray());

        self::assertSame(['identifier' => 'maria@example.com', 'dataExport' => null], $read->accountBlocked);
        self::assertEquals($frame, $read);
    }

    public function testBlockedCardWithoutAnAddressSurvivesTheRoundtrip(): void
    {
        $frame = new SessionStateSignalData('token', 17, null, ['accept-key'])->withAccountBlocked(['identifier' => null, 'dataExport' => null]);

        self::assertSame(['identifier' => null, 'dataExport' => null], SessionStateSignalData::fromArray($frame->toArray())->accountBlocked);
    }

    public function testFrameWithoutACardCarriesNull(): void
    {
        $frame = new SessionStateSignalData('token', 17, 41, ['accept-key']);

        self::assertArrayHasKey(SessionStateSignalData::accountBlocked, $frame->toArray());
        self::assertNull(SessionStateSignalData::fromArray($frame->toArray())->accountBlocked);
    }

    public function testRoundtripKeepsTheStandingBesideTheCard(): void
    {
        $standing = [
            'shown' => 'deletion_scheduled',
            'blocked' => false,
            'frozen' => false,
            'deletionEffectiveAt' => 1_767_225_600_000,
            'deletionGraceDays' => 30,
            'lapsed' => [],
            'window' => [],
            'mergedInto' => null,
            'mergedIntoName' => null,
        ];
        $frame = new SessionStateSignalData('token', 17, 41, ['accept-key'])
            ->withAccountStanding($standing)
            ->withAccountBlocked(null);

        $read = SessionStateSignalData::fromArray($frame->toArray());

        self::assertSame($standing, $read->accountStanding);
        self::assertEquals($frame, $read);
        self::assertNull(SessionStateSignalData::fromArray(new SessionStateSignalData('token', 17, null, [])->toArray())->accountStanding);

        unset($standing['deletionGraceDays']);
        $legacy = new SessionStateSignalData('token', 17, 41, ['accept-key'])->withAccountStanding($standing);
        $readLegacy = SessionStateSignalData::fromArray($legacy->toArray());
        self::assertNull($readLegacy->accountStanding['deletionGraceDays']);
        self::assertSame(1_767_225_600_000, $readLegacy->accountStanding['deletionEffectiveAt']);
    }

    public function testMissingSessionIdIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        SessionStateSignalData::fromArray([
            'sessionToken' => 'token',
            'userId' => null,
            'acceptKeys' => ['accept-key'],
            'accessLossReason' => null,
        ]);
    }

    public function testFrameWithAnAnswerAndMultipleSocketsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SessionStateSignalData(
            sessionToken: 'token',
            sessionId: 17,
            userId: 41,
            acceptKeys: ['socket-a', 'socket-b'],
            requestId: 'req-1',
            action: 'hilos_dismiss_session_ack',
        );
    }

    public function testFrameWithARotationTicketAndMultipleSocketsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SessionStateSignalData(
            sessionToken: 'token',
            sessionId: 17,
            userId: null,
            acceptKeys: ['socket-a', 'socket-b'],
            rotationTicket: 'ticket-1',
        );
    }

    public function testFrameWithAnOutcomeAndMultipleSocketsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SessionStateSignalData(
            sessionToken: 'token',
            sessionId: 17,
            userId: 41,
            acceptKeys: ['socket-a', 'socket-b'],
            outcome: ['status' => 'ok'],
        );
    }

    /** @return array<string, array{?string}> Headers a browser sends and an explicit absence */
    public static function acceptLanguageHeaders(): array
    {
        return [
            'weighted list' => ['ru-UA,ru;q=0.9,en;q=0.5'],
            'malformed' => [';;q=x'],
            'no header' => [null],
        ];
    }

    /**
     * The handshake frame passes the library's stamping door, so the browser's header must survive
     * each copy made there and the trip to the project agent; a frame that leaves it out reads null.
     *
     * @param ?string $header Accept-Language of the socket that connected
     */
    #[DataProvider('acceptLanguageHeaders')]
    public function testAcceptLanguageSurvivesEveryCopyAndRoundtrip(?string $header): void
    {
        foreach ([[], ['socket-a']] as $keys) {
            $frame = new SessionStateSignalData(
                sessionToken: 'token',
                sessionId: 17,
                userId: null,
                acceptKeys: $keys,
                pendingAck: 'auth_registered',
                rotationTicket: 'ticket',
                accountBlocked: ['identifier' => 'ada@example.test', 'dataExport' => null],
                acceptLanguage: $header,
            );
            $copies = [
                $frame->withAccountBlocked(null),
                $frame->withAccountStanding(null),
                $frame->withAccessLossReason(null),
            ];
            foreach ($copies as $copy) {
                self::assertSame($header, $copy->acceptLanguage);
                self::assertSame($header, $copy->toArray()[SessionStateSignalData::acceptLanguage]);
                self::assertEquals($copy, SessionStateSignalData::fromArray($copy->toArray()));
            }
            $payload = $frame->toArray();
            unset($payload[SessionStateSignalData::acceptLanguage]);
            self::assertNull(SessionStateSignalData::fromArray($payload)->acceptLanguage);
        }
    }

    public function testFrameWithABrowserHeaderAndMultipleSocketsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SessionStateSignalData(
            sessionToken: 'token',
            sessionId: 17,
            userId: null,
            acceptKeys: ['socket-a', 'socket-b'],
            acceptLanguage: 'ru-UA,ru;q=0.9',
        );
    }

    /** @return array<string, array{?string}> A country the lookup names and an unknown one */
    public static function connectionCountries(): array
    {
        return [
            'known' => ['UA'],
            'unknown' => [null],
        ];
    }

    /**
     * The handshake frame passes the library's stamping door, so the country of the connection must
     * survive each copy made there and the trip to the project agent; a frame that leaves it out reads null.
     *
     * @param ?string $country Country of the socket that connected
     */
    #[DataProvider('connectionCountries')]
    public function testConnectionCountrySurvivesEveryCopyAndRoundtrip(?string $country): void
    {
        foreach ([[], ['socket-a']] as $keys) {
            $frame = new SessionStateSignalData(
                sessionToken: 'token',
                sessionId: 17,
                userId: null,
                acceptKeys: $keys,
                pendingAck: 'auth_registered',
                rotationTicket: 'ticket',
                accountBlocked: ['identifier' => 'ada@example.test', 'dataExport' => null],
                acceptLanguage: 'ru-UA,ru;q=0.9',
                connectionCountry: $country,
            );
            $copies = [
                $frame->withAccountBlocked(null),
                $frame->withAccountStanding(null),
                $frame->withAccessLossReason(null),
            ];
            foreach ($copies as $copy) {
                self::assertSame($country, $copy->connectionCountry);
                self::assertSame($country, $copy->toArray()[SessionStateSignalData::connectionCountry]);
                self::assertEquals($copy, SessionStateSignalData::fromArray($copy->toArray()));
            }
            $payload = $frame->toArray();
            unset($payload[SessionStateSignalData::connectionCountry]);
            self::assertNull(SessionStateSignalData::fromArray($payload)->connectionCountry);
        }
    }

    public function testFrameWithAConnectionCountryAndMultipleSocketsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SessionStateSignalData(
            sessionToken: 'token',
            sessionId: 17,
            userId: null,
            acceptKeys: ['socket-a', 'socket-b'],
            connectionCountry: 'UA',
        );
    }

    /** @return array<string, array{string}> Values that are not an upper-case two-letter code */
    public static function malformedConnectionCountries(): array
    {
        return [
            'lower case' => ['ua'],
            'three letters' => ['UKR'],
            'empty' => [''],
            'trailing newline' => ["UA\n"],
        ];
    }

    /** @param string $country Value the lookup never answers */
    #[DataProvider('malformedConnectionCountries')]
    public function testAConnectionCountryThatIsNotTwoUppercaseLettersIsRefused(string $country): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SessionStateSignalData(
            sessionToken: 'token',
            sessionId: 17,
            userId: null,
            acceptKeys: ['socket-a'],
            connectionCountry: $country,
        );
    }

    /** @return array<string, array{?string}> An Origin the browser sent, a malformed one and none */
    public static function pageOrigins(): array
    {
        return [
            'language address' => ['https://de.example.org'],
            'malformed' => ['not an origin'],
            'none' => [null],
        ];
    }

    /**
     * The handshake frame passes the library's stamping door, so the address of the page must survive
     * each copy made there, raw, and the trip to the project agent; a frame that leaves it out reads null.
     *
     * @param ?string $origin Origin header of the socket that connected
     */
    #[DataProvider('pageOrigins')]
    public function testPageOriginSurvivesEveryCopyAndRoundtrip(?string $origin): void
    {
        foreach ([[], ['socket-a']] as $keys) {
            $frame = new SessionStateSignalData(
                sessionToken: 'token',
                sessionId: 17,
                userId: null,
                acceptKeys: $keys,
                pendingAck: 'auth_registered',
                rotationTicket: 'ticket',
                accountBlocked: ['identifier' => 'ada@example.test', 'dataExport' => null],
                acceptLanguage: 'ru-UA,ru;q=0.9',
                connectionCountry: 'UA',
                pageOrigin: $origin,
            );
            $copies = [
                $frame->withAccountBlocked(null),
                $frame->withAccountStanding(null),
                $frame->withAccessLossReason(null),
            ];
            foreach ($copies as $copy) {
                self::assertSame($origin, $copy->pageOrigin);
                self::assertSame($origin, $copy->toArray()[SessionStateSignalData::pageOrigin]);
                self::assertEquals($copy, SessionStateSignalData::fromArray($copy->toArray()));
            }
            $payload = $frame->toArray();
            unset($payload[SessionStateSignalData::pageOrigin]);
            self::assertNull(SessionStateSignalData::fromArray($payload)->pageOrigin);
        }
    }

    public function testFrameWithAPageOriginAndMultipleSocketsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SessionStateSignalData(
            sessionToken: 'token',
            sessionId: 17,
            userId: null,
            acceptKeys: ['socket-a', 'socket-b'],
            pageOrigin: 'https://de.example.org',
        );
    }

    public function testFrameWithAnAnswerAndOneSocketRoundtrips(): void
    {
        $frame = new SessionStateSignalData(
            sessionToken: 'token',
            sessionId: 17,
            userId: 41,
            acceptKeys: ['socket-a'],
            requestId: 'req-1',
            action: 'hilos_dismiss_session_ack',
        );

        self::assertEquals($frame, SessionStateSignalData::fromArray($frame->toArray()));
    }

    public function testFrameWithAnAnswerAndZeroSocketsRoundtrips(): void
    {
        $frame = new SessionStateSignalData(
            sessionToken: 'token',
            sessionId: 17,
            userId: 41,
            acceptKeys: [],
            requestId: 'req-1',
            action: 'hilos_dismiss_session_ack',
        );

        self::assertEquals($frame, SessionStateSignalData::fromArray($frame->toArray()));
    }

    public function testFromArrayWithAnAnswerAndMultipleSocketsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SessionStateSignalData::fromArray([
            'sessionToken' => 'token',
            'sessionId' => 17,
            'userId' => 41,
            'acceptKeys' => ['socket-a', 'socket-b'],
            'requestId' => 'req-1',
            'action' => 'hilos_dismiss_session_ack',
            'accessLossReason' => null,
        ]);
    }

    public function testFrameWithMultipleSocketsWithoutAnAnswerRoundtrips(): void
    {
        $frame = new SessionStateSignalData(
            sessionToken: 'token',
            sessionId: 17,
            userId: 41,
            acceptKeys: ['socket-a', 'socket-b', 'socket-c'],
        );

        self::assertEquals($frame, SessionStateSignalData::fromArray($frame->toArray()));
    }
}
