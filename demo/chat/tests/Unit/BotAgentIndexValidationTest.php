<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Agents\BotAgent;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;
use Hilos\Core\Agent\Exception\InvalidAgentIndexException;
use PHPUnit\Framework\TestCase;

final class BotAgentIndexValidationTest extends TestCase
{
    public function testConstructorRejectsEmptyAgentIndex(): void
    {
        $this->expectException(AgentIndexRequiredException::class);

        new BotAgent('');
    }

    public function testConstructorRejectsNonPositiveAgentIndex(): void
    {
        $this->expectException(InvalidAgentIndexException::class);

        new BotAgent('-1');
    }

    public function testConstructorRejectsZeroAgentIndex(): void
    {
        $this->expectException(InvalidAgentIndexException::class);

        new BotAgent('0');
    }

    public function testConstructorRejectsNonNumericAgentIndex(): void
    {
        $this->expectException(InvalidAgentIndexException::class);

        new BotAgent('bot-1');
    }

    public function testConstructorRejectsOverflowAgentIndex(): void
    {
        $this->expectException(InvalidAgentIndexException::class);

        new BotAgent('999999999999999999999999999999');
    }
}
