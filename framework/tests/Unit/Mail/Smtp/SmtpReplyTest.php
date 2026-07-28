<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Mail\Smtp;

use Hilos\Mail\Smtp\SmtpReply;
use PHPUnit\Framework\TestCase;

/**
 * Tests SMTP reply parsing, including multiline continuation and partial buffers (HIL-197).
 */
final class SmtpReplyTest extends TestCase
{
    public function testParsesSingleLineReplyAndConsumesIt(): void
    {
        $buffer = "250 OK\r\n";
        $reply = SmtpReply::parse($buffer);

        $this->assertNotNull($reply);
        $this->assertSame(250, $reply->code);
        $this->assertSame('OK', $reply->text);
        $this->assertSame('', $buffer);
    }

    public function testJoinsMultilineContinuation(): void
    {
        $buffer = "250-mail.test\r\n250-PIPELINING\r\n250 AUTH PLAIN LOGIN\r\n";
        $reply = SmtpReply::parse($buffer);

        $this->assertNotNull($reply);
        $this->assertSame(250, $reply->code);
        $this->assertSame("mail.test\nPIPELINING\nAUTH PLAIN LOGIN", $reply->text);
        $this->assertSame('', $buffer);
    }

    public function testReturnsNullWhileReplyIsIncomplete(): void
    {
        $buffer = "250-mail.test\r\n250 AUTH";
        $reply = SmtpReply::parse($buffer);

        $this->assertNull($reply);
        $this->assertSame("250-mail.test\r\n250 AUTH", $buffer);
    }

    public function testParsesRepliesOneAtATime(): void
    {
        $buffer = "220 greeting\r\n250 OK\r\n";

        $first = SmtpReply::parse($buffer);
        $this->assertNotNull($first);
        $this->assertSame(220, $first->code);
        $this->assertSame("250 OK\r\n", $buffer);

        $second = SmtpReply::parse($buffer);
        $this->assertNotNull($second);
        $this->assertSame(250, $second->code);
        $this->assertSame('', $buffer);
    }
}
