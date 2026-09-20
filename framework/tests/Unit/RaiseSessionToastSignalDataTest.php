<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\Session\DTO\RaiseSessionToastSignalData;
use Hilos\Auth\Session\SessionToastSeverity;
use PHPUnit\Framework\TestCase;

/**
 * The raise of a session toast carries WHO the card is for, and carries it both ways (HIL-1062).
 *
 * The addressee is the one field of this payload that may legally be null, and null is a real
 * answer rather than an absent one: it says nobody was at the keyboard when the action was
 * taken, and only a session with nobody in it may be shown such a card. A round trip that lost
 * the key would therefore not fail loudly - it would turn "for that administrator" into "for
 * whoever is sitting there", which is the very defect this leaf closes. Hence the third
 * assertion: the key is written even when it is null.
 */
final class RaiseSessionToastSignalDataTest extends TestCase
{
    private const string SESSION_TOKEN_HASH = '3f2a1c4b5d6e7f80112233445566778899aabbccddeeff001122334455667788';

    private const string MESSAGE = 'Backup "2026-09-20_04-00-00" is ready.';

    public function testAPayloadNamingAPersonComesBackNamingThatPerson(): void
    {
        $dto = RaiseSessionToastSignalData::fromArray($this->payload(7));

        $this->assertSame(7, $dto->addresseeUserId);
        $this->assertSame(self::SESSION_TOKEN_HASH, $dto->sessionTokenHash);
        $this->assertSame(self::MESSAGE, $dto->message);
        $this->assertSame(SessionToastSeverity::SUCCESS, $dto->severity);
    }

    public function testAPayloadNamingNobodyComesBackNamingNobody(): void
    {
        $dto = RaiseSessionToastSignalData::fromArray($this->payload(null));

        $this->assertNull($dto->addresseeUserId);
    }

    public function testTheKeyIsWrittenEvenWhenNobodyIsNamed(): void
    {
        $dto = RaiseSessionToastSignalData::fromArray($this->payload(null));

        $this->assertArrayHasKey('addresseeUserId', $dto->toArray());
        $this->assertNull($dto->toArray()['addresseeUserId']);
    }

    public function testThePersonSurvivesTheRoundTripThroughAnArray(): void
    {
        $dto = RaiseSessionToastSignalData::fromArray($this->payload(7));

        $this->assertSame(7, RaiseSessionToastSignalData::fromArray($dto->toArray())->addresseeUserId);
    }

    /**
     * @param ?int $addresseeUserId Person the card is for, or null when nobody was at the keyboard
     * @return array<string, mixed> Payload as a sender queues it
     */
    private function payload(?int $addresseeUserId): array
    {
        return [
            'sessionTokenHash' => self::SESSION_TOKEN_HASH,
            'addresseeUserId' => $addresseeUserId,
            'message' => self::MESSAGE,
            'severity' => SessionToastSeverity::SUCCESS->value,
            'source' => 'Backup',
            'destination' => '/hilos/backup',
        ];
    }
}
