<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use Hilos\Core\Exception\MalformedInput;
use Hilos\Core\Exception\NonArrayPayloadException;
use PHPUnit\Framework\TestCase;

/**
 * Tests the JSON reader and the payload-field helpers every DTO inherits from BaseDTO.
 *
 * A field has two roles and no third one, so the suite pins both ends of each
 * pair: a required field refuses an absent key and a key of the wrong type,
 * while an optional one answers null to absence and refuses the wrong type just
 * the same. The empty string is pinned separately — it is a value the sender
 * filled in, not a missing field, and a helper that rejected it would push
 * validation of blank input out of the handler that owns it.
 *
 * The reader in front of them is pinned by the same logic one step earlier: a
 * string that does not decode and a string that decodes into the wrong shape are
 * two different failures, and the literal `null` belongs to the second one.
 */
final class BaseDtoPayloadFieldsTest extends TestCase
{
    public function testRequiredStringReadsTheValueUnderTheKey(): void
    {
        $this->assertSame('chat', PayloadFieldsProbeDTO::readRequiredString(['page' => 'chat'], 'page'));
    }

    public function testRequiredStringPassesAnEmptyStringThrough(): void
    {
        $this->assertSame('', PayloadFieldsProbeDTO::readRequiredString(['reason' => ''], 'reason'));
    }

    public function testRequiredStringRefusesAnAbsentKeyAndNamesIt(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('page');

        PayloadFieldsProbeDTO::readRequiredString(['other' => 'chat'], 'page');
    }

    public function testRequiredStringRefusesANullValue(): void
    {
        $this->expectException(InvalidFormatException::class);

        PayloadFieldsProbeDTO::readRequiredString(['page' => null], 'page');
    }

    public function testRequiredStringRefusesAValueOfAnotherTypeInsteadOfCastingIt(): void
    {
        $this->expectException(InvalidFormatException::class);

        PayloadFieldsProbeDTO::readRequiredString(['page' => 7], 'page');
    }

    public function testRequiredIntReadsTheValueUnderTheKey(): void
    {
        $this->assertSame(404, PayloadFieldsProbeDTO::readRequiredInt(['httpCode' => 404], 'httpCode'));
    }

    public function testRequiredIntRefusesAnAbsentKey(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('httpCode');

        PayloadFieldsProbeDTO::readRequiredInt([], 'httpCode');
    }

    public function testRequiredIntRefusesTheDigitsOfANumericString(): void
    {
        $this->expectException(InvalidFormatException::class);

        PayloadFieldsProbeDTO::readRequiredInt(['httpCode' => '404'], 'httpCode');
    }

    public function testRequiredArrayReadsTheValueUnderTheKey(): void
    {
        $this->assertSame(
            ['text' => 'hi'],
            PayloadFieldsProbeDTO::readRequiredArray(['payload' => ['text' => 'hi']], 'payload'),
        );
    }

    public function testRequiredArrayPassesAnEmptyArrayThrough(): void
    {
        $this->assertSame([], PayloadFieldsProbeDTO::readRequiredArray(['payload' => []], 'payload'));
    }

    public function testRequiredArrayRefusesAnAbsentKey(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('payload');

        PayloadFieldsProbeDTO::readRequiredArray([], 'payload');
    }

    public function testRequiredArrayRefusesAScalar(): void
    {
        $this->expectException(InvalidFormatException::class);

        PayloadFieldsProbeDTO::readRequiredArray(['payload' => 'hi'], 'payload');
    }

    public function testRequiredBoolReadsATrueValue(): void
    {
        $this->assertTrue(PayloadFieldsProbeDTO::readRequiredBool(['admin' => true], 'admin'));
    }

    public function testRequiredBoolPassesALoweredFlagThrough(): void
    {
        $this->assertFalse(PayloadFieldsProbeDTO::readRequiredBool(['admin' => false], 'admin'));
    }

    public function testRequiredBoolRefusesAnAbsentKeyAndNamesIt(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('admin');

        PayloadFieldsProbeDTO::readRequiredBool([], 'admin');
    }

    public function testRequiredBoolRefusesAValueOfAnotherTypeInsteadOfCastingIt(): void
    {
        $this->expectException(InvalidFormatException::class);

        PayloadFieldsProbeDTO::readRequiredBool(['admin' => 1], 'admin');
    }

