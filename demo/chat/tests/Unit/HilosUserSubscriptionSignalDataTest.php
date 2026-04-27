<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Core\Router\DTO\HilosUserSubscriptionSignalData;
use Hilos\Core\Router\DTO\FrontendChangesDTO;
use Hilos\Core\Router\SignalDataInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see HilosUserSubscriptionSignalData}.
 */
final class HilosUserSubscriptionSignalDataTest extends TestCase
{
    public function testImplementsSignalDataInterface(): void
    {
        $dto = HilosUserSubscriptionSignalData::fromFrontendChanges(7, new FrontendChangesDTO());

        $this->assertInstanceOf(SignalDataInterface::class, $dto);
    }

    public function testPayloadContainsUserIdAndEntities(): void
    {
        $dto = HilosUserSubscriptionSignalData::fromFrontendChanges(
            7,
            new FrontendChangesDTO(updates: ['users' => [['id' => 7, 'name' => 'Ada']]]),
        );

        $this->assertSame(
            [
                'userId' => 7,
                'frontend' => [
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
        $dto = HilosUserSubscriptionSignalData::fromFrontendChanges(404, new FrontendChangesDTO());

        $this->assertSame(['userId' => 404, 'frontend' => []], $dto->toArray());
    }

    public function testRoundtripPreservesPayload(): void
    {
        $original = HilosUserSubscriptionSignalData::fromFrontendChanges(
            7,
            new FrontendChangesDTO(updates: ['users' => [['id' => 7, 'name' => 'Ada']]]),
        );

        $restored = HilosUserSubscriptionSignalData::fromArray($original->toArray());

        $this->assertInstanceOf(HilosUserSubscriptionSignalData::class, $restored);
        $this->assertSame($original->toArray(), $restored->toArray());
    }

    public function testMalformedRoundtripPayloadFallsBackToEmptyShape(): void
    {
        $restored = HilosUserSubscriptionSignalData::fromArray([
            'userId' => '7',
            'frontend' => 'bad',
        ]);

        $this->assertSame(['userId' => 0, 'frontend' => []], $restored->toArray());
    }
}
