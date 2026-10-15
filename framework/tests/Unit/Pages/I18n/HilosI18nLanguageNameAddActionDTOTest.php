<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\I18n;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\HilosException;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLanguageNameAddActionDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exact ID coordinates and literal text survive every serialization boundary. */
final class HilosI18nLanguageNameAddActionDTOTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> Valid base and correction payloads */
    public static function payloads(): iterable
    {
        foreach (['ordinary', '', "  \n", str_repeat('é', 255)] as $index => $name) {
            $payload = [
                'languageId' => 11,
                'inLanguageId' => 12,
                'localeId' => null,
                'baseNameId' => null,
                'name' => $name,
            ];
            yield 'base ' . $index => [$payload];
            $payload['localeId'] = 21;
            $payload['baseNameId'] = 31;
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
        $dto = HilosI18nLanguageNameAddActionDTO::fromArray([...$payload, 'extra' => 'ignored']);
        self::assertSame($payload['languageId'], $dto->languageId);
        self::assertSame($payload['inLanguageId'], $dto->inLanguageId);
        self::assertSame($payload['localeId'], $dto->localeId);
        self::assertSame($payload['baseNameId'], $dto->baseNameId);
        self::assertSame($payload['name'], $dto->name);
        self::assertSame($payload, $dto->toArray());
        self::assertSame(HilosSignalConstants::HILOS_I18N_LANGUAGE_NAME_ADD, $dto->getAction());
        self::assertSame([], HilosI18nLanguageNameAddActionDTO::SECRET_FIELDS);
        self::assertSame($payload, HilosI18nLanguageNameAddActionDTO::fromJson($dto->toJson())->toArray());
        self::assertSame($payload, HilosI18nLanguageNameAddActionDTO::fromArray([
            SignalPayloadConstants::FIELD_DATA => $payload,
        ])->toArray());
    }

    /** @return iterable<string, array{array<string, mixed>}> Missing, mistyped and malformed fields */
    public static function invalidPayloads(): iterable
    {
        $name = 'Name';
        $valid = [
            'languageId' => 11,
            'inLanguageId' => 12,
            'localeId' => null,
            'baseNameId' => null,
            'name' => $name,
        ];
        foreach (array_keys($valid) as $key) {
            $missing = $valid;
            unset($missing[$key]);
            yield 'missing ' . $key => [$missing];
            $ids = ['languageId', 'inLanguageId', 'localeId', 'baseNameId'];
            $invalid = in_array($key, $ids, true) ? ['12', false, true, 1.5, 12.0, 0, -1, '', []] : [null, 0, true, []];
            foreach ($invalid as $value) {
                yield $key . ' ' . var_export($value, true) => [[...$valid, $key => $value]];
            }
        }
        yield 'null languageId' => [[...$valid, 'languageId' => null]];
        yield 'null inLanguageId' => [[...$valid, 'inLanguageId' => null]];
        yield 'same language' => [[...$valid, 'inLanguageId' => 11]];
        yield 'locale without base' => [[...$valid, 'localeId' => 21]];
        yield 'base without locale' => [[...$valid, 'baseNameId' => 31]];
        yield 'legacy codes' => [['languageCode' => 'qv', 'inLanguageCode' => 'qw', 'localeCode' => null, 'name' => 'Name']];
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
        HilosI18nLanguageNameAddActionDTO::fromArray($payload);
    }
}
