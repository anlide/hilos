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
            ['{"allow": true}'],
            ['{"allow": true, "reason": ""}'],
            ['{"allow": true, "reason": "   "}'],
            ['{"reason": "spam"}'],
            ['not json'],
        ];
    }
}
