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

    public function testStartTlsDiscardsBytesBufferedBeforeTheHandshake(): void
    {
        $transport = new StartTlsScriptedSmtpMailTransport($this->startTlsConfig());
        $transport->start(new EmailMessage(to: 'user@test.local', subject: 'Hi', text: 'Hello'), 0.0);

        // The pre-TLS conversation, with a forged EHLO reply spliced into the cleartext right
        // after "220 ready" — exactly what a MITM injects before the upgrade (CVE-2011-0411).
        fwrite(
            $transport->peer,
            "220 mail.test ESMTP\r\n"
            . "250-mail.test\r\n250 STARTTLS\r\n"
            . "220 ready to start tls\r\n"
            . "250 evil-injected.host\r\n",
        );

        $now = 0.0;
        for ($i = 0; $i < 40; $i++) {
            $now += 1.0;
            $transport->tick($now);
        }

        $sentBeforeReply = (string)stream_get_contents($transport->peer);
        // The client re-issued EHLO after the upgrade and is now waiting for the genuine
        // post-TLS reply: the injected 250 was discarded, so the envelope has not advanced.
        $this->assertFalse($transport->hasResult());
        $this->assertTrue($transport->isBusy());
        $this->assertSame(2, substr_count($sentBeforeReply, 'EHLO test.local'));
        $this->assertStringNotContainsString('MAIL FROM', $sentBeforeReply);

        // The real encrypted replies arrive and the send completes normally.
        fwrite(
            $transport->peer,
            "250 mail.test\r\n"
            . "250 sender ok\r\n"
            . "250 recipient ok\r\n"
            . "354 end data\r\n"
            . "250 queued\r\n"
            . "221 bye\r\n",
        );
        for ($i = 0; $i < 200 && !$transport->hasResult(); $i++) {
            $now += 1.0;
            $transport->tick($now);
        }

        $this->assertTrue($transport->hasResult());
        $this->assertTrue($transport->consumeResult()->delivered);
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

    /**
     * Builds a STARTTLS SMTP config with a generous timeout for the interactive handshake test.
     *
     * @return MailTransportConfig STARTTLS config targeting a stubbed host, no AUTH
     */
    private function startTlsConfig(): MailTransportConfig
    {
        return new MailTransportConfig(
            fromAddress: 'from@test.local',
            fileDir: sys_get_temp_dir(),
            transport: 'smtp',
            smtpHost: 'mail.test',
            smtpPort: 587,
            security: SmtpSecurity::STARTTLS,
            timeoutMs: 100000,
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

/**
 * SMTP transport over an in-memory pair whose TLS handshake is faked as instantly secured,
 * so a test can drive the STARTTLS upgrade path without a real TLS peer.
 */
final class StartTlsScriptedSmtpMailTransport extends SmtpMailTransport
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

    /**
     * @return int|bool Always true: the handshake is treated as instantly secured
     */
    protected function enableCrypto(): int|bool
    {
        return true;
    }
}
