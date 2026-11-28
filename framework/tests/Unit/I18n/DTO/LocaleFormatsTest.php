<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\I18n\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\I18n\DTO\LocaleFormats;
use Hilos\I18n\Weekday;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The display values keep their wire shape and refuse broken units and weekdays. */
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
        LocaleFormats::firstWeekday => 0,
    ];

    public function testRoundTrip(): void
    {
        $formats = LocaleFormats::fromArray(self::FORMATS);

        self::assertSame(Weekday::SUNDAY, $formats->firstWeekday);
        self::assertSame(self::FORMATS, $formats->toArray());
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

    public function testRejectsMissingFirstWeekday(): void
    {
        $data = self::FORMATS;
        unset($data[LocaleFormats::firstWeekday]);
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('Payload carries no integer under key firstWeekday');
        LocaleFormats::fromArray($data);
    }

    /** @return iterable<string, array{mixed}> First weekdays that are not an integer */
    public static function nonIntegerWeekdays(): iterable
    {
        yield 'numeric string' => ['1'];
        yield 'float' => [1.0];
        yield 'null' => [null];
    }

    /** @param mixed $weekday A first weekday of the wrong type */
    #[DataProvider('nonIntegerWeekdays')]
    public function testRejectsNonIntegerFirstWeekday(mixed $weekday): void
    {
        $data = self::FORMATS;
        $data[LocaleFormats::firstWeekday] = $weekday;
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('Payload carries no integer under key firstWeekday');
        LocaleFormats::fromArray($data);
    }

    public function testRejectsAWeekdayPastSaturday(): void
    {
        $data = self::FORMATS;
        $data[LocaleFormats::firstWeekday] = 7;
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('Unknown first weekday: 7');
        LocaleFormats::fromArray($data);
    }
}
