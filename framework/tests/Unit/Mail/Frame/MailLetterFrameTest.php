<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Mail\Frame;

use Hilos\Mail\Exception\MailLetterFrameInvalidException;
use Hilos\Mail\Frame\MailLetterFrame;
use Hilos\Mail\Frame\MailLetterFrameRule;
use PHPUnit\Framework\TestCase;
use stdClass;

final class MailLetterFrameTest extends TestCase
{
    public function testDefaultIsACompleteValidDocument(): void
    {
        self::assertNull(MailLetterFrame::validate(MailLetterFrame::DEFAULT_HTML));
        $html = MailLetterFrame::render(MailLetterFrame::DEFAULT_HTML, '<p>Hello</p>', 'en');
        self::assertStringStartsWith('<!doctype html>', $html);
        self::assertStringContainsString('<html lang="en" dir="ltr">', $html);
        self::assertStringContainsString('role="presentation"', $html);
        self::assertStringContainsString('<p>Hello</p>', $html);
        self::assertStringEndsWith('</html>', $html);
    }

    public function testContentIsRequiredExactlyOnceAndUnknownSlotsAreRefused(): void
    {
        foreach (['', '<div>No slot</div>', '[[:content]][[:content]]'] as $frame) {
            self::assertSame(MailLetterFrame::CONTENT_REFUSAL, MailLetterFrame::validate($frame));
        }
        foreach (['[[:unknown]]', '[[:CONTENT]]', '[[: code ]]'] as $unknown) {
            self::assertSame(MailLetterFrame::PLACEHOLDER_REFUSAL, MailLetterFrame::validate('[[:content]]' . $unknown));
        }
        self::assertNull(MailLetterFrame::validate('[[:content]]'));
        self::assertNull(MailLetterFrame::validate('[[:lang]][[:lang]][[:dir]][[:dir]][[:content]]'));
    }

    public function testSinglePassPreservesLiteralSlotsAndMarkupInsideContent(): void
    {
        self::assertSame(
            '<div lang="en"><b>[[:lang]][[:dir]][[:content]] &amp;</b></div>',
            MailLetterFrame::render('<div lang="[[:lang]]">[[:content]]</div>', '<b>[[:lang]][[:dir]][[:content]] &amp;</b>', 'en'),
        );
        self::assertSame('<div></div>', MailLetterFrame::render('<div>[[:content]]</div>', '', 'en'));
    }

    public function testLanguageIsEscapedAndDirectionUsesTheBuiltInBaseLanguage(): void
    {
        foreach (['ar' => 'rtl', 'AR-sa' => 'rtl', 'he_IL' => 'rtl', 'en' => 'ltr', 'unknown' => 'ltr'] as $language => $direction) {
            self::assertSame(
                $language . '|' . $direction . '|' . $direction . '|body',
                MailLetterFrame::render('[[:lang]]|[[:dir]]|[[:dir]]|[[:content]]', 'body', $language),
            );
        }
        self::assertSame(
            '<div lang="en&quot; onload=&quot;&lt;&amp;&#039;" dir="ltr">body</div>',
            MailLetterFrame::render('<div lang="[[:lang]]" dir="[[:dir]]">[[:content]]</div>', 'body', 'en" onload="<&\''),
        );
    }

    public function testRuleRefusesNonTextWithoutCoercion(): void
    {
        foreach ([null, false, true, 1, 1.5, ['[[:content]]'], new stdClass()] as $value) {
            self::assertSame(MailLetterFrame::TYPE_REFUSAL, MailLetterFrameRule::validate($value));
        }
        self::assertSame(MailLetterFrame::CONTENT_REFUSAL, MailLetterFrameRule::validate('no slot'));
        self::assertNull(MailLetterFrameRule::validate('[[:content]]'));
    }

    public function testInvalidRenderRaisesOnlyASafeDomainSentence(): void
    {
        $this->expectException(MailLetterFrameInvalidException::class);
        $this->expectExceptionMessage('Letter frame is invalid.');
        MailLetterFrame::render('<private>secret code</private>', 'private content', 'en');
    }
}
