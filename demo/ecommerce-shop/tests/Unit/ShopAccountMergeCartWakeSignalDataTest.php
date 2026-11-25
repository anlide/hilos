<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Unit;

use Demo\EcommerceShop\Core\Router\DTO\ShopAccountMergeCartWakeSignalData;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\AgentSignalData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShopAccountMergeCartWakeSignalDataTest extends TestCase
{
    public function testOperationIdSurvivesTheAgentEnvelope(): void
    {
        $payload = ['operationId' => 82];
        $wake = ShopAccountMergeCartWakeSignalData::fromArray($payload);
        $delivered = AgentSignalData::fromJson((new AgentSignalData($wake))->toJson())->data;

        self::assertInstanceOf(ShopAccountMergeCartWakeSignalData::class, $delivered);
        self::assertSame($payload, $delivered->toArray());
    }

    /** @param array<array-key, mixed> $payload Invalid wake payload */
    #[DataProvider('invalidPayloads')]
    public function testWakeRequiresExactlyOnePositiveIntegerOperationId(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        ShopAccountMergeCartWakeSignalData::fromArray($payload);
    }

    /** @return iterable<string, array{array<array-key, mixed>}> Invalid wake payloads */
    public static function invalidPayloads(): iterable
    {
        yield 'missing' => [[]];
        yield 'wrong key' => [['userId' => 82]];
        yield 'extra key' => [['operationId' => 82, 'userId' => 92]];
        yield 'list' => [[82]];
        foreach (['zero' => 0, 'negative' => -1, 'string' => '82', 'float' => 82.0, 'bool' => true, 'null' => null] as $name => $value) {
            yield $name => [['operationId' => $value]];
        }
    }
}
