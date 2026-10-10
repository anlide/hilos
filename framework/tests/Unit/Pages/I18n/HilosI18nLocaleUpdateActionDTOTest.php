<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\I18n;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\I18n\DTO\LocaleFormats;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLocaleUpdateActionDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The locale update request has two strict address parts and seven required formats. */
final class HilosI18nLocaleUpdateActionDTOTest extends TestCase
{
    private const array FORMATS = [
        LocaleFormats::date => 'DD/MM/YYYY',
        LocaleFormats::time => 'HH:mm:ss',
        LocaleFormats::number => '1,000.00',
        LocaleFormats::phone => '+XX-XXXX-XXXX',
        LocaleFormats::address => 'Street, House, City, Index',
        LocaleFormats::measurement => 'metric',
        LocaleFormats::collation => 'und',
    ];

    private const array PAYLOAD = [
        HilosI18nLocaleUpdateActionDTO::languageCode => 'en',
        HilosI18nLocaleUpdateActionDTO::countryCode => 'gb',
        HilosI18nLocaleUpdateActionDTO::formats => self::FORMATS,
    ];

    public function testReadsRawWrappedAndCountrylessPayload(): void
    {
        self::assertSame(self::PAYLOAD, HilosI18nLocaleUpdateActionDTO::fromArray(self::PAYLOAD)->toArray());
        self::assertSame(self::PAYLOAD, HilosI18nLocaleUpdateActionDTO::fromArray([
            SignalPayloadConstants::FIELD_DATA => self::PAYLOAD,
        ])->toArray());
        self::assertSame(
            HilosSignalConstants::HILOS_I18N_LOCALE_UPDATE,
            HilosI18nLocaleUpdateActionDTO::fromArray(self::PAYLOAD)->getAction(),
        );
        $countryless = self::PAYLOAD;
        $countryless[HilosI18nLocaleUpdateActionDTO::countryCode] = null;
        self::assertSame($countryless, HilosI18nLocaleUpdateActionDTO::fromArray($countryless)->toArray());
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid request shapes */
    public static function invalidPayloads(): iterable
    {
        foreach (['EN', 'eng', '', 42] as $invalid) {
            yield 'language ' . var_export($invalid, true) => [array_replace(self::PAYLOAD, [
                HilosI18nLocaleUpdateActionDTO::languageCode => $invalid,
            ])];
            yield 'country ' . var_export($invalid, true) => [array_replace(self::PAYLOAD, [
                HilosI18nLocaleUpdateActionDTO::countryCode => $invalid,
            ])];
        }
        $missingCountry = self::PAYLOAD;
        unset($missingCountry[HilosI18nLocaleUpdateActionDTO::countryCode]);
        yield 'missing country' => [$missingCountry];
        $missingFormats = self::PAYLOAD;
        unset($missingFormats[HilosI18nLocaleUpdateActionDTO::formats]);
        yield 'missing formats' => [$missingFormats];
    }

    /** @param array<string, mixed> $payload Invalid request */
    #[DataProvider('invalidPayloads')]
    public function testRejectsInvalidPayload(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        HilosI18nLocaleUpdateActionDTO::fromArray($payload);
    }
}
