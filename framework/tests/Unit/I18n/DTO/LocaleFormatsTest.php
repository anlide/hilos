<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\I18n\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\I18n\DTO\LocaleFormats;
use PHPUnit\Framework\TestCase;

/** The seven-format value keeps its wire shape and refuses broken units. */
final class LocaleFormatsTest extends TestCase
{
    private const array FORMATS = [
        LocaleFormats::date => 'DD/MM/YYYY',
        LocaleFormats::time => 'HH:mm:ss',
        LocaleFormats::number => '1 000,00',
        LocaleFormats::phone => '+XX-XXXX-XXXX',
        LocaleFormats::address => 'Street, House, City, Index',
        LocaleFormats::measurement => 'metric',
        LocaleFormats::collation => 'und',
    ];

    public function testRoundTrip(): void
    {
        self::assertSame(self::FORMATS, LocaleFormats::fromArray(self::FORMATS)->toArray());
    }

    public function testRejectsUnknownUnits(): void
    {
        $data = self::FORMATS;
        $data[LocaleFormats::measurement] = 'unknown';
        $this->expectException(InvalidFormatException::class);
        LocaleFormats::fromArray($data);
    }

    public function testRejectsMissingField(): void
    {
        $data = self::FORMATS;
        unset($data[LocaleFormats::date]);
        $this->expectException(InvalidFormatException::class);
        LocaleFormats::fromArray($data);
    }
}
