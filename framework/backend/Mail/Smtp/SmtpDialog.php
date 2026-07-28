<?php

declare(strict_types=1);

namespace Hilos\Mail\Smtp;

use Hilos\Mail\EmailMessage;
use Hilos\Mail\MailSendOutcome;
use Hilos\Mail\MailTransportConfig;
use Hilos\Mail\SmtpSecurity;

/**
 * SmtpDialog - the pure SMTP conversation state machine (HIL-197).
 *
 * Holds no socket: it is fed one parsed {@see SmtpReply} at a time via {@see onReply()}
 * and returns the {@see SmtpAction} the transport must carry out (send bytes, upgrade to
 * TLS, or finish). This keeps the protocol — EHLO capability probing, the STARTTLS
 * upgrade, AUTH PLAIN/LOGIN, the envelope, DATA dot-stuffing, and error classification —
 * unit-testable by scripting server replies with no network.
 *
 * A server reply that does not advance the current step settles a failed
 * {@see MailSendOutcome}: a 5xx code is permanent (no retry), anything else transient.
 */
final class SmtpDialog
{
    /** Line terminator on the SMTP wire (RFC 5321). */
    private const string CRLF = "\r\n";

    /** @var SmtpState Reply the dialog is currently waiting for. */
    private SmtpState $state = SmtpState::GREETING;

    /** @var bool Whether the channel is already encrypted (implicit TLS or after STARTTLS). */
    private bool $secured;

    /** @var list<string> AUTH mechanisms advertised by the last EHLO, upper-cased. */
    private array $authMechanisms = [];

    /**
     * @param EmailMessage $message Recipient message whose envelope and body are sent
     * @param MailTransportConfig $config Endpoint, security, and credentials for the send
     * @param string $payload Encoded RFC 5322 message streamed as the DATA payload
     */
    public function __construct(
        private readonly EmailMessage $message,
        private readonly MailTransportConfig $config,
        private readonly string $payload,
    ) {
        $this->secured = $config->security === SmtpSecurity::TLS;
    }

    /**
     * Advances the conversation with one complete server reply.
     *
     * @param SmtpReply $reply The parsed server reply
     * @return SmtpAction Directive for the transport
     */
    public function onReply(SmtpReply $reply): SmtpAction
    {
        return match ($this->state) {
            SmtpState::GREETING => $this->expect($reply, 220, 'SMTP server did not accept the connection')
                ?? $this->sendEhlo(SmtpState::EHLO),
            SmtpState::EHLO, SmtpState::EHLO_TLS => $this->expect($reply, 250, 'SMTP server rejected the EHLO handshake')
                ?? $this->afterEhlo($reply),
            SmtpState::STARTTLS => $this->expect($reply, 220, 'SMTP server refused STARTTLS')
                ?? SmtpAction::startTls(),
            SmtpState::AUTH_PLAIN, SmtpState::AUTH_LOGIN_PASSWORD => $this->expect($reply, 235, 'SMTP authentication failed')
                ?? $this->sendMailFrom(),
            SmtpState::AUTH_LOGIN => $this->expect($reply, 334, 'SMTP authentication failed')
                ?? $this->send(SmtpState::AUTH_LOGIN_USERNAME, base64_encode((string)$this->config->username)),
            SmtpState::AUTH_LOGIN_USERNAME => $this->expect($reply, 334, 'SMTP authentication failed')
                ?? $this->send(SmtpState::AUTH_LOGIN_PASSWORD, base64_encode((string)$this->config->password)),
            SmtpState::MAIL_FROM => $this->expect($reply, 250, 'SMTP server rejected the sender')
                ?? $this->send(SmtpState::RCPT_TO, 'RCPT TO:<' . $this->message->to . '>'),
            SmtpState::RCPT_TO => $this->expectAny($reply, [250, 251], 'SMTP server rejected the recipient')
                ?? $this->send(SmtpState::DATA, 'DATA'),
            SmtpState::DATA => $this->expect($reply, 354, 'SMTP server refused the message data')
                ?? $this->send(SmtpState::DATA_BODY, $this->dataPayload(), terminated: true),
            SmtpState::DATA_BODY => $this->expect($reply, 250, 'SMTP server did not accept the message')
                ?? $this->sendQuit(),
            SmtpState::QUIT, SmtpState::DONE => SmtpAction::finish(MailSendOutcome::delivered()),
        };
    }

    /**
     * Resumes after the transport has completed the STARTTLS handshake.
     *
     * @return SmtpAction Directive to re-issue EHLO over the now-encrypted channel
     */
    public function onSecured(): SmtpAction
    {
        $this->secured = true;

        return $this->sendEhlo(SmtpState::EHLO_TLS);
    }

    /**
     * Settles the send when the socket closes before the conversation finished.
     *
     * @return SmtpAction FINISH: delivered if the body was already accepted, else a transient failure
     */
    public function onDisconnect(): SmtpAction
    {
        if ($this->state === SmtpState::QUIT) {
            return SmtpAction::finish(MailSendOutcome::delivered());
        }

        return SmtpAction::finish(MailSendOutcome::failed('SMTP connection closed unexpectedly', false));
    }

