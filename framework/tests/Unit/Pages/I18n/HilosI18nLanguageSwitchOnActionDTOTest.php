<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\I18n;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLanguageSwitchOnActionDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The language switch-on request has one required, strictly formatted address. */
final class HilosI18nLanguageSwitchOnActionDTOTest extends TestCase
{
    public function testReadsRawAndWrappedPayload(): void
    {
        $raw = [HilosI18nLanguageSwitchOnActionDTO::languageId => 13];
        self::assertSame($raw, HilosI18nLanguageSwitchOnActionDTO::fromArray($raw)->toArray());
        self::assertSame(13, HilosI18nLanguageSwitchOnActionDTO::fromArray([
            SignalPayloadConstants::FIELD_DATA => $raw,
        ])->languageId);
        self::assertSame(
            HilosSignalConstants::HILOS_I18N_LANGUAGE_SWITCH_ON,
            HilosI18nLanguageSwitchOnActionDTO::fromArray($raw)->getAction(),
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid request shapes */
    public static function invalidPayloads(): iterable
    {
        yield 'uppercase' => [[HilosI18nLanguageSwitchOnActionDTO::languageId => 'EN']];
        yield 'three letters' => [[HilosI18nLanguageSwitchOnActionDTO::languageId => 'eng']];
        yield 'empty' => [[HilosI18nLanguageSwitchOnActionDTO::languageId => '']];
        yield 'zero' => [[HilosI18nLanguageSwitchOnActionDTO::languageId => 0]];
        yield 'missing' => [[]];
        yield 'legacy code' => [['languageCode' => 'fr']];
        foreach (['13', -1, 13.0, null, true, false] as $id) {
            yield 'invalid ID ' . var_export($id, true) => [['languageId' => $id]];
        }
    }

    /** @param array<string, mixed> $payload Invalid request */
    #[DataProvider('invalidPayloads')]
    public function testRejectsInvalidCode(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        HilosI18nLanguageSwitchOnActionDTO::fromArray($payload);
    }
}
