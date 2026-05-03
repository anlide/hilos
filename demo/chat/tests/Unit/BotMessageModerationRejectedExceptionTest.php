<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Agents\Exception\BotMessageModerationRejectedException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see BotMessageModerationRejectedException}.
 */
final class BotMessageModerationRejectedExceptionTest extends TestCase
{
    public function testMessageUsesProvidedReason(): void
    {
        $exception = new BotMessageModerationRejectedException(42, 'policy_violation');

        $this->assertSame(
            'Bot message blocked by moderation (botId=42; reason=policy_violation)',
            $exception->getMessage(),
        );
    }

    public function testEmptyReasonDefaultsToUnknown(): void
    {
        $exception = new BotMessageModerationRejectedException(42, '');

        $this->assertSame(
            'Bot message blocked by moderation (botId=42; reason=unknown)',
            $exception->getMessage(),
        );
    }
}
