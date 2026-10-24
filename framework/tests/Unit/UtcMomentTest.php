<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use DateTime;
use DateTimeImmutable;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Utils\Helpers\UtcMoment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UtcMomentTest extends TestCase
{
    public function testFormatPreservesTheInstantAndDoesNotMutateItsInputOrProcessZone(): void
    {
        $previousZone = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Honolulu');
            $moment = new DateTime('2026-10-24T12:34:56.123456+05:45');

            $this->assertSame('2026-10-24T06:49:56.123456Z', UtcMoment::format($moment));
            $this->assertSame('2026-10-24T12:34:56.123456+05:45', $moment->format('Y-m-d\TH:i:s.uP'));
            $this->assertSame('Pacific/Honolulu', date_default_timezone_get());
            $this->assertSame('2026-10-24T06:49:56.123456Z', UtcMoment::format(DateTimeImmutable::createFromMutable($moment)));
        } finally {
            date_default_timezone_set($previousZone);
        }
    }

    #[DataProvider('zonedMoments')]
    public function testNormalizePreservesTheInstantAndFraction(string $input, string $expected): void
    {
        $previousZone = date_default_timezone_get();
        try {
            date_default_timezone_set('Asia/Kathmandu');
            $this->assertSame($expected, UtcMoment::normalize($input));
            $this->assertSame($expected, UtcMoment::normalize($expected));
            $this->assertSame(new DateTimeImmutable($input)->format('U.u'), new DateTimeImmutable($expected)->format('U.u'));
        } finally {
            date_default_timezone_set($previousZone);
        }
    }

    /** @return list<array{string, string}> Explicitly zoned instants and their canonical forms */
    public static function zonedMoments(): array
    {
        return [
            ['2026-01-01T00:00:00Z', '2026-01-01T00:00:00.000000Z'],
            ['2026-01-01T01:00:00+01:00', '2026-01-01T00:00:00.000000Z'],
            ['2025-12-31T19:00:00-05:00', '2026-01-01T00:00:00.000000Z'],
            ['2026-01-01T05:45:00.1+05:45', '2026-01-01T00:00:00.100000Z'],
            ['2026-01-01T00:00:00.12+00:00', '2026-01-01T00:00:00.120000Z'],
            ['2026-01-01T00:00:00.123Z', '2026-01-01T00:00:00.123000Z'],
            ['2026-01-01T00:00:00.1234Z', '2026-01-01T00:00:00.123400Z'],
            ['2026-01-01T00:00:00.12345Z', '2026-01-01T00:00:00.123450Z'],
            ['2026-01-01T00:00:00.123456Z', '2026-01-01T00:00:00.123456Z'],
            ['2024-02-29T00:00:00-03:30', '2024-02-29T03:30:00.000000Z'],
        ];
    }

    #[DataProvider('invalidMoments')]
    public function testNormalizeRefusesAmbiguousOrImpossibleInput(string $input): void
    {
        $this->expectException(InvalidFormatException::class);
        UtcMoment::normalize($input);
    }

    /** @return list<array{string}> Inputs that must never acquire a guessed instant */
    public static function invalidMoments(): array
    {
        return array_map(static fn(string $input): array => [$input], [
            '', '1767225600', '2026-01-01', '2026-01-01 00:00:00', '2026-01-01T00:00:00',
            '2026-02-29T00:00:00Z', '2026-04-31T00:00:00Z', '2026-13-01T00:00:00Z',
            '2026-00-01T00:00:00Z', '2026-01-00T00:00:00Z', '0000-01-01T00:00:00Z',
            '2026-01-01T24:00:00Z', '2026-01-01T23:60:00Z', '2026-01-01T23:59:60Z',
            '2026-01-01T00:00:00.1234567Z', '2026-01-01T00:00:00.Z',
            '2026-01-01T00:00:00+24:00', '2026-01-01T00:00:00+00:60',
            '2026-01-01T00:00:00+0100', '2026-01-01T00:00:00 UTC',
            '2026-01-01t00:00:00z', "2026-01-01T00:00:00Z\n", ' 2026-01-01T00:00:00Z',
        ]);
    }
}
