<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\Exception\BrokenSignalPayloadDtoException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalPayloadHydrator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Tests the single hydration point shared by the agent, page signal, and command parsers.
 */
final class SignalPayloadHydratorTest extends TestCase
{
    private const string MISSING_CLASS = 'Hilos\Tests\Unit\HydratorTestMissingPayload';

    public function testHydratesDeclaredDtoFromRawData(): void
    {
        $payload = SignalPayloadHydrator::hydrate(
            ['value' => 'hydrated'],
            HydratorTestPayload::class,
            'typed_test_signal',
        );

        $this->assertInstanceOf(HydratorTestPayload::class, $payload);
        $this->assertSame('hydrated', $payload->value);
    }

    public function testThrowsBrokenWhenDeclaredClassDoesNotExist(): void
    {
        $this->expectException(BrokenSignalPayloadDtoException::class);
        $this->expectExceptionMessage('typed_test_signal');

        SignalPayloadHydrator::hydrate(['value' => 'hydrated'], self::MISSING_CLASS, 'typed_test_signal');
    }

    public function testThrowsBrokenWhenDeclaredClassIsNotSignalData(): void
    {
        $this->expectException(BrokenSignalPayloadDtoException::class);
        $this->expectExceptionMessage(HydratorTestForeignClass::class);

        SignalPayloadHydrator::hydrate([], HydratorTestForeignClass::class, 'typed_test_signal');
    }

    public function testFromArrayFailurePropagatesUnchanged(): void
    {
        try {
            SignalPayloadHydrator::hydrate([], HydratorTestPayload::class, 'typed_test_signal');
            $this->fail('Expected the fromArray failure to reach the caller');
        } catch (Throwable $e) {
            $this->assertNotInstanceOf(BrokenSignalPayloadDtoException::class, $e);
            $this->assertInstanceOf(InvalidArgumentException::class, $e);
            $this->assertSame('value is required', $e->getMessage());
        }
    }
}

/**
 * Test payload DTO requiring a non-empty 'value' field.
 */
final class HydratorTestPayload implements SignalDataInterface
{
    /**
     * @param string $value Required payload value
     */
    public function __construct(public readonly string $value)
    {
    }

    /**
     * @return array<string, mixed> Wire payload
     */
    public function toArray(): array
    {
        return ['value' => $this->value];
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static Restored payload
     * @throws InvalidArgumentException When the required value is missing
     */
    public static function fromArray(array $data): static
    {
        $value = $data['value'] ?? null;
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException('value is required');
        }

        return new static($value);
    }
}

/**
 * Existing class that is not a signal payload, for the broken-declaration branch.
 */
final class HydratorTestForeignClass
{
}
