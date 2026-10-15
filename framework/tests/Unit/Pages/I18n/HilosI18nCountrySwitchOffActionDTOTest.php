<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\I18n;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Pages\I18n\Details\DTO\HilosI18nCountrySwitchOffActionDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The country switch-off request has one required, strictly formatted address. */
final class HilosI18nCountrySwitchOffActionDTOTest extends TestCase
{
    public function testReadsRawAndWrappedPayload(): void
    {
        $raw = [HilosI18nCountrySwitchOffActionDTO::countryId => 141];
        self::assertSame($raw, HilosI18nCountrySwitchOffActionDTO::fromArray($raw)->toArray());
        self::assertSame(141, HilosI18nCountrySwitchOffActionDTO::fromArray([
            SignalPayloadConstants::FIELD_DATA => $raw,
        ])->countryId);
        self::assertSame(
            HilosSignalConstants::HILOS_I18N_COUNTRY_SWITCH_OFF,
            HilosI18nCountrySwitchOffActionDTO::fromArray($raw)->getAction(),
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid request shapes */
    public static function invalidPayloads(): iterable
    {
        yield 'uppercase' => [[HilosI18nCountrySwitchOffActionDTO::countryId => 'US']];
        yield 'three letters' => [[HilosI18nCountrySwitchOffActionDTO::countryId => 'usa']];
        yield 'empty' => [[HilosI18nCountrySwitchOffActionDTO::countryId => '']];
        yield 'zero ID' => [[HilosI18nCountrySwitchOffActionDTO::countryId => 0]];
        yield 'missing' => [[]];
        yield 'legacy code' => [['countryCode' => 'ca']];
        foreach (['106', -1, 106.0, null, true, false] as $id) {
            yield 'invalid ID ' . var_export($id, true) => [['countryId' => $id]];
        }
    }

    /** @param array<string, mixed> $payload Invalid request */
    #[DataProvider('invalidPayloads')]
    public function testRejectsInvalidCode(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        HilosI18nCountrySwitchOffActionDTO::fromArray($payload);
    }
}
