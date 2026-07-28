<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Mail;

use Hilos\Mail\EmailMessage;
use Hilos\Mail\MailMessageEncoder;
use PHPUnit\Framework\TestCase;

/**
 * Tests the RFC 5322 / MIME wire encoding shared by every mail transport (HIL-197).
 */
final class MailMessageEncoderTest extends TestCase
{
    private MailMessageEncoder $encoder;

    protected function setUp(): void
    {
        $this->encoder = new MailMessageEncoder();
    }

    public function testTextOnlyMessageIsBase64TextPlain(): void
    {
        $encoded = $this->encoder->encode(
            new EmailMessage(to: 'user@example.com', subject: 'Hi there', text: 'Hello body'),
            'from@example.com',
        );

        $this->assertStringContainsString("From: from@example.com\r\n", $encoded);
        $this->assertStringContainsString("To: user@example.com\r\n", $encoded);
        $this->assertStringContainsString("Subject: Hi there\r\n", $encoded);
        $this->assertStringContainsString("MIME-Version: 1.0\r\n", $encoded);
        $this->assertStringContainsString("Content-Type: text/plain; charset=UTF-8\r\n", $encoded);
        $this->assertStringContainsString("Content-Transfer-Encoding: base64\r\n", $encoded);
        $this->assertStringContainsString(base64_encode('Hello body'), $encoded);
        $this->assertStringNotContainsString('multipart', $encoded);
    }

    public function testHtmlMessageIsMultipartAlternativeWithTextFallback(): void
    {
        $encoded = $this->encoder->encode(
            new EmailMessage(
                to: 'user@example.com',
                subject: 'Rich',
                text: 'plain fallback',
                html: '<p>rich body</p>',
            ),
            'from@example.com',
        );

        $this->assertMatchesRegularExpression(
            '/Content-Type: multipart\/alternative; boundary="([^"]+)"/',
            $encoded,
        );
        preg_match('/boundary="([^"]+)"/', $encoded, $m);
        $boundary = $m[1];
        $this->assertStringContainsString('--' . $boundary . "\r\n", $encoded);
        $this->assertStringContainsString('--' . $boundary . "--", $encoded);
        $this->assertStringContainsString("Content-Type: text/plain; charset=UTF-8\r\n", $encoded);
        $this->assertStringContainsString("Content-Type: text/html; charset=UTF-8\r\n", $encoded);
        $this->assertStringContainsString(base64_encode('plain fallback'), $encoded);
        $this->assertStringContainsString(base64_encode('<p>rich body</p>'), $encoded);
        // The boundary token must not collide with the encoded bodies.
        $this->assertStringNotContainsString($boundary, base64_encode('plain fallback'));
    }

    public function testNonAsciiSubjectBecomesEncodedWord(): void
    {
        $encoded = $this->encoder->encode(
            new EmailMessage(to: 'user@example.com', subject: 'Привет', text: 'body'),
            'from@example.com',
        );

        $this->assertStringContainsString('Subject: =?UTF-8?B?' . base64_encode('Привет') . '?=', $encoded);
    }

    public function testDisplayNamesAndReplyToAreEmitted(): void
    {
        $encoded = $this->encoder->encode(
            new EmailMessage(
                to: 'user@example.com',
                subject: 'Hi',
                text: 'body',
                toName: 'Recipient',
                replyTo: 'reply@example.com',
            ),
            'from@example.com',
            'Пётр',
        );

        $this->assertStringContainsString(
            'From: =?UTF-8?B?' . base64_encode('Пётр') . '?= <from@example.com>',
            $encoded,
        );
        $this->assertStringContainsString('To: Recipient <user@example.com>', $encoded);
        $this->assertStringContainsString("Reply-To: reply@example.com\r\n", $encoded);
    }

    public function testCrlfInRecipientAndReplyToIsStrippedToPreventHeaderInjection(): void
    {
        $encoded = $this->encoder->encode(
            new EmailMessage(
                to: "user@example.com\r\nBcc: victim@example.com",
                subject: 'Hi',
                text: 'body',
                replyTo: "reply@example.com\r\nCc: victim@example.com",
            ),
            "from@example.com\r\nX-Evil: 1",
        );

        // No injected header line: the smuggled keys never begin a fresh CRLF-delimited line.
        $this->assertStringNotContainsString("\r\nBcc:", $encoded);
        $this->assertStringNotContainsString("\r\nCc: victim", $encoded);
        $this->assertStringNotContainsString("\r\nX-Evil:", $encoded);
        // The header line survives with the newlines folded away, so no extra header appears.
        $this->assertStringContainsString('To: user@example.comBcc: victim@example.com', $encoded);
        $this->assertStringContainsString('From: from@example.comX-Evil: 1', $encoded);
        $this->assertStringContainsString('Reply-To: reply@example.comCc: victim@example.com', $encoded);
    }

    public function testEncodingIsDeterministic(): void
    {
        $message = new EmailMessage(to: 'user@example.com', subject: 'Hi', text: 'body', html: '<i>x</i>');

        $this->assertSame(
            $this->encoder->encode($message, 'from@example.com'),
            $this->encoder->encode($message, 'from@example.com'),
        );
    }
}
