<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Core\Router\DTO\UserPresenceSignalData;
use Demo\Chat\Core\Router\DTO\UserPresenceEmitPayload;
use Hilos\Core\Router\DTO\FrontendChangesDTO;
use Hilos\Core\Router\SignalDataInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see UserPresenceSignalData}.
 */
final class UserPresenceSignalDataTest extends TestCase
{
    public function testImplementsSignalDataInterface(): void
    {
        $dto = UserPresenceSignalData::fromFrontendChanges(new FrontendChangesDTO());

        $this->assertInstanceOf(SignalDataInterface::class, $dto);
    }

    public function testPayloadContainsEntities(): void
    {
        $dto = UserPresenceSignalData::fromFrontendChanges(
            new FrontendChangesDTO(updates: ['userPresence' => [['userId' => 7, 'presence' => 'online']]]),
        );

        $this->assertSame(
            [
                'frontend' => [
                    'updates' => [
                        'userPresence' => [
                            ['userId' => 7, 'presence' => 'online'],
                        ],
                    ],
                ],
            ],
            $dto->toArray(),
        );
    }

    public function testRoundtripPreservesPayload(): void
    {
        $original = UserPresenceSignalData::fromFrontendChanges(
            new FrontendChangesDTO(updates: ['userPresence' => [['userId' => 7, 'presence' => 'offline']]]),
        );

        $restored = UserPresenceSignalData::fromArray($original->toArray());

        $this->assertInstanceOf(UserPresenceSignalData::class, $restored);
        $this->assertSame($original->toArray(), $restored->toArray());
    }

    public function testMalformedRoundtripPayloadFallsBackToEmptyShape(): void
    {
        $restored = UserPresenceSignalData::fromArray(['frontend' => 'bad']);

        $this->assertSame(['frontend' => []], $restored->toArray());
    }

    public function testEmitPayloadRoundtripPreservesFrontendVariants(): void
    {
        $original = UserPresenceEmitPayload::fromFrontendChanges(
            7,
            new FrontendChangesDTO(updates: [
                'userPresence' => [['userId' => 7, 'presence' => 'online']],
            ]),
            new FrontendChangesDTO(updates: [
                'userPresence' => [['userId' => 7, 'presence' => 'online']],
                'userConnectionStats' => [['userId' => 7, 'onlineSessionCount' => 2]],
            ]),
        );

        $restored = UserPresenceEmitPayload::fromArray($original->toArray());

        $this->assertSame(7, $restored->userId);
        $this->assertSame(
            ['updates' => ['userPresence' => [['userId' => 7, 'presence' => 'online']]]],
            $restored->frontend()->toArray(),
        );
        $this->assertSame(
            [
                'updates' => [
                    'userPresence' => [['userId' => 7, 'presence' => 'online']],
                    'userConnectionStats' => [['userId' => 7, 'onlineSessionCount' => 2]],
                ],
            ],
            $restored->statsFrontend()->toArray(),
        );
    }
}
