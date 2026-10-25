<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\Communications\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Pages\Communications\DTO\CommunicationsChannelSummary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommunicationsChannelSummaryTest extends TestCase
{
    public function testArrayAndJsonRoundtripsKeepTheExactWireContract(): void
    {
        $summary = new CommunicationsChannelSummary('email', 'Email', true, false, 'smtp', 2, 7, 1, 3);
        $expected = [
            'channel' => 'email',
            'label' => 'Email',
            'enabled' => true,
            'configured' => false,
            'driver' => 'smtp',
            'missingFields' => 2,
            'fieldCount' => 7,
            'secretFieldCount' => 1,
            'overriddenFieldCount' => 3,
        ];

        self::assertSame($expected, $summary->toArray());
        self::assertSame($expected, CommunicationsChannelSummary::fromArray($expected)->toArray());
        self::assertSame($expected, CommunicationsChannelSummary::fromJson($summary->toJson())->toArray());
    }

    public function testNullDriverAndEmptyLabelStayDistinctFromFallbackText(): void
    {
        $summary = new CommunicationsChannelSummary('custom', '', false, true, null, 0, 0, 0, 0);
        $restored = CommunicationsChannelSummary::fromArray($summary->toArray());

        self::assertSame('', $restored->label);
        self::assertNull($restored->driver);
        self::assertSame($summary->toArray(), $restored->toArray());
    }

    /** @param array<string, mixed> $payload Broken summary */
    #[DataProvider('invalidPayloads')]
    public function testMalformedPayloadsAreRefused(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);

        CommunicationsChannelSummary::fromArray($payload);
    }

    /** @return iterable<string, array{array<string, mixed>}> Missing and mistyped summary fields */
    public static function invalidPayloads(): iterable
    {
        $valid = new CommunicationsChannelSummary('email', 'Email', true, true, null, 0, 0, 0, 0)->toArray();
        foreach (['channel', 'label', 'enabled', 'configured', 'missingFields', 'fieldCount', 'secretFieldCount', 'overriddenFieldCount'] as $key) {
            $missing = $valid;
            unset($missing[$key]);
            yield 'missing ' . $key => [$missing];
            yield 'mistyped ' . $key => [array_replace($valid, [$key => []])];
        }
        yield 'null label' => [array_replace($valid, ['label' => null])];
        yield 'mistyped driver' => [array_replace($valid, ['driver' => false])];
        yield 'coerced boolean' => [array_replace($valid, ['enabled' => 'true'])];
        yield 'coerced count' => [array_replace($valid, ['fieldCount' => '0'])];
    }
}
