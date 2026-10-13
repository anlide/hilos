<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\I18n;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Pages\I18n\Details\DTO\HilosI18nCountryUpdateActionDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The country's update address is strict; the currency is checked at the write door. */
final class HilosI18nCountryUpdateActionDTOTest extends TestCase
{
    private const array PAYLOAD = [
        HilosI18nCountryUpdateActionDTO::countryCode => 'ie',
        HilosI18nCountryUpdateActionDTO::currencySymbol => 'IE€',
        HilosI18nCountryUpdateActionDTO::currencyCode => 'EUR',
        HilosI18nCountryUpdateActionDTO::defaultLocaleCode => 'en-IE',
    ];

    /** Keeps the explicit null key and the inherited factory's concrete card action. */
    public function testReadsRawWrappedAndNullLocalePayloads(): void
    {
        foreach (['en-IE', 'en', null] as $code) {
            $payload = array_replace(self::PAYLOAD, [HilosI18nCountryUpdateActionDTO::defaultLocaleCode => $code]);
            foreach ([$payload, [SignalPayloadConstants::FIELD_DATA => $payload]] as $input) {
                $dto = HilosI18nCountryUpdateActionDTO::fromArray($input);
                self::assertSame($payload, $dto->toArray());
                self::assertSame(HilosSignalConstants::HILOS_I18N_COUNTRY_UPDATE, $dto->getAction());
            }
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid request shapes */
    public static function invalidPayloads(): iterable
    {
        foreach (['IE', 'iee', '', "ie\n", 42] as $invalid) {
            yield 'country ' . var_export($invalid, true) => [array_replace(self::PAYLOAD, [
                HilosI18nCountryUpdateActionDTO::countryCode => $invalid,
            ])];
        }
        foreach (['en_IE', 'EN-IE', 'en-ie', '', "en-IE\n", 42, false] as $invalid) {
            yield 'locale ' . var_export($invalid, true) => [array_replace(self::PAYLOAD, [
                HilosI18nCountryUpdateActionDTO::defaultLocaleCode => $invalid,
            ])];
        }
        foreach ([HilosI18nCountryUpdateActionDTO::currencySymbol, HilosI18nCountryUpdateActionDTO::currencyCode] as $key) {
            foreach ([null, 42, false, []] as $invalid) {
                yield $key . ' ' . var_export($invalid, true) => [array_replace(self::PAYLOAD, [$key => $invalid])];
            }
        }
        foreach (array_keys(self::PAYLOAD) as $key) {
            $missing = self::PAYLOAD;
            unset($missing[$key]);
            yield 'missing ' . $key => [$missing];
        }
        yield 'malformed envelope' => [[SignalPayloadConstants::FIELD_DATA => 'bad']];
    }

    /** @param array<string, mixed> $payload Invalid request */
    #[DataProvider('invalidPayloads')]
    public function testRejectsInvalidPayload(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        HilosI18nCountryUpdateActionDTO::fromArray($payload);
    }
}
