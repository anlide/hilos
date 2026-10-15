<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\I18n;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\HilosException;
use Hilos\Pages\I18n\Details\DTO\HilosI18nCountryNameUpdateActionDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exact ID coordinates and literal text survive every serialization boundary. */
final class HilosI18nCountryNameUpdateActionDTOTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> Valid base and correction payloads */
    public static function payloads(): iterable
    {
        foreach (['ordinary', '', "  \n", str_repeat('é', 255)] as $index => $name) {
            $payload = [
                'countryId' => 11,
                'inLanguageId' => 12,
                'localeId' => null,
                'nameId' => 31,
                'name' => $name,
                'expectedName' => $name,
            ];
            yield 'base ' . $index => [$payload];
            $payload['localeId'] = 21;
            yield 'correction ' . $index => [$payload];
        }
    }

    /**
     * @param array<string, mixed> $payload Exact wire contract
     * @throws HilosException When serialization or validation fails
     */
    #[DataProvider('payloads')]
    public function testRawEnvelopeAndJsonRoundtrip(array $payload): void
    {
        $dto = HilosI18nCountryNameUpdateActionDTO::fromArray([...$payload, 'extra' => 'ignored']);
        self::assertSame($payload['countryId'], $dto->countryId);
        self::assertSame($payload['inLanguageId'], $dto->inLanguageId);
        self::assertSame($payload['localeId'], $dto->localeId);
        self::assertSame($payload['nameId'], $dto->nameId);
        self::assertSame($payload['name'], $dto->name);
        self::assertSame($payload['expectedName'], $dto->expectedName);
        self::assertSame($payload, $dto->toArray());
        self::assertSame(HilosSignalConstants::HILOS_I18N_COUNTRY_NAME_UPDATE, $dto->getAction());
        self::assertSame([], HilosI18nCountryNameUpdateActionDTO::SECRET_FIELDS);
        self::assertSame($payload, HilosI18nCountryNameUpdateActionDTO::fromJson($dto->toJson())->toArray());
        self::assertSame($payload, HilosI18nCountryNameUpdateActionDTO::fromArray([
            SignalPayloadConstants::FIELD_DATA => $payload,
        ])->toArray());
    }

    /** @throws HilosException When equal IDs from distinct models are incorrectly refused */
    public function testCountryAndWritingLanguageMayHaveTheSameId(): void
    {
        $name = '';
        $payload = [
            'countryId' => 11,
            'inLanguageId' => 12,
            'localeId' => null,
            'nameId' => 31,
            'name' => $name,
            'expectedName' => $name,
        ];
        $payload['inLanguageId'] = $payload['countryId'];
        $dto = HilosI18nCountryNameUpdateActionDTO::fromArray($payload);
        self::assertSame($dto->countryId, $dto->inLanguageId);
    }

    /** @throws InvalidFormatException When a name reserved for ORM validation is refused */
    public function testNameWidthIsLeftToTheWriteAction(): void
    {
        $name = str_repeat('é', 256);
        $payload = [
            'countryId' => 11,
            'inLanguageId' => 12,
            'localeId' => null,
            'nameId' => 31,
            'name' => $name,
            'expectedName' => 'Name',
        ];
        $dto = HilosI18nCountryNameUpdateActionDTO::fromArray($payload);
        self::assertSame($name, $dto->name);
        self::assertSame($payload, $dto->toArray());
    }

    /** @return iterable<string, array{array<string, mixed>}> Missing, mistyped and malformed fields */
    public static function invalidPayloads(): iterable
    {
        $name = 'Name';
        $valid = [
            'countryId' => 11,
            'inLanguageId' => 12,
            'localeId' => null,
            'nameId' => 31,
            'name' => $name,
            'expectedName' => $name,
        ];
        foreach (array_keys($valid) as $key) {
            $missing = $valid;
            unset($missing[$key]);
            yield 'missing ' . $key => [$missing];
            $ids = ['countryId', 'inLanguageId', 'localeId', 'nameId'];
            $invalid = in_array($key, $ids, true) ? ['12', false, true, 1.5, 12.0, 0, -1, '', []] : [null, 0, true, []];
            foreach ($invalid as $value) {
                yield $key . ' ' . var_export($value, true) => [[...$valid, $key => $value]];
            }
        }
        yield 'null countryId' => [[...$valid, 'countryId' => null]];
        yield 'null inLanguageId' => [[...$valid, 'inLanguageId' => null]];
        yield 'null nameId' => [[...$valid, 'nameId' => null]];
        yield 'expected text too long' => [[...$valid, 'expectedName' => str_repeat('é', 256)]];
        yield 'legacy codes' => [['countryCode' => 'qv', 'inLanguageCode' => 'qw', 'localeCode' => null, 'name' => 'Name']];
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
        HilosI18nCountryNameUpdateActionDTO::fromArray($payload);
    }
}
