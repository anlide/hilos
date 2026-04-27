<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Core\Router\DTO\UserPresenceSignalData;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Router\SignalDataInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see UserPresenceSignalData}.
 */
final class UserPresenceSignalDataTest extends TestCase
{
    public function testImplementsSignalDataInterface(): void
    {
        $dto = UserPresenceSignalData::fromEntities(new EntitiesChangesDTO());

        $this->assertInstanceOf(SignalDataInterface::class, $dto);
    }

    public function testPayloadContainsEntities(): void
    {
        $dto = UserPresenceSignalData::fromEntities(
            new EntitiesChangesDTO(updates: ['users' => [['id' => 7, 'presence' => 'online']]]),
        );

        $this->assertSame(
            [
                'entities' => [
                    'updates' => [
                        'users' => [
                            ['id' => 7, 'presence' => 'online'],
                        ],
                    ],
                ],
            ],
            $dto->toArray(),
        );
    }

    public function testRoundtripPreservesPayload(): void
    {
        $original = UserPresenceSignalData::fromEntities(
            new EntitiesChangesDTO(updates: ['users' => [['id' => 7, 'presence' => 'offline']]]),
        );

        $restored = UserPresenceSignalData::fromArray($original->toArray());

        $this->assertInstanceOf(UserPresenceSignalData::class, $restored);
        $this->assertSame($original->toArray(), $restored->toArray());
    }

    public function testMalformedRoundtripPayloadFallsBackToEmptyShape(): void
    {
        $restored = UserPresenceSignalData::fromArray(['entities' => 'bad']);

        $this->assertSame(['entities' => []], $restored->toArray());
    }
}
