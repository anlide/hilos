<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\I18n;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Pages\I18n\Details\DTO\HilosI18nCountrySwitchOnActionDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The country switch-on request has one required, strictly formatted address. */
final class HilosI18nCountrySwitchOnActionDTOTest extends TestCase
{
    public function testReadsRawAndWrappedPayload(): void
    {
        $raw = [HilosI18nCountrySwitchOnActionDTO::countryCode => 'ca'];
        self::assertSame($raw, HilosI18nCountrySwitchOnActionDTO::fromArray($raw)->toArray());
        self::assertSame('ca', HilosI18nCountrySwitchOnActionDTO::fromArray([
            SignalPayloadConstants::FIELD_DATA => $raw,
        ])->countryCode);
        self::assertSame(
            HilosSignalConstants::HILOS_I18N_COUNTRY_SWITCH_ON,
            HilosI18nCountrySwitchOnActionDTO::fromArray($raw)->getAction(),
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid request shapes */
    public static function invalidPayloads(): iterable
    {
        yield 'uppercase' => [[HilosI18nCountrySwitchOnActionDTO::countryCode => 'CA']];
        yield 'three letters' => [[HilosI18nCountrySwitchOnActionDTO::countryCode => 'can']];
        yield 'empty' => [[HilosI18nCountrySwitchOnActionDTO::countryCode => '']];
        yield 'not a string' => [[HilosI18nCountrySwitchOnActionDTO::countryCode => 42]];
        yield 'missing' => [[]];
    }

    /** @param array<string, mixed> $payload Invalid request */
    #[DataProvider('invalidPayloads')]
    public function testRejectsInvalidCode(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        HilosI18nCountrySwitchOnActionDTO::fromArray($payload);
    }
}
