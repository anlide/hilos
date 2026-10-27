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
            'remote end' => ['session_ended'],
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

            $older = $frame->toArray();
            unset($older['accessLossReason']);
            self::assertNull(SessionStateSignalData::fromArray($older)->accessLossReason);
        }
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

        SessionStateSignalData::fromArray(['sessionToken' => 'token', 'sessionId' => 17, 'accessLossReason' => $reason]);
    }

    public function testAccessLossReasonCannotBeAttachedToAnAuthenticatedIdentity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SessionStateSignalData('token', 17, 41, ['socket'])->withAccessLossReason('session_ended');
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
