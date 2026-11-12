<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Users\DTO\UserSecondFactorTrustRestrictSignalData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserSecondFactorTrustRestrictSignalDataTest extends TestCase
{
    public function testAllFieldsRoundtrip(): void
    {
        $wire = self::payload();
        $ask = UserSecondFactorTrustRestrictSignalData::fromArray($wire);
        self::assertSame($wire, $ask->toArray());
        self::assertSame($wire, UserSecondFactorTrustRestrictSignalData::fromJson($ask->toJson())->toArray());
    }

    /** @return iterable<string, array{string, mixed}> Invalid strict ids */
    public static function invalidIds(): iterable
    {
        foreach (['userId', 'restrictionId'] as $key) {
            foreach ([0, -1, '7', false, 7.0, null] as $value) {
                yield $key . ':' . json_encode($value) => [$key, $value];
            }
        }
    }

    /**
     * @param string $key Id field
     * @param mixed $value Malformed id
     */
    #[DataProvider('invalidIds')]
    public function testIdsArePositiveIntegersWithoutCoercion(string $key, mixed $value): void
    {
        $wire = self::payload();
        $wire[$key] = $value;
        $this->expectException(InvalidFormatException::class);
        UserSecondFactorTrustRestrictSignalData::fromArray($wire);
    }

    /** @return iterable<string, array{mixed}> Reply names the frame cannot be answered under */
    public static function invalidReplySignals(): iterable
    {
        yield 'missing' => [null];
        yield 'not a string' => [7];
    }

    /** @param mixed $value Malformed reply name */
    #[DataProvider('invalidReplySignals')]
    public function testTheReplyNameIsRequired(mixed $value): void
    {
        $wire = self::payload();
        $wire['replySignal'] = $value;
        $this->expectException(InvalidFormatException::class);
        UserSecondFactorTrustRestrictSignalData::fromArray($wire);
    }

    /** @return array<string, int|string> Complete restriction ask as a sender sends it */
    private static function payload(): array
    {
        return [
            'userId' => 7,
            'restrictionId' => 19,
            'replySignal' => 'test_trust_restrict_done',
        ];
    }
}
