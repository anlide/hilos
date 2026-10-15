<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\I18n;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLocaleSwitchOffActionDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The locale switch-off request has two strict address parts and no formats. */
final class HilosI18nLocaleSwitchOffActionDTOTest extends TestCase
{
    private const array PAYLOAD = [
        HilosI18nLocaleSwitchOffActionDTO::languageId => 11,
        HilosI18nLocaleSwitchOffActionDTO::countryId => 101,
        HilosI18nLocaleSwitchOffActionDTO::localeId => 11101,
    ];

    public function testReadsRawWrappedAndCountrylessPayload(): void
    {
        self::assertSame(self::PAYLOAD, HilosI18nLocaleSwitchOffActionDTO::fromArray(self::PAYLOAD)->toArray());
        self::assertSame(self::PAYLOAD, HilosI18nLocaleSwitchOffActionDTO::fromArray([
            SignalPayloadConstants::FIELD_DATA => self::PAYLOAD,
        ])->toArray());
        self::assertSame(
            HilosSignalConstants::HILOS_I18N_LOCALE_SWITCH_OFF,
            HilosI18nLocaleSwitchOffActionDTO::fromArray(self::PAYLOAD)->getAction(),
        );
        $countryless = self::PAYLOAD;
        $countryless[HilosI18nLocaleSwitchOffActionDTO::countryId] = null;
        self::assertSame($countryless, HilosI18nLocaleSwitchOffActionDTO::fromArray($countryless)->toArray());
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid request shapes */
    public static function invalidPayloads(): iterable
    {
        yield 'legacy codes' => [['languageCode' => 'en', 'countryCode' => 'gb']];

        foreach (['11101', 0, -1, 11101.0, null, true, false] as $invalidId) {
            yield 'locale ID ' . var_export($invalidId, true) => [array_replace(self::PAYLOAD, [
                HilosI18nLocaleSwitchOffActionDTO::localeId => $invalidId,
            ])];
        }
        $missingLocale = self::PAYLOAD;
        unset($missingLocale[HilosI18nLocaleSwitchOffActionDTO::localeId]);
        yield 'missing locale ID' => [$missingLocale];

        foreach (['11', 'EN', 0, -1, 11.0, false, true, [], "en\n"] as $invalid) {
            yield 'language ' . var_export($invalid, true) => [array_replace(self::PAYLOAD, [
                HilosI18nLocaleSwitchOffActionDTO::languageId => $invalid,
            ])];
            yield 'country ' . var_export($invalid, true) => [array_replace(self::PAYLOAD, [
                HilosI18nLocaleSwitchOffActionDTO::countryId => $invalid,
            ])];
        }
        $missingCountry = self::PAYLOAD;
        unset($missingCountry[HilosI18nLocaleSwitchOffActionDTO::countryId]);
        yield 'missing country' => [$missingCountry];
        $missingLanguage = self::PAYLOAD;
        unset($missingLanguage[HilosI18nLocaleSwitchOffActionDTO::languageId]);
        yield 'missing language' => [$missingLanguage];
        yield 'null language' => [array_replace(self::PAYLOAD, [
            HilosI18nLocaleSwitchOffActionDTO::languageId => null,
        ])];
        yield 'non-array envelope' => [[SignalPayloadConstants::FIELD_DATA => 'invalid']];
    }

    /** @param array<string, mixed> $payload Invalid request */
    #[DataProvider('invalidPayloads')]
    public function testRejectsInvalidPayload(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        HilosI18nLocaleSwitchOffActionDTO::fromArray($payload);
    }
}
