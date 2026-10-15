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
        $raw = [HilosI18nCountrySwitchOnActionDTO::countryId => 106];
        self::assertSame($raw, HilosI18nCountrySwitchOnActionDTO::fromArray($raw)->toArray());
        self::assertSame(106, HilosI18nCountrySwitchOnActionDTO::fromArray([
            SignalPayloadConstants::FIELD_DATA => $raw,
        ])->countryId);
        self::assertSame(
            HilosSignalConstants::HILOS_I18N_COUNTRY_SWITCH_ON,
            HilosI18nCountrySwitchOnActionDTO::fromArray($raw)->getAction(),
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid request shapes */
    public static function invalidPayloads(): iterable
    {
        yield 'uppercase' => [[HilosI18nCountrySwitchOnActionDTO::countryId => 'CA']];
        yield 'three letters' => [[HilosI18nCountrySwitchOnActionDTO::countryId => 'can']];
        yield 'empty' => [[HilosI18nCountrySwitchOnActionDTO::countryId => '']];
        yield 'zero ID' => [[HilosI18nCountrySwitchOnActionDTO::countryId => 0]];
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
        HilosI18nCountrySwitchOnActionDTO::fromArray($payload);
    }
}
