<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for worker execution metadata scoping.
 */
final class ExecutionContextTest extends TestCase
{
    public function tearDown(): void
    {
        ExecutionContext::clear();

        parent::tearDown();
    }

    public function testRunScopesAgentAndAcceptKeyThenRestoresPreviousFrame(): void
    {
        ExecutionContext::setCurrentAgentId('outer-agent');
        ExecutionContext::setCurrentAcceptKey('outer-ak');

        $result = ExecutionContext::run(
            new ExecutionFrame('inner-agent', 'inner-ak'),
            static function (): string {
                self::assertSame('inner-agent', ExecutionContext::currentAgentId());
                self::assertSame('inner-ak', ExecutionContext::currentAcceptKey());

                return 'done';
            },
        );

        $this->assertSame('done', $result);
        $this->assertSame('outer-agent', ExecutionContext::currentAgentId());
        $this->assertSame('outer-ak', ExecutionContext::currentAcceptKey());
    }

    public function testRunRestoresPreviousFrameAfterException(): void
    {
        ExecutionContext::setCurrentAgentId('outer-agent');

        try {
            ExecutionContext::run(
                new ExecutionFrame('inner-agent', 'inner-ak'),
                static function (): void {
                    throw new RuntimeException('context failure');
                },
            );
        } catch (RuntimeException) {
        }

        $this->assertSame('outer-agent', ExecutionContext::currentAgentId());
        $this->assertNull(ExecutionContext::currentAcceptKey());
    }

    public function testPushPopRestoresPreviousFrame(): void
    {
        ExecutionContext::setCurrentAgentId('outer-agent');

        $token = ExecutionContext::push(new ExecutionFrame('inner-agent', 'inner-ak'));
        try {
            $this->assertSame('inner-agent', ExecutionContext::currentAgentId());
            $this->assertSame('inner-ak', ExecutionContext::currentAcceptKey());
        } finally {
            ExecutionContext::pop($token);
        }

        $this->assertSame('outer-agent', ExecutionContext::currentAgentId());
        $this->assertNull(ExecutionContext::currentAcceptKey());
    }

    public function testClearCurrentAgentIdIfOnlyClearsMatchingAgent(): void
    {
        ExecutionContext::setCurrentAgentId('agent-a');
        ExecutionContext::clearCurrentAgentIdIf('agent-b');
        $this->assertSame('agent-a', ExecutionContext::currentAgentId());

        ExecutionContext::clearCurrentAgentIdIf('agent-a');
        $this->assertNull(ExecutionContext::currentAgentId());
    }
}
