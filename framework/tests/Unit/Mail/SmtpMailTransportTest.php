<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Mail;

use Hilos\Mail\EmailMessage;
use Hilos\Mail\MailTransportConfig;
use Hilos\Mail\SmtpMailTransport;
use Hilos\Mail\SmtpSecurity;
use PHPUnit\Framework\TestCase;

/**
 * Drives the non-blocking SMTP transport over an in-memory socket pair: the full send
 * pump against scripted replies, and the timeout path when the server stays silent (HIL-197).
 */
final class SmtpMailTransportTest extends TestCase
{
    public function testPumpsAFullSendAgainstScriptedReplies(): void
    {
        $transport = new ScriptedSmtpMailTransport($this->config(5000));
        $transport->start(new EmailMessage(to: 'user@test.local', subject: 'Hi', text: 'Hello'), 0.0);

        fwrite(
            $transport->peer,
            "220 mail.test ESMTP\r\n"
            . "250-mail.test\r\n250 PIPELINING\r\n"
            . "250 sender ok\r\n"
            . "250 recipient ok\r\n"
            . "354 end data with <CRLF>.<CRLF>\r\n"
            . "250 queued\r\n"
            . "221 bye\r\n",
        );

        $now = 0.0;
        for ($i = 0; $i < 200 && !$transport->hasResult(); $i++) {
            $now += 1.0;
            $transport->tick($now);
        }

        $this->assertTrue($transport->hasResult());
        $outcome = $transport->consumeResult();
        $this->assertTrue($outcome->delivered);

        $sent = (string)stream_get_contents($transport->peer);
        $this->assertStringContainsString('EHLO test.local', $sent);
        $this->assertStringContainsString('MAIL FROM:<from@test.local>', $sent);
        $this->assertStringContainsString('RCPT TO:<user@test.local>', $sent);
        $this->assertStringContainsString(base64_encode('Hello'), $sent);
        $this->assertStringContainsString('QUIT', $sent);
    }

    public function testSilentServerSettlesTransientTimeout(): void
    {
        $transport = new ScriptedSmtpMailTransport($this->config(50));
        $transport->start(new EmailMessage(to: 'user@test.local', subject: 'Hi', text: 'Hello'), 0.0);

        $transport->tick(10.0);
        $this->assertFalse($transport->hasResult());

        $transport->tick(60.0);
        $this->assertTrue($transport->hasResult());

        $outcome = $transport->consumeResult();
        $this->assertFalse($outcome->delivered);
        $this->assertFalse($outcome->permanent);
        $this->assertSame('SMTP send timed out', $outcome->errorDetail);
    }

    /**
     * Builds a plain-security SMTP config with the given timeout.
     *
     * @param int $timeoutMs Per-send timeout in milliseconds
     * @return MailTransportConfig Config targeting a stubbed host
     */
    private function config(int $timeoutMs): MailTransportConfig
    {
        return new MailTransportConfig(
            fromAddress: 'from@test.local',
            fileDir: sys_get_temp_dir(),
            transport: 'smtp',
            smtpHost: 'mail.test',
            smtpPort: 25,
            security: SmtpSecurity::NONE,
            timeoutMs: $timeoutMs,
        );
    }
}

/**
 * SMTP transport whose socket is an in-memory pair, so a test can script the server side.
 */
final class ScriptedSmtpMailTransport extends SmtpMailTransport
{
    /** @var resource The server end of the socket pair, written by the test. */
    public $peer;

    /**
     * Returns the client end of a socket pair and keeps the server end for the test.
     *
     * @return resource The client end of the in-memory socket pair
     */
    protected function establishSocket()
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        stream_set_blocking($pair[0], false);
        stream_set_blocking($pair[1], false);
        $this->peer = $pair[1];

        return $pair[0];
    }
}
