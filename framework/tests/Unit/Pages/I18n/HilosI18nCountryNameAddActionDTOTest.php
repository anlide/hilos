<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\I18n;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\HilosException;
use Hilos\Pages\I18n\Details\DTO\HilosI18nCountryNameAddActionDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Literal name text and optional country-locale coordinates survive the wire boundary. */
final class HilosI18nCountryNameAddActionDTOTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> Literal names for both coordinate forms */
    public static function names(): iterable
    {
        foreach ([null, 'qw-GB'] as $locale) {
            foreach (['ordinary', '', "  \n", str_repeat('é', 255)] as $index => $name) {
                yield ($locale ?? 'base') . ' ' . $index => [$name, $locale];
            }
        }
    }

    /**
     * @param string $name Literal name
     * @param ?string $locale Country locale, or base
     * @throws HilosException When serialization or validation fails
     */
    #[DataProvider('names')]
    public function testRawEnvelopeAndJsonRoundtrip(string $name, ?string $locale): void
    {
        $payload = ['countryCode' => 'qv', 'inLanguageCode' => 'qw', 'localeCode' => $locale, 'name' => $name];
        $dto = HilosI18nCountryNameAddActionDTO::fromArray([...$payload, 'extra' => 'ignored']);
        self::assertSame($name, $dto->name);
        self::assertSame($locale, $dto->localeCode);
        self::assertSame($payload, $dto->toArray());
        self::assertSame(HilosSignalConstants::HILOS_I18N_COUNTRY_NAME_ADD, $dto->getAction());
        self::assertSame([], HilosI18nCountryNameAddActionDTO::SECRET_FIELDS);
        self::assertSame($payload, HilosI18nCountryNameAddActionDTO::fromJson($dto->toJson())->toArray());
        self::assertSame($payload, HilosI18nCountryNameAddActionDTO::fromArray([
            SignalPayloadConstants::FIELD_DATA => $payload,
        ])->toArray());
        if ($locale === null) {
            unset($payload['localeCode']);
            self::assertSame($dto->toArray(), HilosI18nCountryNameAddActionDTO::fromArray($payload)->toArray());
        }
    }

    /** @throws InvalidFormatException When valid coordinates are refused */
    public function testCountryAndWritingLanguageMayHaveTheSameCode(): void
    {
        $payload = ['countryCode' => 'de', 'inLanguageCode' => 'de', 'name' => 'Deutschland'];
        $dto = HilosI18nCountryNameAddActionDTO::fromArray($payload);
        self::assertSame('de', $dto->countryCode);
        self::assertSame('de', $dto->inLanguageCode);
    }

    /** @throws InvalidFormatException When a name reserved for ORM validation is refused */
    public function testNameWidthIsLeftToTheWriteAction(): void
    {
        $dto = HilosI18nCountryNameAddActionDTO::fromArray([
            'countryCode' => 'de', 'inLanguageCode' => 'de', 'name' => str_repeat('é', 256),
        ]);
        self::assertSame(256, mb_strlen($dto->name));
    }

    /** @return iterable<string, array{array<string, mixed>}> Missing, mistyped and malformed fields */
    public static function invalidPayloads(): iterable
    {
        $name = 'Name';
        $valid = ['countryCode' => 'qv', 'inLanguageCode' => 'qw', 'name' => $name];
        foreach (array_keys($valid) as $key) {
            $missing = $valid;
            unset($missing[$key]);
            yield 'missing ' . $key => [$missing];
            foreach ([null, 0, true, []] as $value) {
                yield $key . ' ' . var_export($value, true) => [[...$valid, $key => $value]];
            }
        }
        foreach (['countryCode', 'inLanguageCode'] as $key) {
            foreach (['', 'QW', 'q', 'qww', "qw\n", 'éñ', ' qw'] as $code) {
                yield $key . ' format ' . $code => [[...$valid, $key => $code]];
            }
        }
        foreach (['', 'qw', 'qv-GB', 'qw-gb', 'QW-GB', 'qw-GB-extra', "qw-GB\n", 0, true, []] as $locale) {
            yield 'locale ' . var_export($locale, true) => [[...$valid, 'localeCode' => $locale]];
        }
        yield 'empty payload' => [[]];
        foreach ([null, 'invalid', 42, []] as $envelope) {
            yield 'envelope ' . var_export($envelope, true) => [[SignalPayloadConstants::FIELD_DATA => $envelope]];
        }
    }

    /**
     * @param array<string, mixed> $payload Rejected payload
     * @throws InvalidFormatException Expected payload refusal
     */
    #[DataProvider('invalidPayloads')]
    public function testRejectsInvalidPayload(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        HilosI18nCountryNameAddActionDTO::fromArray($payload);
    }
}
