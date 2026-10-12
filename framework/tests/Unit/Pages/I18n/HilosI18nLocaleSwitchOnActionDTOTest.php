<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\I18n;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLocaleSwitchOnActionDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The locale switch-on request has two strict address parts and no formats. */
final class HilosI18nLocaleSwitchOnActionDTOTest extends TestCase
{
    private const array PAYLOAD = [
        HilosI18nLocaleSwitchOnActionDTO::languageCode => 'en',
        HilosI18nLocaleSwitchOnActionDTO::countryCode => 'gb',
    ];

    public function testReadsRawWrappedAndCountrylessPayload(): void
    {
        self::assertSame(self::PAYLOAD, HilosI18nLocaleSwitchOnActionDTO::fromArray(self::PAYLOAD)->toArray());
        self::assertSame(self::PAYLOAD, HilosI18nLocaleSwitchOnActionDTO::fromArray([
            SignalPayloadConstants::FIELD_DATA => self::PAYLOAD,
        ])->toArray());
        self::assertSame(
            HilosSignalConstants::HILOS_I18N_LOCALE_SWITCH_ON,
            HilosI18nLocaleSwitchOnActionDTO::fromArray(self::PAYLOAD)->getAction(),
        );
        $countryless = self::PAYLOAD;
        $countryless[HilosI18nLocaleSwitchOnActionDTO::countryCode] = null;
        self::assertSame($countryless, HilosI18nLocaleSwitchOnActionDTO::fromArray($countryless)->toArray());
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid request shapes */
    public static function invalidPayloads(): iterable
    {
        foreach (['EN', 'gbr', '', 42, false, [], "en\n"] as $invalid) {
            yield 'language ' . var_export($invalid, true) => [array_replace(self::PAYLOAD, [
                HilosI18nLocaleSwitchOnActionDTO::languageCode => $invalid,
            ])];
            yield 'country ' . var_export($invalid, true) => [array_replace(self::PAYLOAD, [
                HilosI18nLocaleSwitchOnActionDTO::countryCode => $invalid,
            ])];
        }
        $missingCountry = self::PAYLOAD;
        unset($missingCountry[HilosI18nLocaleSwitchOnActionDTO::countryCode]);
        yield 'missing country' => [$missingCountry];
        $missingLanguage = self::PAYLOAD;
        unset($missingLanguage[HilosI18nLocaleSwitchOnActionDTO::languageCode]);
        yield 'missing language' => [$missingLanguage];
        yield 'null language' => [array_replace(self::PAYLOAD, [
            HilosI18nLocaleSwitchOnActionDTO::languageCode => null,
        ])];
        yield 'non-array envelope' => [[SignalPayloadConstants::FIELD_DATA => 'invalid']];
    }

    /** @param array<string, mixed> $payload Invalid request */
    #[DataProvider('invalidPayloads')]
    public function testRejectsInvalidPayload(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        HilosI18nLocaleSwitchOnActionDTO::fromArray($payload);
    }
}
