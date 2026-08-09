<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalNameInterface;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use PHPUnit\Framework\TestCase;

/**
 * Tests the signal envelope contract (HIL-547).
 *
 * The envelope is what every reader of a signal name holds: the core is typed by
 * SignalNameInterface, so the "a name is never empty" guarantee has to be enforced
 * here as well as in SignalName, both when a signal is built in process and when it
 * arrives off the wire.
 */
final class SignalDTOTest extends TestCase
{
    public function testRoundTripsThroughItsArrayForm(): void
    {
        $signal = new SignalDTO(
            new SignalSource(SignalSource::AGENT, 'chat_agent', '7'),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName('chat_message'),
            new SignalData(['text' => 'hi']),
            ['userActionId' => 42],
        );

        $restored = SignalDTO::fromArray($signal->toArray());

        $this->assertSame(SignalSource::AGENT, $restored->signalSource->getSource());
        $this->assertSame('chat_agent', $restored->signalSource->getType());
        $this->assertSame('7', $restored->signalSource->getIndex());
        $this->assertSame(SignalTypeConstants::AGENT_SIGNAL, $restored->signalType->getType());
        $this->assertSame('chat_message', $restored->signalName->getName());
        $this->assertSame(['text' => 'hi'], $restored->data->toArray());
        $this->assertSame(['userActionId' => 42], $restored->meta);
    }

    public function testRejectsAnEmptyNameOnConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // Not a SignalName: that one already refuses to be empty, and the point
        // here is the envelope holding the line for a project's own implementation.
        new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalDTOTestUnnamedSignalName(),
            new SignalData(),
        );
    }

    public function testRejectsAnEmptyNameOffTheWire(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SignalDTO::fromArray($this->wireArray(''));
    }

    public function testRejectsANonStringNameOffTheWire(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SignalDTO::fromArray($this->wireArray(7));
    }

    public function testRejectsAMissingNameOffTheWire(): void
    {
        $payload = $this->wireArray('chat_message');
        unset($payload['signalName']);

        $this->expectException(InvalidArgumentException::class);

        SignalDTO::fromArray($payload);
    }

    /**
     * Builds a wire payload carrying the given name.
     *
     * @param mixed $signalName Name to put on the wire
     * @return array<string, mixed> Wire payload
     */
    private function wireArray(mixed $signalName): array
    {
        return [
            'signalSource' => ['source' => SignalSource::AGENT, 'type' => null, 'index' => null],
            'signalType' => SignalTypeConstants::AGENT_SIGNAL,
            'signalName' => $signalName,
            'data' => [],
            'dataType' => SignalData::class,
        ];
    }
}

/**
 * A signal name implementation that answers the empty string.
 *
 * Stands in for a project implementation of SignalNameInterface that does not
 * carry SignalName's own guarantee.
 */
final class SignalDTOTestUnnamedSignalName implements SignalNameInterface
{
    /**
     * @return string Empty signal name
     */
    public function getName(): string
    {
        return '';
    }
}
