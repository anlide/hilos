<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Core\Router\DTO\HilosUserSubscriptionSignalData;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Router\SignalDataInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see HilosUserSubscriptionSignalData}.
 */
final class HilosUserSubscriptionSignalDataTest extends TestCase
{
    public function testImplementsSignalDataInterface(): void
    {
        $dto = HilosUserSubscriptionSignalData::fromEntities(7, new EntitiesChangesDTO());

        $this->assertInstanceOf(SignalDataInterface::class, $dto);
    }

    public function testPayloadContainsUserIdAndEntities(): void
    {
        $dto = HilosUserSubscriptionSignalData::fromEntities(
            7,
            new EntitiesChangesDTO(updates: ['users' => [['id' => 7, 'name' => 'Ada']]]),
        );

        $this->assertSame(
            [
                'userId' => 7,
                'entities' => [
                    'updates' => [
                        'users' => [
                            ['id' => 7, 'name' => 'Ada'],
                        ],
                    ],
                ],
            ],
            $dto->toArray(),
        );
    }

    public function testEmptyEntityEnvelopeIsPreserved(): void
    {
        $dto = HilosUserSubscriptionSignalData::fromEntities(404, new EntitiesChangesDTO());

        $this->assertSame(['userId' => 404, 'entities' => []], $dto->toArray());
    }

    public function testRoundtripPreservesPayload(): void
    {
        $original = HilosUserSubscriptionSignalData::fromEntities(
            7,
            new EntitiesChangesDTO(updates: ['users' => [['id' => 7, 'name' => 'Ada']]]),
        );

        $restored = HilosUserSubscriptionSignalData::fromArray($original->toArray());

        $this->assertInstanceOf(HilosUserSubscriptionSignalData::class, $restored);
        $this->assertSame($original->toArray(), $restored->toArray());
    }

    public function testMalformedRoundtripPayloadFallsBackToEmptyShape(): void
    {
        $restored = HilosUserSubscriptionSignalData::fromArray([
            'userId' => '7',
            'entities' => 'bad',
        ]);

        $this->assertSame(['userId' => 0, 'entities' => []], $restored->toArray());
    }
}