    public function testOptionalStringAnswersNullWhenTheKeyIsAbsent(): void
    {
        $this->assertNull(PayloadFieldsProbeDTO::readOptionalString([], 'message'));
    }

    public function testOptionalStringAnswersNullWhenTheKeyHoldsNull(): void
    {
        $this->assertNull(PayloadFieldsProbeDTO::readOptionalString(['message' => null], 'message'));
    }

    public function testOptionalStringReadsThePresentValue(): void
    {
        $this->assertSame('saved', PayloadFieldsProbeDTO::readOptionalString(['message' => 'saved'], 'message'));
    }

    public function testOptionalStringRefusesAPresentValueOfAnotherType(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('message');

        PayloadFieldsProbeDTO::readOptionalString(['message' => 7], 'message');
    }

    public function testOptionalIntAnswersNullWhenTheKeyIsAbsent(): void
    {
        $this->assertNull(PayloadFieldsProbeDTO::readOptionalInt([], 'retryAfter'));
    }

    public function testOptionalIntReadsThePresentValue(): void
    {
        $this->assertSame(30, PayloadFieldsProbeDTO::readOptionalInt(['retryAfter' => 30], 'retryAfter'));
    }

    public function testOptionalIntRefusesAPresentValueOfAnotherType(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('retryAfter');

        PayloadFieldsProbeDTO::readOptionalInt(['retryAfter' => '30'], 'retryAfter');
    }

    public function testOptionalArrayAnswersNullWhenTheKeyIsAbsent(): void
    {
        $this->assertNull(PayloadFieldsProbeDTO::readOptionalArray([], 'reply'));
    }

    public function testOptionalArrayReadsThePresentValue(): void
    {
        $this->assertSame(['id' => 1], PayloadFieldsProbeDTO::readOptionalArray(['reply' => ['id' => 1]], 'reply'));
    }

    public function testOptionalArrayRefusesAPresentValueOfAnotherType(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('reply');

        PayloadFieldsProbeDTO::readOptionalArray(['reply' => 'hi'], 'reply');
    }

    public function testOptionalBoolAnswersNullWhenTheKeyIsAbsent(): void
    {
        $this->assertNull(PayloadFieldsProbeDTO::readOptionalBool([], 'monopolistic'));
    }

    public function testOptionalBoolTellsALoweredFlagFromAnAbsentOne(): void
    {
        $this->assertFalse(PayloadFieldsProbeDTO::readOptionalBool(['monopolistic' => false], 'monopolistic'));
    }

    public function testOptionalBoolRefusesAPresentValueOfAnotherType(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('monopolistic');

        PayloadFieldsProbeDTO::readOptionalBool(['monopolistic' => 'yes'], 'monopolistic');
    }

    public function testRequiredFloatReadsTheValueUnderTheKey(): void
    {
        $this->assertSame(12.5, PayloadFieldsProbeDTO::readRequiredFloat(['cpu' => 12.5], 'cpu'));
    }

    public function testRequiredFloatWidensAnIntegerSoADtoSurvivesItsOwnSerialization(): void
    {
        $this->assertSame(0.0, PayloadFieldsProbeDTO::readRequiredFloat(['cpu' => 0], 'cpu'));
    }

    public function testRequiredFloatRefusesAnAbsentKeyAndNamesIt(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('cpu');

        PayloadFieldsProbeDTO::readRequiredFloat([], 'cpu');
    }

    public function testRequiredFloatRefusesANumberWrittenAsText(): void
    {
        $this->expectException(InvalidFormatException::class);

        PayloadFieldsProbeDTO::readRequiredFloat(['cpu' => '12.5'], 'cpu');
    }

    public function testOptionalFloatAnswersNullWhenTheKeyIsAbsent(): void
    {
        $this->assertNull(PayloadFieldsProbeDTO::readOptionalFloat([], 'cpu'));
    }

    public function testOptionalFloatWidensAPresentInteger(): void
    {
        $this->assertSame(3.0, PayloadFieldsProbeDTO::readOptionalFloat(['cpu' => 3], 'cpu'));
    }

    public function testOptionalFloatRefusesAPresentValueOfAnotherType(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('cpu');

        PayloadFieldsProbeDTO::readOptionalFloat(['cpu' => '3'], 'cpu');
    }

    public function testRequiredIntOrStringReadsAnIntegerKey(): void
    {
        $this->assertSame(17, PayloadFieldsProbeDTO::readRequiredIntOrString(['rowKey' => 17], 'rowKey'));
    }

