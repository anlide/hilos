<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\I18n;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Pages\I18n\Lists\DTO\HilosI18nCountryRowUpdateActionDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The country's update address is strict; the currency is checked at the write door. */
final class HilosI18nCountryRowUpdateActionDTOTest extends TestCase
{
    private const array PAYLOAD = [
        HilosI18nCountryRowUpdateActionDTO::countryCode => 'ie',
        HilosI18nCountryRowUpdateActionDTO::currencySymbol => 'IE€',
        HilosI18nCountryRowUpdateActionDTO::currencyCode => 'EUR',
        HilosI18nCountryRowUpdateActionDTO::defaultLocaleCode => 'en-IE',
    ];

    /** Keeps the explicit null key and the inherited factory's concrete row action. */
    public function testReadsRawWrappedAndNullLocalePayloads(): void
    {
        foreach (['en-IE', 'en', null] as $code) {
            $payload = array_replace(self::PAYLOAD, [HilosI18nCountryRowUpdateActionDTO::defaultLocaleCode => $code]);
            foreach ([$payload, [SignalPayloadConstants::FIELD_DATA => $payload]] as $input) {
                $dto = HilosI18nCountryRowUpdateActionDTO::fromArray($input);
                self::assertSame($payload, $dto->toArray());
                self::assertSame(HilosSignalConstants::HILOS_I18N_COUNTRY_ROW_UPDATE, $dto->getAction());
            }
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid request shapes */
    public static function invalidPayloads(): iterable
    {
        foreach (['IE', 'iee', '', "ie\n", 42] as $invalid) {
            yield 'country ' . var_export($invalid, true) => [array_replace(self::PAYLOAD, [
                HilosI18nCountryRowUpdateActionDTO::countryCode => $invalid,
            ])];
        }
        foreach (['en_IE', 'EN-IE', 'en-ie', '', "en-IE\n", 42, false] as $invalid) {
            yield 'locale ' . var_export($invalid, true) => [array_replace(self::PAYLOAD, [
                HilosI18nCountryRowUpdateActionDTO::defaultLocaleCode => $invalid,
            ])];
        }
        foreach ([HilosI18nCountryRowUpdateActionDTO::currencySymbol, HilosI18nCountryRowUpdateActionDTO::currencyCode] as $key) {
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
        HilosI18nCountryRowUpdateActionDTO::fromArray($payload);
    }
}
