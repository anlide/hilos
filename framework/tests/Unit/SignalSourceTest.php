<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Router\SignalSource;
use PHPUnit\Framework\TestCase;

/**
 * Tests that a sender is spelled out in full, not merely by its kind (HIL-859).
 *
 * The handler of an agent signal keys per-sender state by this string, so two senders of
 * one kind have to produce two different names - and one sender has to produce the same
 * name every time, in the worker and in the daemon log alike.
 */
final class SignalSourceTest extends TestCase
{
    public function testNamesASenderThatHasNeitherTypeNorIndex(): void
    {
        $this->assertSame(
            'daemon',
            SignalSource::describe(new SignalSource(SignalSource::DAEMON)),
        );
    }

    public function testNamesAnAgentByItsType(): void
    {
        $this->assertSame(
            'agent/hilos_logs',
            SignalSource::describe(new SignalSource(SignalSource::AGENT, 'hilos_logs')),
        );
    }

    public function testTellsTwoInstancesOfOneAgentTypeApart(): void
    {
        $this->assertSame(
            'agent/chat_agent#7',
            SignalSource::describe(new SignalSource(SignalSource::AGENT, 'chat_agent', '7')),
        );
    }

    public function testTreatsAnEmptyPartAsAnAbsentOne(): void
    {
        // Not pedantry: the empty string arrives off the wire beside null, and a separator
        // kept for it would give one sender two names - 'worker/' beside 'worker'.
        $this->assertSame(
            'worker',
            SignalSource::describe(new SignalSource(SignalSource::WORKER, '', '')),
        );
    }
}
