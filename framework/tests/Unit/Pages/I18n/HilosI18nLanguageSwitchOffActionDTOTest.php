<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\I18n;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLanguageSwitchOffActionDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The language switch-off request has one required, strictly formatted address. */
final class HilosI18nLanguageSwitchOffActionDTOTest extends TestCase
{
    public function testReadsRawAndWrappedPayload(): void
    {
        $raw = [HilosI18nLanguageSwitchOffActionDTO::languageCode => 'fr'];
        self::assertSame($raw, HilosI18nLanguageSwitchOffActionDTO::fromArray($raw)->toArray());
        self::assertSame('fr', HilosI18nLanguageSwitchOffActionDTO::fromArray([
            SignalPayloadConstants::FIELD_DATA => $raw,
        ])->languageCode);
        self::assertSame(
            HilosSignalConstants::HILOS_I18N_LANGUAGE_SWITCH_OFF,
            HilosI18nLanguageSwitchOffActionDTO::fromArray($raw)->getAction(),
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid request shapes */
    public static function invalidPayloads(): iterable
    {
        yield 'uppercase' => [[HilosI18nLanguageSwitchOffActionDTO::languageCode => 'EN']];
        yield 'three letters' => [[HilosI18nLanguageSwitchOffActionDTO::languageCode => 'eng']];
        yield 'empty' => [[HilosI18nLanguageSwitchOffActionDTO::languageCode => '']];
        yield 'not a string' => [[HilosI18nLanguageSwitchOffActionDTO::languageCode => 42]];
        yield 'missing' => [[]];
    }

    /** @param array<string, mixed> $payload Invalid request */
    #[DataProvider('invalidPayloads')]
    public function testRejectsInvalidCode(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        HilosI18nLanguageSwitchOffActionDTO::fromArray($payload);
    }
}
