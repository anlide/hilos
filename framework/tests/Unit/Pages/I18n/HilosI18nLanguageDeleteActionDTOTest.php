<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\I18n;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Pages\I18n\Details\DTO\AbstractHilosI18nLanguageDeleteActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLanguageDeleteActionDTO;
use Hilos\Pages\I18n\Lists\DTO\HilosI18nLanguageRowDeleteActionDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Both page removal actions accept exactly one strict language address. */
final class HilosI18nLanguageDeleteActionDTOTest extends TestCase
{
    /** @return iterable<string, array{class-string<AbstractHilosI18nLanguageDeleteActionDTO>, string}> Page doors */
    public static function actions(): iterable
    {
        yield 'list' => [HilosI18nLanguageRowDeleteActionDTO::class, HilosSignalConstants::HILOS_I18N_LANGUAGE_ROW_DELETE];
        yield 'card' => [HilosI18nLanguageDeleteActionDTO::class, HilosSignalConstants::HILOS_I18N_LANGUAGE_DELETE];
    }

    /**
     * @param class-string<AbstractHilosI18nLanguageDeleteActionDTO> $dtoClass Action DTO
     * @param string $action Expected action name
     */
    #[DataProvider('actions')]
    public function testRawEnvelopeAndJsonRoundtrip(string $dtoClass, string $action): void
    {
        $payload = ['languageId' => 19];
        $dto = $dtoClass::fromArray($payload);
        self::assertSame(19, $dto->languageId);
        self::assertSame($action, $dto->getAction());
        self::assertSame($payload, $dto->toArray());
        self::assertSame($payload, $dtoClass::fromJson($dto->toJson())->toArray());
        self::assertSame($payload, $dtoClass::fromArray([SignalPayloadConstants::FIELD_DATA => $payload])->toArray());
        self::assertSame([], $dtoClass::SECRET_FIELDS);
    }

    /** @return iterable<string, array{class-string<AbstractHilosI18nLanguageDeleteActionDTO>, array<string, mixed>}> Rejected payloads */
    public static function invalidPayloads(): iterable
    {
        foreach (self::actions() as $door => [$dtoClass]) {
            foreach (['EN', 'eng', 'e', '', "en\n", 'éñ', '19', 0, -1, 19.0, null, true, false] as $code) {
                yield $door . ' ' . var_export($code, true) => [$dtoClass, ['languageId' => $code]];
            }
            yield $door . ' missing' => [$dtoClass, []];
            yield $door . ' legacy code' => [$dtoClass, ['languageCode' => 'uk', 'nativeName' => 'Name', 'rtl' => false]];
            yield $door . ' malformed envelope' => [$dtoClass, [SignalPayloadConstants::FIELD_DATA => 'invalid']];
        }
    }

    /**
     * @param class-string<AbstractHilosI18nLanguageDeleteActionDTO> $dtoClass Action DTO
     * @param array<string, mixed> $payload Rejected payload
     */
    #[DataProvider('invalidPayloads')]
    public function testRejectsInvalidAddress(string $dtoClass, array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        $dtoClass::fromArray($payload);
    }
}
