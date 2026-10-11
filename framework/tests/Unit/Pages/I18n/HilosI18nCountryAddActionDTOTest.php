<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\I18n;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Pages\I18n\Lists\DTO\HilosI18nCountryAddActionDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The country add request requires one strict code and two string currency fields. */
final class HilosI18nCountryAddActionDTOTest extends TestCase
{
    private const array PAYLOAD = [
        HilosI18nCountryAddActionDTO::countryCode => 'gg',
        HilosI18nCountryAddActionDTO::currencySymbol => '£',
        HilosI18nCountryAddActionDTO::currencyCode => 'GBP',
    ];

    public function testReadsRawAndWrappedPayload(): void
    {
        self::assertSame(self::PAYLOAD, HilosI18nCountryAddActionDTO::fromArray(self::PAYLOAD)->toArray());
        self::assertSame(self::PAYLOAD, HilosI18nCountryAddActionDTO::fromArray([
            SignalPayloadConstants::FIELD_DATA => self::PAYLOAD,
        ])->toArray());
        self::assertSame(
            HilosSignalConstants::HILOS_I18N_COUNTRY_ADD,
            HilosI18nCountryAddActionDTO::fromArray(self::PAYLOAD)->getAction(),
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid request shapes */
    public static function invalidPayloads(): iterable
    {
        foreach (['GG', 'ggg', 42] as $invalid) {
            yield 'country ' . var_export($invalid, true) => [array_replace(self::PAYLOAD, [
                HilosI18nCountryAddActionDTO::countryCode => $invalid,
            ])];
        }
        foreach ([
            HilosI18nCountryAddActionDTO::countryCode,
            HilosI18nCountryAddActionDTO::currencySymbol,
            HilosI18nCountryAddActionDTO::currencyCode,
        ] as $field) {
            $missing = self::PAYLOAD;
            unset($missing[$field]);
            yield 'missing ' . $field => [$missing];
        }
        foreach ([HilosI18nCountryAddActionDTO::currencySymbol, HilosI18nCountryAddActionDTO::currencyCode] as $field) {
            yield 'non-string ' . $field => [array_replace(self::PAYLOAD, [$field => 42])];
        }
    }

    /** @param array<string, mixed> $payload Invalid request */
    #[DataProvider('invalidPayloads')]
    public function testRejectsInvalidPayload(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        HilosI18nCountryAddActionDTO::fromArray($payload);
    }
}