    public function testRequiredIntOrStringReadsAStringKey(): void
    {
        $this->assertSame('u-17', PayloadFieldsProbeDTO::readRequiredIntOrString(['rowKey' => 'u-17'], 'rowKey'));
    }

    public function testRequiredIntOrStringRefusesAnAbsentKeyAndNamesIt(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('rowKey');

        PayloadFieldsProbeDTO::readRequiredIntOrString([], 'rowKey');
    }

    public function testRequiredIntOrStringRefusesAnArray(): void
    {
        $this->expectException(InvalidFormatException::class);

        PayloadFieldsProbeDTO::readRequiredIntOrString(['rowKey' => ['id' => 17]], 'rowKey');
    }

    public function testRequiredIntOrStringRefusesAFlag(): void
    {
        $this->expectException(InvalidFormatException::class);

        PayloadFieldsProbeDTO::readRequiredIntOrString(['rowKey' => true], 'rowKey');
    }

    public function testOptionalIntOrStringAnswersNullWhenTheKeyIsAbsent(): void
    {
        $this->assertNull(PayloadFieldsProbeDTO::readOptionalIntOrString([], 'rowKey'));
    }

    public function testOptionalIntOrStringReadsEitherTypeWhenPresent(): void
    {
        $this->assertSame(17, PayloadFieldsProbeDTO::readOptionalIntOrString(['rowKey' => 17], 'rowKey'));
        $this->assertSame('u-17', PayloadFieldsProbeDTO::readOptionalIntOrString(['rowKey' => 'u-17'], 'rowKey'));
    }

    public function testOptionalIntOrStringRefusesAPresentValueOfAnotherType(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('rowKey');

        PayloadFieldsProbeDTO::readOptionalIntOrString(['rowKey' => 1.5], 'rowKey');
    }

    public function testFromJsonRestoresTheDtoFromAValidObject(): void
    {
        $probe = PayloadFieldsProbeDTO::fromJson('{"page":"chat","httpCode":404}');

        $this->assertSame(['page' => 'chat', 'httpCode' => 404], $probe->restoredFrom);
    }

    public function testFromJsonRefusesAStringThatDoesNotDecode(): void
    {
        $this->expectException(InvalidJsonException::class);

        PayloadFieldsProbeDTO::fromJson('{"a":}');
    }

    public function testFromJsonRefusesANumberThatDecodesIntoNoArray(): void
    {
        $this->expectException(NonArrayPayloadException::class);

        PayloadFieldsProbeDTO::fromJson('123');
    }

    public function testFromJsonRefusesAQuotedStringThatDecodesIntoNoArray(): void
    {
        $this->expectException(NonArrayPayloadException::class);

        PayloadFieldsProbeDTO::fromJson('"text"');
    }

    public function testFromJsonReadsTheLiteralNullAsAPayloadOfTheWrongShapeAndNotAsAFailureToDecode(): void
    {
        $this->expectException(NonArrayPayloadException::class);

        PayloadFieldsProbeDTO::fromJson('null');
    }

    public function testBothReaderRefusalsCarryTheMarkerAGuardReadsTheLogLevelBy(): void
    {
        $this->assertInstanceOf(MalformedInput::class, new InvalidJsonException('undecodable'));
        $this->assertInstanceOf(MalformedInput::class, new NonArrayPayloadException('not an array'));
    }

    public function testBothReaderRefusalsReachTheCatchThatAlreadyAnswersABrokenPayload(): void
    {
        $this->assertInstanceOf(InvalidFormatException::class, new InvalidJsonException('undecodable'));
        $this->assertInstanceOf(InvalidFormatException::class, new NonArrayPayloadException('not an array'));
    }
}

/**
 * Opens the inherited protected helpers to the suite without changing them.
 */
final class PayloadFieldsProbeDTO extends BaseDTO
{
    /** @var array<string, mixed> Payload the probe was restored from, so a reader test can see what arrived */
    public array $restoredFrom = [];

