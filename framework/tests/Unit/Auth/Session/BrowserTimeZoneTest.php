<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Session;

use Hilos\Auth\Session\BrowserTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the exact-IANA check of a browser-reported time zone name (HIL-1582).
 */
final class BrowserTimeZoneTest extends TestCase
{
    public function testWireNamesAreFixed(): void
    {
        $this->assertSame('browserTimeZone', BrowserTimeZone::QUERY_PARAM);
        $this->assertSame('browser_time_zone_report', BrowserTimeZone::JOURNAL_ACTION);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function usableNames(): array
    {
        return [
            'canonical identifier' => ['Europe/Warsaw'],
            'two-segment identifier' => ['America/Argentina/Buenos_Aires'],
            'UTC' => ['UTC'],
            'backward alias' => ['US/Pacific'],
            'historical alias' => ['Asia/Calcutta'],
            'backward alias without region' => ['GB'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusableNames(): array
    {
        return [
            'empty string' => [''],
            'only whitespace' => [' '],
            'leading whitespace' => [' Europe/Warsaw'],
            'trailing whitespace' => ['Europe/Warsaw '],
            'trailing newline' => ["Europe/Warsaw\n"],
            'wrong case' => ['europe/warsaw'],
            'unknown name' => ['Mars/Olympus_Mons'],
            'region without city' => ['Europe'],
            'numeric offset' => ['+02:00'],
            'compact numeric offset' => ['+0200'],
            'offset with sign only hours' => ['-05'],
            'abbreviation' => ['CEST'],
            'abbreviation with offset' => ['GMT+2'],
            'terminating byte' => ["Europe/Warsaw\0"],
        ];
    }

    #[DataProvider('usableNames')]
    public function testUsableNameIsValidAndKeptWithoutRewriting(string $name): void
    {
        $this->assertTrue(BrowserTimeZone::isValid($name));
        $this->assertSame($name, BrowserTimeZone::normalize($name));
    }

    #[DataProvider('unusableNames')]
    public function testUnusableNameIsNotValidAndNormalizesToNull(string $name): void
    {
        $this->assertFalse(BrowserTimeZone::isValid($name));
        $this->assertNull(BrowserTimeZone::normalize($name));
    }

    public function testMissingValueNormalizesToNull(): void
    {
        $this->assertNull(BrowserTimeZone::normalize(null));
    }
}
