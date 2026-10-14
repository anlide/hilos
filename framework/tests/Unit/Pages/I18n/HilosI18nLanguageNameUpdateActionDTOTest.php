<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\I18n;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\HilosException;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLanguageNameUpdateActionDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Literal name text and optional country-locale coordinates survive the wire boundary. */
final class HilosI18nLanguageNameUpdateActionDTOTest extends TestCase
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
        $payload = ['languageCode' => 'qv', 'inLanguageCode' => 'qw', 'localeCode' => $locale, 'name' => $name, 'expectedName' => $name];
        $dto = HilosI18nLanguageNameUpdateActionDTO::fromArray([...$payload, 'extra' => 'ignored']);
        self::assertSame($name, $dto->name);
        self::assertSame($locale, $dto->localeCode);
        self::assertSame($payload, $dto->toArray());
        self::assertSame(HilosSignalConstants::HILOS_I18N_LANGUAGE_NAME_UPDATE, $dto->getAction());
        self::assertSame([], HilosI18nLanguageNameUpdateActionDTO::SECRET_FIELDS);
        self::assertSame($payload, HilosI18nLanguageNameUpdateActionDTO::fromJson($dto->toJson())->toArray());
        self::assertSame($payload, HilosI18nLanguageNameUpdateActionDTO::fromArray([
            SignalPayloadConstants::FIELD_DATA => $payload,
        ])->toArray());
        if ($locale === null) {
            unset($payload['localeCode']);
            self::assertSame($dto->toArray(), HilosI18nLanguageNameUpdateActionDTO::fromArray($payload)->toArray());
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> Missing, mistyped and malformed fields */
    public static function invalidPayloads(): iterable
    {
        $name = 'Name';
        $valid = ['languageCode' => 'qv', 'inLanguageCode' => 'qw', 'name' => $name, 'expectedName' => $name];
        foreach (array_keys($valid) as $key) {
            $missing = $valid;
            unset($missing[$key]);
            yield 'missing ' . $key => [$missing];
            foreach ([null, 0, true, []] as $value) {
                yield $key . ' ' . var_export($value, true) => [[...$valid, $key => $value]];
            }
        }
        foreach (['languageCode', 'inLanguageCode'] as $key) {
            foreach (['', 'QW', 'q', 'qww', "qw\n", 'éñ', ' qw'] as $code) {
                yield $key . ' format ' . $code => [[...$valid, $key => $code]];
            }
        }
        yield 'same language' => [[...$valid, 'languageCode' => 'qw']];
        foreach (['', 'qw', 'qv-GB', 'qw-gb', 'QW-GB', 'qw-GB-extra', "qw-GB\n", 0, true, []] as $locale) {
            yield 'locale ' . var_export($locale, true) => [[...$valid, 'localeCode' => $locale]];
        }
        yield 'expected name too wide' => [[...$valid, 'expectedName' => str_repeat('é', 256)]];
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
        HilosI18nLanguageNameUpdateActionDTO::fromArray($payload);
    }
}
