<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\I18n;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Pages\I18n\Details\DTO\AbstractHilosI18nLanguageUpdateActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLanguageUpdateActionDTO;
use Hilos\Pages\I18n\Lists\DTO\HilosI18nLanguageRowUpdateActionDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Both page actions share the same strict language update payload. */
final class HilosI18nLanguageUpdateActionDTOTest extends TestCase
{
    private const array PAYLOAD = [
        AbstractHilosI18nLanguageUpdateActionDTO::languageCode => 'uk',
        AbstractHilosI18nLanguageUpdateActionDTO::nativeName => 'Українська',
        AbstractHilosI18nLanguageUpdateActionDTO::rtl => false,
    ];

    /** @return iterable<string, array{class-string<AbstractHilosI18nLanguageUpdateActionDTO>, string}> Page action DTOs */
    public static function actions(): iterable
    {
        yield 'card' => [HilosI18nLanguageUpdateActionDTO::class, HilosSignalConstants::HILOS_I18N_LANGUAGE_UPDATE];
        yield 'row' => [HilosI18nLanguageRowUpdateActionDTO::class, HilosSignalConstants::HILOS_I18N_LANGUAGE_ROW_UPDATE];
    }

    /**
     * @param class-string<AbstractHilosI18nLanguageUpdateActionDTO> $dtoClass Page action DTO
     * @param string $action Expected action name
     */
    #[DataProvider('actions')]
    public function testReadsRawAndWrappedPayload(string $dtoClass, string $action): void
    {
        $dto = $dtoClass::fromArray(self::PAYLOAD);
        self::assertSame($action, $dto->getAction());
        self::assertSame(self::PAYLOAD, $dto->toArray());
        self::assertSame(self::PAYLOAD, $dtoClass::fromArray([
            SignalPayloadConstants::FIELD_DATA => self::PAYLOAD,
        ])->toArray());
        self::assertSame([], $dtoClass::SECRET_FIELDS);

        $rtlPayload = array_replace(self::PAYLOAD, [AbstractHilosI18nLanguageUpdateActionDTO::rtl => true]);
        self::assertSame($rtlPayload, $dtoClass::fromArray($rtlPayload)->toArray());
    }

    /** @return iterable<string, array{class-string<AbstractHilosI18nLanguageUpdateActionDTO>, array<string, mixed>}> Invalid requests */
    public static function invalidPayloads(): iterable
    {
        foreach (self::actions() as $door => [$dtoClass]) {
            foreach (['EN', 'eng', '', "en\n", 42] as $invalidCode) {
                yield $door . ' code ' . var_export($invalidCode, true) => [$dtoClass, array_replace(self::PAYLOAD, [
                    AbstractHilosI18nLanguageUpdateActionDTO::languageCode => $invalidCode,
                ])];
            }
            foreach ([
                AbstractHilosI18nLanguageUpdateActionDTO::nativeName => 42,
                AbstractHilosI18nLanguageUpdateActionDTO::rtl => 1,
            ] as $key => $invalidValue) {
                yield $door . ' wrong type ' . $key => [$dtoClass, array_replace(self::PAYLOAD, [$key => $invalidValue])];
            }
            foreach (array_keys(self::PAYLOAD) as $key) {
                $missing = self::PAYLOAD;
                unset($missing[$key]);
                yield $door . ' missing ' . $key => [$dtoClass, $missing];
            }
            yield $door . ' malformed envelope' => [$dtoClass, [SignalPayloadConstants::FIELD_DATA => 'invalid']];
        }
    }

    /**
     * @param class-string<AbstractHilosI18nLanguageUpdateActionDTO> $dtoClass Page action DTO
     * @param array<string, mixed> $payload Invalid request
     */
    #[DataProvider('invalidPayloads')]
    public function testRejectsInvalidPayload(string $dtoClass, array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        $dtoClass::fromArray($payload);
    }

    /**
     * @param class-string<AbstractHilosI18nLanguageUpdateActionDTO> $dtoClass Page action DTO
     * @param string $action Expected action name
     */
    #[DataProvider('actions')]
    public function testLeavesNativeNameValidationToTheWriteDoor(string $dtoClass, string $action): void
    {
        foreach (['   ', str_repeat('ї', 65)] as $nativeName) {
            $dto = new $dtoClass('uk', $nativeName, false);
            self::assertSame($nativeName, $dto->nativeName);
            self::assertSame($action, $dto->getAction());
        }
    }
}
