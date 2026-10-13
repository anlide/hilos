<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\I18n;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\Entity\Item\LanguageName as EntityLanguageName;
use Hilos\HilosException;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLanguageNameOverrideDeleteActionDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The removal request preserves literal text and refuses malformed coordinates. */
final class HilosI18nLanguageNameOverrideDeleteActionDTOTest extends TestCase
{
    /** @return iterable<string, array{string}> Literal values accepted by the name column */
    public static function names(): iterable
    {
        yield 'ordinary' => ['Fixture name'];
        yield 'empty' => [''];
        yield 'whitespace' => ["  name \n"];
        yield 'maximum character width' => [str_repeat('é', EntityLanguageName::NAME_MAX_CHARS)];
    }

    /**
     * @param string $name Expected text, without trimming
     * @throws HilosException When serialization or validation unexpectedly fails
     */
    #[DataProvider('names')]
    public function testRawEnvelopeAndJsonRoundtrip(string $name): void
    {
        $payload = ['languageCode' => 'qv', 'inLanguageCode' => 'qw', 'localeCode' => 'qw-GB', 'expectedName' => $name];
        $dto = HilosI18nLanguageNameOverrideDeleteActionDTO::fromArray([...$payload, 'extra' => 'ignored']);
        self::assertSame($name, $dto->expectedName);
        self::assertSame($payload, $dto->toArray());
        self::assertSame(HilosSignalConstants::HILOS_I18N_LANGUAGE_NAME_OVERRIDE_DELETE, $dto->getAction());
        self::assertSame([], HilosI18nLanguageNameOverrideDeleteActionDTO::SECRET_FIELDS);
        self::assertSame($payload, HilosI18nLanguageNameOverrideDeleteActionDTO::fromJson($dto->toJson())->toArray());
        self::assertSame($payload, HilosI18nLanguageNameOverrideDeleteActionDTO::fromArray([
            SignalPayloadConstants::FIELD_DATA => $payload,
        ])->toArray());
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid coordinates and text */
    public static function invalidPayloads(): iterable
    {
        $valid = ['languageCode' => 'qv', 'inLanguageCode' => 'qw', 'localeCode' => 'qw-GB', 'expectedName' => 'Fixture name'];
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
        yield 'name too wide' => [[...$valid, 'expectedName' => str_repeat('é', EntityLanguageName::NAME_MAX_CHARS + 1)]];
        yield 'empty payload' => [[]];
        foreach ([null, 'invalid', 42, []] as $envelope) {
            yield 'envelope ' . var_export($envelope, true) => [[SignalPayloadConstants::FIELD_DATA => $envelope]];
        }
        foreach (['qw', 'qw-gb', 'QW-GB', 'qw-USA', 'qw-GB\n', 'en-GB', 'qw-1B', ' qw-GB'] as $locale) {
            yield 'locale ' . $locale => [[...$valid, 'localeCode' => $locale]];
        }
    }

    /**
     * @param array<string, mixed> $payload Rejected payload
     * @throws InvalidFormatException The expected payload refusal
     */
    #[DataProvider('invalidPayloads')]
    public function testRejectsInvalidPayload(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        HilosI18nLanguageNameOverrideDeleteActionDTO::fromArray($payload);
    }
}
