<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Agents\DTO\ModerationDecision;
use Hilos\Core\Exception\InvalidArgumentException;
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

        $this->assertFalse($decision->allow);
        $this->assertSame('spam', $decision->reason);
    }

    /**
     * The reason is a label the model may omit for clear-cut cases; the allow
     * decision stands and the reason defaults rather than failing moderation.
     *
     * @param string $text Raw model output
     * @param bool $allow Expected allow decision
     * @param string $reason Expected defaulted reason
     *
     * @dataProvider defaultedReasonProvider
     */
    public function testFromModelOutputDefaultsMissingOrEmptyReason(string $text, bool $allow, string $reason): void
    {
        $decision = ModerationDecision::fromModelOutput($text);

        $this->assertSame($allow, $decision->allow);
        $this->assertSame($reason, $decision->reason);
    }

    /**
     * @return list<array{0: string, 1: bool, 2: string}>
     */
    public static function defaultedReasonProvider(): array
    {
        return [
            ['{"allow": true}', true, ModerationDecision::REASON_ALLOWED],
            ['{"allow": true, "reason": ""}', true, ModerationDecision::REASON_ALLOWED],
            ['{"allow": true, "reason": "   "}', true, ModerationDecision::REASON_ALLOWED],
            ['{"allow": false}', false, ModerationDecision::REASON_BLOCKED],
        ];
    }

    /**
     * @param string $text Raw invalid model output
     *
     * @dataProvider invalidDecisionOutputProvider
     */
    public function testFromModelOutputRejectsInvalidDecisionShape(string $text): void
    {
        $this->expectException(InvalidArgumentException::class);

        ModerationDecision::fromModelOutput($text);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function invalidDecisionOutputProvider(): array
    {
        return [
            ['{"allow": "yes", "reason": "spam"}'],
            ['{"reason": "spam"}'],
            ['not json'],
        ];
    }
}
