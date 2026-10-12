<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\I18n;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Pages\I18n\Lists\DTO\HilosI18nCountryRowDeleteActionDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The country deletion request has one required, strictly formatted address. */
final class HilosI18nCountryRowDeleteActionDTOTest extends TestCase
{
    /** @throws InvalidFormatException When a valid fixture cannot be parsed */
    public function testReadsRawAndWrappedPayload(): void
    {
        $raw = [HilosI18nCountryRowDeleteActionDTO::countryCode => 'it'];
        $dto = HilosI18nCountryRowDeleteActionDTO::fromArray($raw);
        self::assertSame($raw, HilosI18nCountryRowDeleteActionDTO::fromArray($dto->toArray())->toArray());
        self::assertSame($raw, HilosI18nCountryRowDeleteActionDTO::fromJson($dto->toJson())->toArray());
        self::assertSame($raw, HilosI18nCountryRowDeleteActionDTO::fromArray([SignalPayloadConstants::FIELD_DATA => $dto->toArray()])->toArray());
        self::assertSame([], HilosI18nCountryRowDeleteActionDTO::SECRET_FIELDS);
        self::assertSame('it', HilosI18nCountryRowDeleteActionDTO::fromArray([
            SignalPayloadConstants::FIELD_DATA => $raw,
        ])->countryCode);
        self::assertSame(
            HilosSignalConstants::HILOS_I18N_COUNTRY_ROW_DELETE,
            HilosI18nCountryRowDeleteActionDTO::fromArray($raw)->getAction(),
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid request shapes */
    public static function invalidPayloads(): iterable
    {
        yield 'uppercase' => [[HilosI18nCountryRowDeleteActionDTO::countryCode => 'US']];
        yield 'three letters' => [[HilosI18nCountryRowDeleteActionDTO::countryCode => 'usa']];
        yield 'empty' => [[HilosI18nCountryRowDeleteActionDTO::countryCode => '']];
        yield 'not a string' => [[HilosI18nCountryRowDeleteActionDTO::countryCode => 42]];
        yield 'missing' => [[]];
        yield 'newline' => [[HilosI18nCountryRowDeleteActionDTO::countryCode => "it\n"]];
        yield 'one letter' => [[HilosI18nCountryRowDeleteActionDTO::countryCode => 'i']];
        yield 'non-Latin' => [[HilosI18nCountryRowDeleteActionDTO::countryCode => 'éñ']];
        yield 'null' => [[HilosI18nCountryRowDeleteActionDTO::countryCode => null]];
        yield 'boolean' => [[HilosI18nCountryRowDeleteActionDTO::countryCode => true]];
        yield 'bad envelope' => [[SignalPayloadConstants::FIELD_DATA => 'it']];
    }

    /** @param array<string, mixed> $payload Invalid request */
    #[DataProvider('invalidPayloads')]
    public function testRejectsInvalidCode(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        HilosI18nCountryRowDeleteActionDTO::fromArray($payload);
    }
}
