<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\I18n;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Pages\I18n\Lists\DTO\HilosI18nLanguageAddActionDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The language add request requires a strict code, a name string and a boolean direction. */
final class HilosI18nLanguageAddActionDTOTest extends TestCase
{
    public function testReadsRawAndWrappedPayload(): void
    {
        $raw = [
            HilosI18nLanguageAddActionDTO::languageCode => 'pt',
            HilosI18nLanguageAddActionDTO::nativeName => 'Português editado',
            HilosI18nLanguageAddActionDTO::rtl => true,
        ];
        $dto = HilosI18nLanguageAddActionDTO::fromArray($raw);
        self::assertSame($raw, $dto->toArray());
        self::assertSame($raw, HilosI18nLanguageAddActionDTO::fromArray([
            SignalPayloadConstants::FIELD_DATA => $raw,
        ])->toArray());
        self::assertSame(HilosSignalConstants::HILOS_I18N_LANGUAGE_ADD, $dto->getAction());
        self::assertSame([], HilosI18nLanguageAddActionDTO::SECRET_FIELDS);
    }

    public function testLeavesNameValidationToTheWriteDoor(): void
    {
        self::assertSame('', HilosI18nLanguageAddActionDTO::fromArray([
            HilosI18nLanguageAddActionDTO::languageCode => 'cy',
            HilosI18nLanguageAddActionDTO::nativeName => '',
            HilosI18nLanguageAddActionDTO::rtl => false,
        ])->nativeName);
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid request shapes */
    public static function invalidPayloads(): iterable
    {
        $raw = [
            HilosI18nLanguageAddActionDTO::languageCode => 'pt',
            HilosI18nLanguageAddActionDTO::nativeName => 'Português',
            HilosI18nLanguageAddActionDTO::rtl => false,
        ];
        foreach (['EN', 'eng', '', "en\n", 42] as $code) {
            yield 'code ' . json_encode($code) => [array_replace($raw, [HilosI18nLanguageAddActionDTO::languageCode => $code])];
        }
        foreach ([0, 1, 'false', null] as $rtl) {
            yield 'rtl ' . json_encode($rtl) => [array_replace($raw, [HilosI18nLanguageAddActionDTO::rtl => $rtl])];
        }
        foreach (array_keys($raw) as $key) {
            $missing = $raw;
            unset($missing[$key]);
            yield 'missing ' . $key => [$missing];
        }
        yield 'name not a string' => [array_replace($raw, [HilosI18nLanguageAddActionDTO::nativeName => 42])];
        yield 'invalid envelope' => [[SignalPayloadConstants::FIELD_DATA => 'not an object']];
    }

    /** @param array<string, mixed> $payload Invalid request */
    #[DataProvider('invalidPayloads')]
    public function testRejectsInvalidPayload(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        HilosI18nLanguageAddActionDTO::fromArray($payload);
    }
}