    /**
     * @return array<string, mixed> Empty payload; the probe carries no fields of its own
     */
    public function toArray(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $data DTO payload
     * @return static Restored probe instance holding the payload it was built from
     */
    public static function fromArray(array $data): static
    {
        $probe = new static();
        $probe->restoredFrom = $data;

        return $probe;
    }

    /**
     * @param array<string, mixed> $data Payload the DTO is being built from
     * @param string $key Payload key holding the field
     * @return string Value stored under the key
     * @throws InvalidFormatException When the key is absent or holds a non-string
     */
    public static function readRequiredString(array $data, string $key): string
    {
        return self::requireString($data, $key);
    }

    /**
     * @param array<string, mixed> $data Payload the DTO is being built from
     * @param string $key Payload key holding the field
     * @return int Value stored under the key
     * @throws InvalidFormatException When the key is absent or holds a non-integer
     */
    public static function readRequiredInt(array $data, string $key): int
    {
        return self::requireInt($data, $key);
    }

    /**
     * @param array<string, mixed> $data Payload the DTO is being built from
     * @param string $key Payload key holding the field
     * @return array<string, mixed> Value stored under the key
     * @throws InvalidFormatException When the key is absent or holds a non-array
     */
    public static function readRequiredArray(array $data, string $key): array
    {
        return self::requireArray($data, $key);
    }

    /**
     * @param array<string, mixed> $data Payload the DTO is being built from
     * @param string $key Payload key holding the field
     * @return bool Value stored under the key
     * @throws InvalidFormatException When the key is absent or holds a non-boolean
     */
    public static function readRequiredBool(array $data, string $key): bool
    {
        return self::requireBool($data, $key);
    }

    /**
     * @param array<string, mixed> $data Payload the DTO is being built from
     * @param string $key Payload key holding the field
     * @return ?string Value stored under the key, or null when the key is absent
     * @throws InvalidFormatException When the key is present and holds a non-string
     */
    public static function readOptionalString(array $data, string $key): ?string
    {
        return self::optionalString($data, $key);
    }

    /**
     * @param array<string, mixed> $data Payload the DTO is being built from
     * @param string $key Payload key holding the field
     * @return ?int Value stored under the key, or null when the key is absent
     * @throws InvalidFormatException When the key is present and holds a non-integer
     */
    public static function readOptionalInt(array $data, string $key): ?int
    {
        return self::optionalInt($data, $key);
    }

    /**
     * @param array<string, mixed> $data Payload the DTO is being built from
     * @param string $key Payload key holding the field
     * @return ?array<string, mixed> Value stored under the key, or null when the key is absent
     * @throws InvalidFormatException When the key is present and holds a non-array
     */
    public static function readOptionalArray(array $data, string $key): ?array
    {
        return self::optionalArray($data, $key);
    }

    /**
     * @param array<string, mixed> $data Payload the DTO is being built from
     * @param string $key Payload key holding the field
     * @return ?bool Value stored under the key, or null when the key is absent
     * @throws InvalidFormatException When the key is present and holds a non-boolean
     */
    public static function readOptionalBool(array $data, string $key): ?bool
    {
        return self::optionalBool($data, $key);
    }

    /**
     * @param array<string, mixed> $data Payload the DTO is being built from
     * @param string $key Payload key holding the field
     * @return float Value stored under the key
     * @throws InvalidFormatException When the key is absent or holds neither a float nor an integer
     */
    public static function readRequiredFloat(array $data, string $key): float
    {
        return self::requireFloat($data, $key);
    }

    /**
     * @param array<string, mixed> $data Payload the DTO is being built from
     * @param string $key Payload key holding the field
     * @return ?float Value stored under the key, or null when the key is absent
     * @throws InvalidFormatException When the key is present and holds neither a float nor an integer
     */
    public static function readOptionalFloat(array $data, string $key): ?float
    {
        return self::optionalFloat($data, $key);
    }

    /**
     * @param array<string, mixed> $data Payload the DTO is being built from
     * @param string $key Payload key holding the field
     * @return int|string Value stored under the key
     * @throws InvalidFormatException When the key is absent or holds neither an integer nor a string
     */
    public static function readRequiredIntOrString(array $data, string $key): int|string
    {
        return self::requireIntOrString($data, $key);
    }

    /**
     * @param array<string, mixed> $data Payload the DTO is being built from
     * @param string $key Payload key holding the field
     * @return int|string|null Value stored under the key, or null when the key is absent
     * @throws InvalidFormatException When the key is present and holds neither an integer nor a string
     */
    public static function readOptionalIntOrString(array $data, string $key): int|string|null
    {
        return self::optionalIntOrString($data, $key);
    }
}
