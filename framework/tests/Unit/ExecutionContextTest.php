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

    public function testWithOriginScopesBothHalvesOfAnOriginAndRestoresThem(): void
    {
        ExecutionContext::setCurrentAgentId('backup');

        ExecutionContext::withOrigin('initiator-ak', 'req-9', static function (): void {
            self::assertSame('backup', ExecutionContext::currentAgentId());
            self::assertSame('initiator-ak', ExecutionContext::currentAcceptKey());
            self::assertSame('req-9', ExecutionContext::currentRequestId());
        });

        $this->assertSame('backup', ExecutionContext::currentAgentId());
        $this->assertNull(ExecutionContext::currentAcceptKey());
        $this->assertNull(ExecutionContext::currentRequestId());
    }

    public function testWithOriginTakesAnAcceptKeyWithoutAnAction(): void
    {
        // A resumed frame stamps the connection back on and nothing else: the write it is
        // about to make belongs to that tab, but to no press of any button.
        ExecutionContext::withOrigin('initiator-ak', null, static function (): void {
            self::assertSame('initiator-ak', ExecutionContext::currentAcceptKey());
            self::assertNull(ExecutionContext::currentRequestId());
        });
    }

    public function testSettingTheAcceptKeyLeavesTheRunningActionInPlace(): void
    {
        // The worker loop restores agent and connection around a wait; the action it is in
        // the middle of answering is not its to forget.
        ExecutionContext::withOrigin('ak-1', 'req-1', static function (): void {
            ExecutionContext::setCurrentAcceptKey('ak-2');

            self::assertSame('ak-2', ExecutionContext::currentAcceptKey());
            self::assertSame('req-1', ExecutionContext::currentRequestId());
        });
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