    /**
     * Chooses the step after a successful EHLO: STARTTLS upgrade, AUTH, or the envelope.
     *
     * @param SmtpReply $reply The EHLO reply whose text advertises capabilities
     * @return SmtpAction Directive for the chosen next step
     */
    private function afterEhlo(SmtpReply $reply): SmtpAction
    {
        $this->authMechanisms = $this->parseAuthMechanisms($reply->text);

        if ($this->config->security === SmtpSecurity::STARTTLS && !$this->secured) {
            return $this->send(SmtpState::STARTTLS, 'STARTTLS');
        }

        if ($this->config->username === null || $this->config->password === null) {
            return $this->sendMailFrom();
        }

        if (in_array('PLAIN', $this->authMechanisms, true)) {
            $credential = base64_encode("\0" . $this->config->username . "\0" . $this->config->password);
            return $this->send(SmtpState::AUTH_PLAIN, 'AUTH PLAIN ' . $credential);
        }

        if (in_array('LOGIN', $this->authMechanisms, true)) {
            return $this->send(SmtpState::AUTH_LOGIN, 'AUTH LOGIN');
        }

        return SmtpAction::finish(MailSendOutcome::failed('SMTP server offers no supported AUTH mechanism', true));
    }

    /**
     * Emits EHLO with the sender-domain identity.
     *
     * @param SmtpState $next Reply state to wait for after EHLO
     * @return SmtpAction SEND directive for the EHLO command
     */
    private function sendEhlo(SmtpState $next): SmtpAction
    {
        return $this->send($next, 'EHLO ' . $this->clientHostname());
    }

    /**
     * Emits the MAIL FROM envelope command.
     *
     * @return SmtpAction SEND directive for MAIL FROM
     */
    private function sendMailFrom(): SmtpAction
    {
        return $this->send(SmtpState::MAIL_FROM, 'MAIL FROM:<' . $this->config->fromAddress . '>');
    }

    /**
     * Emits QUIT after the message was accepted.
     *
     * @return SmtpAction SEND directive for QUIT
     */
    private function sendQuit(): SmtpAction
    {
        return $this->send(SmtpState::QUIT, 'QUIT');
    }

    /**
     * Transitions to the next state and builds the SEND directive.
     *
     * @param SmtpState $next Reply state to wait for after this command
     * @param string $line Command line without its terminator
     * @param bool $terminated Whether $line already carries its own terminator (the DATA payload)
     * @return SmtpAction SEND directive carrying the wire bytes
     */
    private function send(SmtpState $next, string $line, bool $terminated = false): SmtpAction
    {
        $this->state = $next;

        return SmtpAction::send($terminated ? $line : $line . self::CRLF);
    }

    /**
     * Builds the dot-stuffed DATA payload terminated by the end-of-data marker.
     *
     * @return string The message with leading-dot lines escaped and a trailing "\r\n.\r\n"
     */
    private function dataPayload(): string
    {
        $lines = explode(self::CRLF, $this->payload);
        foreach ($lines as $index => $line) {
            if (str_starts_with($line, '.')) {
                $lines[$index] = '.' . $line;
            }
        }

        return implode(self::CRLF, $lines) . self::CRLF . '.' . self::CRLF;
    }

    /**
     * Fails the send unless the reply carries the expected code.
     *
     * @param SmtpReply $reply The server reply
     * @param int $expected Code that advances the current step
     * @param string $sentence Domain failure sentence when the code differs
     * @return ?SmtpAction A FINISH failure action, or null when the reply is as expected
     */
    private function expect(SmtpReply $reply, int $expected, string $sentence): ?SmtpAction
    {
        return $reply->code === $expected ? null : $this->fail($reply, $sentence);
    }

    /**
     * Fails the send unless the reply carries one of the accepted codes.
     *
     * @param SmtpReply $reply The server reply
     * @param list<int> $accepted Codes that advance the current step
     * @param string $sentence Domain failure sentence when the code is not accepted
     * @return ?SmtpAction A FINISH failure action, or null when the reply is accepted
     */
    private function expectAny(SmtpReply $reply, array $accepted, string $sentence): ?SmtpAction
    {
        return in_array($reply->code, $accepted, true) ? null : $this->fail($reply, $sentence);
    }

    /**
     * Settles a failed outcome, classifying a 5xx reply as permanent.
     *
     * @param SmtpReply $reply The rejecting reply, whose code decides retryability
     * @param string $sentence Domain failure sentence exposed to the caller
     * @return SmtpAction FINISH directive carrying the failed outcome
     */
    private function fail(SmtpReply $reply, string $sentence): SmtpAction
    {
        return SmtpAction::finish(MailSendOutcome::failed($sentence, $reply->code >= 500));
    }

    /**
     * Extracts AUTH mechanisms from EHLO capability lines.
     *
     * @param string $ehloText EHLO reply text, continuation lines joined by "\n"
     * @return list<string> Upper-cased mechanism names, empty when AUTH is not advertised
     */
    private function parseAuthMechanisms(string $ehloText): array
    {
        $mechanisms = [];
        foreach (explode("\n", $ehloText) as $line) {
            if (stripos($line, 'AUTH ') !== 0 && stripos($line, 'AUTH=') !== 0) {
                continue;
            }
            foreach (preg_split('/[ =]+/', strtoupper(trim($line))) ?: [] as $token) {
                if ($token !== '' && $token !== 'AUTH') {
                    $mechanisms[] = $token;
                }
            }
        }

        return $mechanisms;
    }

    /**
     * Derives the EHLO client identity from the sender address domain.
     *
     * @return string Sender domain, or `localhost` when the address has none
     */
    private function clientHostname(): string
    {
        $at = strrpos($this->config->fromAddress, '@');
        if ($at === false || $at === strlen($this->config->fromAddress) - 1) {
            return 'localhost';
        }

        return substr($this->config->fromAddress, $at + 1);
    }
}
