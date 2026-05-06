<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Agents\DTO\ModerationDecision;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for moderation model decision parsing.
 */
final class ModerationDecisionTest extends TestCase
{
    public function testFromModelOutputExtractsJsonObjectAndFields(): void
    {
        $decision = ModerationDecision::fromModelOutput(
            'Model says: {"allow": false, "reason": "spam"}',
        );

        $this->assertNotNull($decision);
        $this->assertFalse($decision->allow);
        $this->assertSame('spam', $decision->reason);
    }

    public function testFromModelOutputAllowsMissingReason(): void
    {
        $decision = ModerationDecision::fromModelOutput('{"allow": true}');

        $this->assertNotNull($decision);
        $this->assertTrue($decision->allow);
        $this->assertSame('', $decision->reason);
    }

    public function testFromModelOutputRejectsInvalidDecisionShape(): void
    {
        $this->assertNull(ModerationDecision::fromModelOutput('{"allow": "yes", "reason": "spam"}'));
        $this->assertNull(ModerationDecision::fromModelOutput('{"reason": "spam"}'));
        $this->assertNull(ModerationDecision::fromModelOutput('not json'));
    }
}
