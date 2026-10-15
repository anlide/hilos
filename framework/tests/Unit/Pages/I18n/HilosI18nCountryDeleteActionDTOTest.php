<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\I18n;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Pages\I18n\Details\DTO\HilosI18nCountryDeleteActionDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The country deletion request has one required, strictly formatted address. */
final class HilosI18nCountryDeleteActionDTOTest extends TestCase
{
    /** @throws InvalidFormatException When a valid fixture cannot be parsed */
    public function testReadsRawAndWrappedPayload(): void
    {
        $raw = [HilosI18nCountryDeleteActionDTO::countryId => 141];
        $dto = HilosI18nCountryDeleteActionDTO::fromArray($raw);
        self::assertSame($raw, HilosI18nCountryDeleteActionDTO::fromArray($dto->toArray())->toArray());
        self::assertSame($raw, HilosI18nCountryDeleteActionDTO::fromJson($dto->toJson())->toArray());
        self::assertSame($raw, HilosI18nCountryDeleteActionDTO::fromArray([SignalPayloadConstants::FIELD_DATA => $dto->toArray()])->toArray());
        self::assertSame([], HilosI18nCountryDeleteActionDTO::SECRET_FIELDS);
        self::assertSame(141, HilosI18nCountryDeleteActionDTO::fromArray([
            SignalPayloadConstants::FIELD_DATA => $raw,
        ])->countryId);
        self::assertSame(
            HilosSignalConstants::HILOS_I18N_COUNTRY_DELETE,
            HilosI18nCountryDeleteActionDTO::fromArray($raw)->getAction(),
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid request shapes */
    public static function invalidPayloads(): iterable
    {
        yield 'uppercase' => [[HilosI18nCountryDeleteActionDTO::countryId => 'US']];
        yield 'three letters' => [[HilosI18nCountryDeleteActionDTO::countryId => 'usa']];
        yield 'empty' => [[HilosI18nCountryDeleteActionDTO::countryId => '']];
        yield 'zero ID' => [[HilosI18nCountryDeleteActionDTO::countryId => 0]];
        yield 'missing' => [[]];
        yield 'legacy code' => [['countryCode' => 'ca']];
        foreach (['106', -1, 106.0, null, true, false] as $id) {
            yield 'invalid ID ' . var_export($id, true) => [['countryId' => $id]];
        }
        yield 'newline' => [[HilosI18nCountryDeleteActionDTO::countryId => "it\n"]];
        yield 'one letter' => [[HilosI18nCountryDeleteActionDTO::countryId => 'i']];
        yield 'non-Latin' => [[HilosI18nCountryDeleteActionDTO::countryId => 'éñ']];
        yield 'null' => [[HilosI18nCountryDeleteActionDTO::countryId => null]];
        yield 'boolean' => [[HilosI18nCountryDeleteActionDTO::countryId => true]];
        yield 'bad envelope' => [[SignalPayloadConstants::FIELD_DATA => 'it']];
    }

    /** @param array<string, mixed> $payload Invalid request */
    #[DataProvider('invalidPayloads')]
    public function testRejectsInvalidCode(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        HilosI18nCountryDeleteActionDTO::fromArray($payload);
    }
}
