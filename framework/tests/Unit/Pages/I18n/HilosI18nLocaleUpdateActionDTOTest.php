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
        HilosI18nLocaleUpdateActionDTO::languageId => 11,
        HilosI18nLocaleUpdateActionDTO::countryId => 101,
        HilosI18nLocaleUpdateActionDTO::localeId => 11101,
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
        $countryless[HilosI18nLocaleUpdateActionDTO::countryId] = null;
        self::assertSame($countryless, HilosI18nLocaleUpdateActionDTO::fromArray($countryless)->toArray());
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid request shapes */
    public static function invalidPayloads(): iterable
    {
        yield 'legacy codes' => [['languageCode' => 'en', 'countryCode' => 'gb']];

        foreach (['11101', 0, -1, 11101.0, null, true, false] as $invalidId) {
            yield 'locale ID ' . var_export($invalidId, true) => [array_replace(self::PAYLOAD, [
                HilosI18nLocaleUpdateActionDTO::localeId => $invalidId,
            ])];
        }
        $missingLocale = self::PAYLOAD;
        unset($missingLocale[HilosI18nLocaleUpdateActionDTO::localeId]);
        yield 'missing locale ID' => [$missingLocale];

        foreach (['11', 'EN', 0, -1, 11.0, '', true, false] as $invalid) {
            yield 'language ' . var_export($invalid, true) => [array_replace(self::PAYLOAD, [
                HilosI18nLocaleUpdateActionDTO::languageId => $invalid,
            ])];
            yield 'country ' . var_export($invalid, true) => [array_replace(self::PAYLOAD, [
                HilosI18nLocaleUpdateActionDTO::countryId => $invalid,
            ])];
        }
        $missingCountry = self::PAYLOAD;
        unset($missingCountry[HilosI18nLocaleUpdateActionDTO::countryId]);
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
