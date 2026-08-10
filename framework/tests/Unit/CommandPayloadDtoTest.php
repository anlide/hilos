<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\BaseDTO;
use Hilos\Core\Agent\Exception\BrokenSignalPayloadDtoException;
use Hilos\Core\Agent\Exception\InvalidCommandPayloadException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Context\DbContext;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use PHPUnit\Framework\TestCase;

/**
 * Tests SignalRouter::createCommandPayloadDTO topology-driven command payload hydration.
 */
final class CommandPayloadDtoTest extends TestCase
{
    public function testHydratesDeclaredPayloadIntoTransientSlot(): void
    {
        $request = new CommandRequestDTO('corr-1', 'typed', ['value' => 'hi']);

        $hydrated = new CommandPayloadTestRouter()->createCommandPayloadDTO('typed', $request);

        $this->assertNotSame($request, $hydrated);
        $this->assertSame(['value' => 'hi'], $hydrated->payload);
        $this->assertInstanceOf(RequireFieldCommandData::class, $hydrated->parsedPayload);
        $this->assertSame('hi', $hydrated->parsedPayload->value);
    }

    public function testPassesThroughUnchangedWhenCommandDeclaresNoDto(): void
    {
        $request = new CommandRequestDTO('corr-2', 'plain', ['value' => 'hi']);

        $result = new CommandPayloadTestRouter()->createCommandPayloadDTO('plain', $request);

        $this->assertSame($request, $result);
        $this->assertNull($result->parsedPayload);
    }

    public function testThrowsWhenDeclaredPayloadFailsToHydrate(): void
    {
        $request = new CommandRequestDTO('corr-3', 'typed', []);

        $this->expectException(InvalidCommandPayloadException::class);
        new CommandPayloadTestRouter()->createCommandPayloadDTO('typed', $request);
    }

    public function testHydrationFailureCarriesItsCause(): void
    {
        $request = new CommandRequestDTO('corr-4', 'typed', []);

        try {
            new CommandPayloadTestRouter()->createCommandPayloadDTO('typed', $request);
            $this->fail('Expected InvalidCommandPayloadException');
        } catch (InvalidCommandPayloadException $e) {
            $this->assertInstanceOf(\InvalidArgumentException::class, $e->getPrevious());
            $this->assertStringContainsString('value is required', $e->getMessage());
        }
    }

    public function testIncompletePayloadIsRefusedRatherThanHydratedWithAStub(): void
    {
        $request = new CommandRequestDTO('corr-6', 'helper', ['other' => 'hi']);

        try {
            new CommandPayloadTestRouter()->createCommandPayloadDTO('helper', $request);
            $this->fail('Expected InvalidCommandPayloadException');
        } catch (InvalidCommandPayloadException $e) {
            $this->assertInstanceOf(InvalidFormatException::class, $e->getPrevious());
            $this->assertStringContainsString('value', $e->getMessage());
        }
    }

    public function testBrokenDeclaredClassIsNotReportedAsAnInvalidPayload(): void
    {
        $request = new CommandRequestDTO('corr-5', 'broken', ['value' => 'hi']);

        $this->expectException(BrokenSignalPayloadDtoException::class);
        $this->expectExceptionMessage('broken');

        new CommandPayloadTestRouter()->createCommandPayloadDTO('broken', $request);
    }
}

/**
 * Test payload DTO requiring a non-empty 'value' field.
 */
final class RequireFieldCommandData implements SignalDataInterface
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
     */
    public static function fromArray(array $data): static
    {
        $value = $data['value'] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException('value is required');
        }

        return new static($value);
    }
}

/**
 * Test payload DTO reading its required field through the shared BaseDTO helper.
 */
final class HelperFieldCommandData extends BaseDTO implements SignalDataInterface
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
     * @throws InvalidFormatException When the payload carries no value
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, 'value'));
    }
}

/**
 * Test facade declaring a DTO route for the 'typed' command and a broken one for 'broken'.
 */
final class CommandPayloadTestHilos extends \Hilos\Hilos
{
    public const string MISSING_DTO_CLASS = 'Hilos\Tests\Unit\CommandPayloadTestMissingData';

    /**
     * @return array<string, class-string<SignalDataInterface>> DTO class keyed by command name, one deliberately unresolvable
     */
    public static function getCommandDtoRoutes(): array
    {
        return [
            'typed' => RequireFieldCommandData::class,
            'helper' => HelperFieldCommandData::class,
            'broken' => self::MISSING_DTO_CLASS,
        ];
    }

    protected static function createDb(): DbContext
    {
        throw new \LogicException('createDb is not used in the payload test');
    }
}

/**
 * Test router pinning the facade to the command payload fixture.
 */
final class CommandPayloadTestRouter extends SignalRouter
{
    protected function hilosClass(): string
    {
        return CommandPayloadTestHilos::class;
    }
}
