<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\Session\DTO\UserSessionsEndRequestSignalData;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The ask a person's Sessions tab sends the sessions library survives the wire whole (HIL-1662).
 *
 * No session names every session of the person, a positive id names one, and nothing else names
 * anything: the person and the session are both refused at zero or below. Who asks is not a key
 * of the frame at all.
 */
final class UserSessionsEndRequestSignalDataTest extends TestCase
{
    /** @return array<string, array{?int}> Every session of the person, and one */
    public static function targets(): array
    {
        return ['every session' => [null], 'one session' => [41]];
    }

    /** @param ?int $sessionId Session the ask names, or null for every session */
    #[DataProvider('targets')]
    public function testTheAskCarriesExactlyItsKeysAndRoundTrips(?int $sessionId): void
    {
        $ask = new UserSessionsEndRequestSignalData(
            userId: 7,
            sessionId: $sessionId,
            replySignal: HilosSignalConstants::HILOS_USER_SESSIONS_END_DONE,
            acceptKey: 'key-1',
            requestId: 'req-1',
            action: 'hilos_user_session_end',
            successMessage: null,
        );

        $wire = json_decode((string)json_encode($ask->toArray()), true);
        self::assertIsArray($wire);
        self::assertSame([
            'userId' => 7,
            'sessionId' => $sessionId,
            'replySignal' => 'hilos_user_sessions_end_done',
            'acceptKey' => 'key-1',
            'requestId' => 'req-1',
            'action' => 'hilos_user_session_end',
            'successMessage' => null,
        ], $wire);
        self::assertEquals($ask, UserSessionsEndRequestSignalData::fromArray($wire));
    }

    public function testAnAbsentSessionIdAsksForEverySession(): void
    {
        $ask = UserSessionsEndRequestSignalData::fromArray([
            'userId' => 7,
            'replySignal' => HilosSignalConstants::HILOS_USER_SESSIONS_END_DONE,
            'acceptKey' => 'key-1',
            'action' => 'hilos_user_sessions_end_all',
        ]);

        self::assertNull($ask->sessionId);
        self::assertNull($ask->requestId);
        self::assertNull($ask->successMessage);
    }

    /** @return array<string, array{int, ?int}> A person or a session named by an id that names nothing */
    public static function invalidIds(): array
    {
        return [
            'person zero' => [0, null],
            'person below zero' => [-7, 41],
            'session zero' => [7, 0],
            'session below zero' => [7, -41],
        ];
    }

    /**
     * @param int $userId Person the ask names
     * @param ?int $sessionId Session the ask names, or null for every session
     */
    #[DataProvider('invalidIds')]
    public function testAnIdThatNamesNothingIsRefused(int $userId, ?int $sessionId): void
    {
        $this->expectException(InvalidFormatException::class);

        UserSessionsEndRequestSignalData::fromArray([
            'userId' => $userId,
            'sessionId' => $sessionId,
            'replySignal' => HilosSignalConstants::HILOS_USER_SESSIONS_END_DONE,
            'acceptKey' => 'key-1',
            'action' => 'hilos_user_session_end',
        ]);
    }

    public function testASessionIdThatIsNoIntegerIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        UserSessionsEndRequestSignalData::fromArray([
            'userId' => 7,
            'sessionId' => '41',
            'replySignal' => HilosSignalConstants::HILOS_USER_SESSIONS_END_DONE,
            'acceptKey' => 'key-1',
            'action' => 'hilos_user_session_end',
        ]);
    }
}
