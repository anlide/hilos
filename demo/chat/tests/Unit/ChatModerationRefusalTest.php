<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Moderation\ChatModerationRefusal;
use Demo\Chat\Constants\ConnectionRuntimeConstants;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the chat public refusal sentences.
 */
final class ChatModerationRefusalTest extends TestCase
{
    /**
     * @param string $rawReason Input raw reason
     * @param ?string $phase Runtime phase
     * @param string $expected Expected public sentence
     */
    #[DataProvider('publicMessageReasonProvider')]
    public function testPublicMessageReason(string $rawReason, ?string $phase, string $expected): void
    {
        $this->assertSame($expected, ChatModerationRefusal::publicMessageReason($rawReason, $phase));
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

    /**
     * @param string $rawReason Input raw reason
     * @param ?string $phase Runtime phase
     * @param int $ordinal Attachment number among every file
     * @param ?string $filename Upload file name, or null when the upload is gone
     * @param string $expected Expected public sentence
     */
    #[DataProvider('publicPictureReasonProvider')]
    public function testPublicPictureReason(
        string $rawReason,
        ?string $phase,
        int $ordinal,
        ?string $filename,
        string $expected,
    ): void {
        $this->assertSame(
            $expected,
            ChatModerationRefusal::publicPictureReason($rawReason, $phase, $ordinal, $filename),
        );
    }

    /**
     * @return list<array{0: string, 1: ?string, 2: int, 3: ?string, 4: string}>
     */
    public static function publicPictureReasonProvider(): array
    {
        $longName = str_repeat('n', 61);
        $shownName = str_repeat('n', 59) . '…';

        return [
            ['nudity', null, 2, 'shot.png', 'Message rejected: attachment 2 (shot.png) looks like nudity or sexual content.'],
            ['violence', null, 2, 'shot.png', 'Message rejected: attachment 2 (shot.png) looks violent.'],
            ['hate', null, 1, 'banner.gif', 'Message rejected: attachment 1 (banner.gif) looks like hate imagery.'],
            [
                'unreadable',
                null,
                3,
                'scan.png',
                'Message rejected: attachment 3 (scan.png) cannot be checked. Send pictures as JPEG, PNG, WebP or GIF.',
            ],
            [
                'contains a watermark',
                null,
                1,
                'shot.png',
                'Message rejected: attachment 1 (shot.png) — contains a watermark',
            ],
            ['blocked', null, 1, 'shot.png', 'Message rejected: attachment 1 (shot.png) was not accepted.'],
            ['', null, 1, 'shot.png', 'Message rejected: attachment 1 (shot.png) was not accepted.'],
            [
                'service_unavailable',
                null,
                1,
                'shot.png',
                'Moderation is unavailable right now. Your message is still here.',
            ],
            ['unknown', null, 1, 'shot.png', 'Moderation is unavailable right now. Your message is still here.'],
            [
                'nudity',
                null,
                1,
                $longName,
                'Message rejected: attachment 1 (' . $shownName . ') looks like nudity or sexual content.',
            ],
            ['nudity', null, 4, null, 'Message rejected: attachment 4 looks like nudity or sexual content.'],
        ];
    }

    public function testIsAboutTheContentRejectsACheckThatCouldNotRun(): void
    {
        $this->assertTrue(ChatModerationRefusal::isAboutTheContent('nudity'));
        $this->assertTrue(ChatModerationRefusal::isAboutTheContent('blocked'));
        $this->assertTrue(ChatModerationRefusal::isAboutTheContent('contains a watermark'));
        $this->assertFalse(ChatModerationRefusal::isAboutTheContent(ChatModerationRefusal::REASON_UNREADABLE));
        $this->assertFalse(ChatModerationRefusal::isAboutTheContent('service_unavailable'));
        $this->assertFalse(ChatModerationRefusal::isAboutTheContent('unknown'));
    }

    public function testPublicReasonRejectsInvalidNaturalLanguagePatterns(): void
    {
        $this->assertSame('Message rejected: this is a natural phrase', ChatModerationRefusal::publicMessageReason('this is a natural phrase'));
        foreach (['', 'singleword', 'has_underscore label', "has\nnewline phrase", "has\x07control phrase", str_repeat('word ', 35)] as $reason) {
            $this->assertSame('Message rejected.', ChatModerationRefusal::publicMessageReason($reason));
        }
    }
}
