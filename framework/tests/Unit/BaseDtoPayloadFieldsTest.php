<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\TestCase;

/**
 * Tests the payload-field helpers every DTO inherits from BaseDTO.
 *
 * A field has two roles and no third one, so the suite pins both ends of each
 * pair: a required field refuses an absent key and a key of the wrong type,
 * while an optional one answers null to absence and refuses the wrong type just
 * the same. The empty string is pinned separately — it is a value the sender
 * filled in, not a missing field, and a helper that rejected it would push
 * validation of blank input out of the handler that owns it.
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
}

/**
 * Opens the inherited protected helpers to the suite without changing them.
 */
final class PayloadFieldsProbeDTO extends BaseDTO
{
    /**
     * @return array<string, mixed> Empty payload; the probe carries no fields of its own
     */
    public function toArray(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $data DTO payload
     * @return static Restored probe instance
     */
    public static function fromArray(array $data): static
    {
        return new static();
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
}
