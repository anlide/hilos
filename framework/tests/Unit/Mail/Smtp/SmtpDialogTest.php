<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Mail\Smtp;

use Hilos\Mail\EmailMessage;
use Hilos\Mail\MailTransportConfig;
use Hilos\Mail\Smtp\SmtpAction;
use Hilos\Mail\Smtp\SmtpActionKind;
use Hilos\Mail\Smtp\SmtpDialog;
use Hilos\Mail\Smtp\SmtpReply;
use Hilos\Mail\SmtpSecurity;
use PHPUnit\Framework\TestCase;

/**
 * Drives the pure SMTP conversation with scripted replies: command sequence, STARTTLS,
 * AUTH PLAIN/LOGIN, DATA dot-stuffing, and error classification (HIL-197).
 */
final class SmtpDialogTest extends TestCase
{
    public function testPlainHappyPathRunsEnvelopeAndFinishesDelivered(): void
    {
        $dialog = $this->dialog(SmtpSecurity::NONE);

        $this->assertSend('EHLO example.com', $dialog->onReply(new SmtpReply(220, 'ready')));
        $this->assertSend('MAIL FROM:<from@example.com>', $dialog->onReply(new SmtpReply(250, 'ok')));
        $this->assertSend('RCPT TO:<to@example.com>', $dialog->onReply(new SmtpReply(250, 'ok')));
        $this->assertSend('DATA', $dialog->onReply(new SmtpReply(250, 'ok')));

        $body = $dialog->onReply(new SmtpReply(354, 'go ahead'));
        $this->assertSame(SmtpActionKind::SEND, $body->kind);
        $this->assertStringContainsString('BODY', (string)$body->bytes);
        $this->assertStringEndsWith("\r\n.\r\n", (string)$body->bytes);

        $this->assertSend('QUIT', $dialog->onReply(new SmtpReply(250, 'queued')));

        $done = $dialog->onReply(new SmtpReply(221, 'bye'));
        $this->assertSame(SmtpActionKind::FINISH, $done->kind);
        $this->assertTrue($done->outcome?->delivered);
    }

    public function testStartTlsThenAuthLogin(): void
    {
        $dialog = $this->dialog(SmtpSecurity::STARTTLS, 'user', 'secret');

        $this->assertSend('EHLO example.com', $dialog->onReply(new SmtpReply(220, 'ready')));
        $this->assertSend('STARTTLS', $dialog->onReply(new SmtpReply(250, 'AUTH LOGIN')));

        $tls = $dialog->onReply(new SmtpReply(220, 'go ahead'));
        $this->assertSame(SmtpActionKind::START_TLS, $tls->kind);

        $this->assertSend('EHLO example.com', $dialog->onSecured());
        $this->assertSend('AUTH LOGIN', $dialog->onReply(new SmtpReply(250, 'AUTH LOGIN')));
        $this->assertSend(base64_encode('user'), $dialog->onReply(new SmtpReply(334, 'Username')));
        $this->assertSend(base64_encode('secret'), $dialog->onReply(new SmtpReply(334, 'Password')));
        $this->assertSend('MAIL FROM:<from@example.com>', $dialog->onReply(new SmtpReply(235, 'authenticated')));
    }

    public function testAuthPlainWhenAdvertised(): void
    {
        $dialog = $this->dialog(SmtpSecurity::NONE, 'user', 'secret');

        $this->assertSend('EHLO example.com', $dialog->onReply(new SmtpReply(220, 'ready')));
        $this->assertSend(
            'AUTH PLAIN ' . base64_encode("\0user\0secret"),
            $dialog->onReply(new SmtpReply(250, 'AUTH PLAIN LOGIN')),
        );
        $this->assertSend('MAIL FROM:<from@example.com>', $dialog->onReply(new SmtpReply(235, 'authenticated')));
    }

    public function testUnsupportedAuthMechanismFailsPermanently(): void
    {
        $dialog = $this->dialog(SmtpSecurity::NONE, 'user', 'secret');
        $dialog->onReply(new SmtpReply(220, 'ready'));

        $action = $dialog->onReply(new SmtpReply(250, 'AUTH CRAM-MD5'));
        $this->assertSame(SmtpActionKind::FINISH, $action->kind);
        $this->assertFalse($action->outcome?->delivered);
        $this->assertTrue($action->outcome?->permanent);
        $this->assertSame('SMTP server offers no supported AUTH mechanism', $action->outcome?->errorDetail);
    }

