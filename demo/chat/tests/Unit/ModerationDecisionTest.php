<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Agents\DTO\ModerationDecision;
use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
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
     */
    #[DataProvider('defaultedReasonProvider')]
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
     */
    #[DataProvider('invalidDecisionOutputProvider')]
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

    /**
     * @param string $rawReason Input raw reason
     * @param ?string $phase Runtime phase
     * @param string $expected Expected public sentence
     */
    #[DataProvider('publicNameReasonProvider')]
    public function testPublicNameReason(string $rawReason, ?string $phase, string $expected): void
    {
        $this->assertSame($expected, ModerationDecision::publicNameReason($rawReason, $phase));
    }

    /**
     * @return list<array{0: string, 1: ?string, 2: string}>
     */
    public static function publicNameReasonProvider(): array
    {
        return [
            ['insult', null, 'This name was not accepted because it appears to contain an insult.'],
            ['threat', null, 'This name was not accepted because it appears to contain a threat.'],
            ['hate_speech', null, 'This name was not accepted because it appears to contain hate speech.'],
            ['sexual', null, 'This name was not accepted because it appears to contain sexual content.'],
            ['spam', null, 'This name was not accepted because it appears to be spam.'],
            ['impersonation', null, 'This name was not accepted because it appears to impersonate someone else.'],
            ['blocked', null, 'This name was not accepted.'],
            ['', null, 'This name was not accepted.'],
            ['   ', null, 'This name was not accepted.'],
            ['policy', null, 'This name was not accepted.'],
            ['unknown_label', null, 'This name was not accepted.'],
            ['service_unavailable', null, 'Names cannot be checked right now.'],
            ['unknown', null, 'Names cannot be checked right now.'],
            ['', ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_UNAVAILABLE, 'Names cannot be checked right now.'],
            ['contains copyrighted brand', null, 'This name was not accepted: contains copyrighted brand'],
            ['contains bad_words', null, 'This name was not accepted.'],
            ["multiline\nreason text", null, 'This name was not accepted.'],
        ];
    }

    /**
     * @param string $rawReason Input raw reason
     * @param ?string $phase Runtime phase
     * @param string $expected Expected public sentence
     */
    #[DataProvider('publicMessageReasonProvider')]
    public function testPublicMessageReason(string $rawReason, ?string $phase, string $expected): void
    {
        $this->assertSame($expected, ModerationDecision::publicMessageReason($rawReason, $phase));
    }

    /**
     * @return list<array{0: string, 1: ?string, 2: string}>
     */
    public static function publicMessageReasonProvider(): array
    {
        return [
            ['insult', null, 'Message rejected: it appears to contain an insult.'],
            ['threat', null, 'Message rejected: it appears to contain a threat.'],
            ['hate_speech', null, 'Message rejected: it appears to contain hate speech.'],
            ['sexual', null, 'Message rejected: it appears to contain sexual content.'],
            ['spam', null, 'Message rejected: it appears to be spam.'],
            ['impersonation', null, 'Message rejected.'],
            ['blocked', null, 'Message rejected.'],
            ['', null, 'Message rejected.'],
            ['policy', null, 'Message rejected.'],
            ['some_code', null, 'Message rejected.'],
            ['service_unavailable', null, 'Moderation is unavailable right now. Your message is still here.'],
            ['unknown', null, 'Moderation is unavailable right now. Your message is still here.'],
            [
                '',
                ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
                'Moderation is unavailable right now. Your message is still here.',
            ],
            ['contains profanity', null, 'Message rejected: contains profanity'],
            ['contains bad_word', null, 'Message rejected.'],
        ];
    }

    public function testIsNaturalLanguagePhraseRejectsInvalidPatterns(): void
    {
        $this->assertTrue(ModerationDecision::isNaturalLanguagePhrase('this is a natural phrase'));
        $this->assertFalse(ModerationDecision::isNaturalLanguagePhrase(''));
        $this->assertFalse(ModerationDecision::isNaturalLanguagePhrase('singleword'));
        $this->assertFalse(ModerationDecision::isNaturalLanguagePhrase('has_underscore label'));
        $this->assertFalse(ModerationDecision::isNaturalLanguagePhrase("has\nnewline phrase"));
        $this->assertFalse(ModerationDecision::isNaturalLanguagePhrase("has\x07control phrase"));
        $this->assertFalse(ModerationDecision::isNaturalLanguagePhrase(str_repeat('word ', 35)));
    }
}
