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
        $raw = [HilosI18nCountryDeleteActionDTO::countryCode => 'it'];
        $dto = HilosI18nCountryDeleteActionDTO::fromArray($raw);
        self::assertSame($raw, HilosI18nCountryDeleteActionDTO::fromArray($dto->toArray())->toArray());
        self::assertSame($raw, HilosI18nCountryDeleteActionDTO::fromJson($dto->toJson())->toArray());
        self::assertSame($raw, HilosI18nCountryDeleteActionDTO::fromArray([SignalPayloadConstants::FIELD_DATA => $dto->toArray()])->toArray());
        self::assertSame([], HilosI18nCountryDeleteActionDTO::SECRET_FIELDS);
        self::assertSame('it', HilosI18nCountryDeleteActionDTO::fromArray([
            SignalPayloadConstants::FIELD_DATA => $raw,
        ])->countryCode);
        self::assertSame(
            HilosSignalConstants::HILOS_I18N_COUNTRY_DELETE,
            HilosI18nCountryDeleteActionDTO::fromArray($raw)->getAction(),
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid request shapes */
    public static function invalidPayloads(): iterable
    {
        yield 'uppercase' => [[HilosI18nCountryDeleteActionDTO::countryCode => 'US']];
        yield 'three letters' => [[HilosI18nCountryDeleteActionDTO::countryCode => 'usa']];
        yield 'empty' => [[HilosI18nCountryDeleteActionDTO::countryCode => '']];
        yield 'not a string' => [[HilosI18nCountryDeleteActionDTO::countryCode => 42]];
        yield 'missing' => [[]];
        yield 'newline' => [[HilosI18nCountryDeleteActionDTO::countryCode => "it\n"]];
        yield 'one letter' => [[HilosI18nCountryDeleteActionDTO::countryCode => 'i']];
        yield 'non-Latin' => [[HilosI18nCountryDeleteActionDTO::countryCode => 'éñ']];
        yield 'null' => [[HilosI18nCountryDeleteActionDTO::countryCode => null]];
        yield 'boolean' => [[HilosI18nCountryDeleteActionDTO::countryCode => true]];
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