    public function testFivexxRecipientRejectionIsPermanent(): void
    {
        $dialog = $this->dialog(SmtpSecurity::NONE);
        $dialog->onReply(new SmtpReply(220, 'ready'));
        $dialog->onReply(new SmtpReply(250, 'ok'));
        $dialog->onReply(new SmtpReply(250, 'sender ok'));

        $action = $dialog->onReply(new SmtpReply(550, 'no such user'));
        $this->assertSame(SmtpActionKind::FINISH, $action->kind);
        $this->assertFalse($action->outcome?->delivered);
        $this->assertTrue($action->outcome?->permanent);
        $this->assertSame('SMTP server rejected the recipient', $action->outcome?->errorDetail);
    }

    public function testFourxxGreetingRejectionIsTransient(): void
    {
        $dialog = $this->dialog(SmtpSecurity::NONE);

        $action = $dialog->onReply(new SmtpReply(421, 'service not available'));
        $this->assertSame(SmtpActionKind::FINISH, $action->kind);
        $this->assertFalse($action->outcome?->delivered);
        $this->assertFalse($action->outcome?->permanent);
        $this->assertSame('SMTP server did not accept the connection', $action->outcome?->errorDetail);
    }

    public function testDataPayloadDotStuffsLeadingDotLines(): void
    {
        $dialog = new SmtpDialog(
            new EmailMessage(to: 'to@example.com', subject: 'S', text: 'B'),
            $this->config(SmtpSecurity::NONE),
            "line1\r\n.hidden\r\nlast",
        );
        $dialog->onReply(new SmtpReply(220, 'ready'));
        $dialog->onReply(new SmtpReply(250, 'ok'));
        $dialog->onReply(new SmtpReply(250, 'ok'));
        $dialog->onReply(new SmtpReply(250, 'ok'));

        $body = $dialog->onReply(new SmtpReply(354, 'go ahead'));
        $this->assertStringContainsString("\r\n..hidden\r\n", (string)$body->bytes);
        $this->assertStringEndsWith("last\r\n.\r\n", (string)$body->bytes);
    }

    public function testDisconnectBeforeDeliveryIsTransientFailure(): void
    {
        $dialog = $this->dialog(SmtpSecurity::NONE);

        $action = $dialog->onDisconnect();
        $this->assertSame(SmtpActionKind::FINISH, $action->kind);
        $this->assertFalse($action->outcome?->delivered);
        $this->assertFalse($action->outcome?->permanent);
    }

    /**
     * Builds a dialog with a plain header/body payload for the given security and credentials.
     *
     * @param SmtpSecurity $security Transport-security mode
     * @param ?string $username SMTP AUTH username, or null
     * @param ?string $password SMTP AUTH password, or null
     * @return SmtpDialog Dialog awaiting the greeting
     */
    private function dialog(SmtpSecurity $security, ?string $username = null, ?string $password = null): SmtpDialog
    {
        return new SmtpDialog(
            new EmailMessage(to: 'to@example.com', subject: 'S', text: 'B'),
            $this->config($security, $username, $password),
            "HEADER\r\n\r\nBODY",
        );
    }

    /**
     * Builds a transport config with a fixed sender for the given security and credentials.
     *
     * @param SmtpSecurity $security Transport-security mode
     * @param ?string $username SMTP AUTH username, or null
     * @param ?string $password SMTP AUTH password, or null
     * @return MailTransportConfig Config with from@example.com as the sender
     */
    private function config(SmtpSecurity $security, ?string $username = null, ?string $password = null): MailTransportConfig
    {
        return new MailTransportConfig(
            fromAddress: 'from@example.com',
            fileDir: '/tmp',
            transport: 'smtp',
            smtpHost: 'mail.example.com',
            security: $security,
            username: $username,
            password: $password,
        );
    }

    /**
     * Asserts an action sends a command line whose payload starts with the expected text.
     *
     * @param string $expected Command line without its CRLF terminator
     * @param SmtpAction $action The action under test
     */
    private function assertSend(string $expected, SmtpAction $action): void
    {
        $this->assertSame(SmtpActionKind::SEND, $action->kind);
        $this->assertSame($expected . "\r\n", $action->bytes);
    }
}
