<?php

declare(strict_types=1);

namespace Hilos\Mail;

use Hilos\Mail\Exception\MailBusyException;
use Hilos\Mail\Exception\MailResultUnavailableException;
use Hilos\Mail\Smtp\SmtpAction;
use Hilos\Mail\Smtp\SmtpActionKind;
use Hilos\Mail\Smtp\SmtpConnectionPhase;
use Hilos\Mail\Smtp\SmtpDialog;
use Hilos\Mail\Smtp\SmtpReply;

/**
 * SmtpMailTransport - a non-blocking SMTP driver for one email send (HIL-197).
 *
 * The first real transport behind {@see MailTransportInterface}, modeled on
 * {@see \Hilos\API\AsyncHttpClient}: it opens a stream socket, then pumps the send one
 * step per {@see tick()} so the mail agent's worker loop never blocks. The protocol logic
 * lives in a pure {@see SmtpDialog}; this class only owns the socket — completing the
 * async connect, running the (implicit or STARTTLS) handshake, flushing writes, reading
 * replies, and enforcing the timeout. It also stamps the Date and Message-ID headers here
 * (not in the deterministic encoder) just before the DATA payload is built.
 *
 * Per the interface contract a send failure never throws: a timeout, a dropped socket, or
 * a rejecting reply settles a failed {@see MailSendOutcome}. Only contract misuse throws.
 */
class SmtpMailTransport implements MailTransportInterface
{
    /** Line terminator on the SMTP wire (RFC 5321). */
    private const string CRLF = "\r\n";

    /** @var MailMessageEncoder Shared wire-format encoder. */
    private MailMessageEncoder $encoder;

    /** @var SmtpConnectionPhase Current socket phase. */
    private SmtpConnectionPhase $phase = SmtpConnectionPhase::IDLE;

    /** @var ?resource Stream socket, or null when idle. */
    private $socket = null;

    /** @var ?SmtpDialog Protocol state machine for the in-flight send, or null when idle. */
    private ?SmtpDialog $dialog = null;

    /** @var bool Whether the pending handshake is a STARTTLS upgrade (vs implicit TLS). */
    private bool $handshakeIsStartTls = false;

    /** @var string Bytes still to write to the socket. */
    private string $pendingWrite = '';

    /** @var string Reply bytes read but not yet parsed. */
    private string $readBuffer = '';

    /** @var float Send start time in milliseconds, for timeout tracking. */
    private float $startTime = 0.0;

    /** @var ?MailSendOutcome Settled outcome awaiting consumption, or null. */
    private ?MailSendOutcome $result = null;

    /**
     * @param MailTransportConfig $config Endpoint, security, credentials, and timeout
     */
    public function __construct(
        private readonly MailTransportConfig $config,
    ) {
        $this->encoder = new MailMessageEncoder();
    }

    /**
     * Opens an SMTP send: encodes the message, stamps Date/Message-ID, and connects.
     *
     * @param EmailMessage $message The recipient message to deliver
     * @param float $nowMs Current time in milliseconds
     * @throws MailBusyException When a previous send is still in flight or unconsumed
     */
    public function start(EmailMessage $message, float $nowMs): void
    {
        if ($this->phase !== SmtpConnectionPhase::IDLE || $this->result !== null) {
            throw new MailBusyException();
        }

        $encoded = $this->encoder->encode($message, $this->config->fromAddress, $this->config->fromName);
        $this->dialog = new SmtpDialog($message, $this->config, $this->stampHeaders($encoded, $nowMs));
        $this->pendingWrite = '';
        $this->readBuffer = '';
        $this->startTime = $nowMs;

        $socket = $this->establishSocket();
        if (!is_resource($socket)) {
            $this->settle(MailSendOutcome::failed('SMTP connection could not be opened', false));
            return;
        }

        $this->socket = $socket;
        $this->phase = SmtpConnectionPhase::CONNECTING;
    }

    /**
     * Advances the in-flight send by one non-blocking step.
     *
     * @param float $nowMs Current time in milliseconds
     */
    public function tick(float $nowMs): void
    {
        if ($this->phase === SmtpConnectionPhase::IDLE) {
            return;
        }

        if ($nowMs - $this->startTime >= $this->config->timeoutMs) {
            $this->settle(MailSendOutcome::failed('SMTP send timed out', false));
            return;
        }

        match ($this->phase) {
            SmtpConnectionPhase::CONNECTING => $this->processConnecting(),
            SmtpConnectionPhase::HANDSHAKE => $this->processHandshake($nowMs),
            SmtpConnectionPhase::WRITING => $this->processWriting(),
            SmtpConnectionPhase::READING => $this->processReading($nowMs),
            SmtpConnectionPhase::IDLE => null,
        };
    }

    /**
     * @return bool True while a send is in flight
     */
    public function isBusy(): bool
    {
        return $this->phase !== SmtpConnectionPhase::IDLE;
    }

    /**
     * @return bool True when a settled outcome is ready to consume
     */
    public function hasResult(): bool
    {
        return $this->result !== null;
    }

    /**
     * @return MailSendOutcome The terminal outcome of the last send
     * @throws MailResultUnavailableException When no outcome is ready
     */
    public function consumeResult(): MailSendOutcome
    {
        if ($this->result === null) {
            throw new MailResultUnavailableException();
        }

        $result = $this->result;
        $this->result = null;

        return $result;
    }

    /**
     * Abandons any in-flight send, closes the socket, and drops an unconsumed outcome.
     */
    public function close(): void
    {
        $this->closeSocket();
        $this->phase = SmtpConnectionPhase::IDLE;
        $this->dialog = null;
        $this->pendingWrite = '';
        $this->readBuffer = '';
        $this->result = null;
    }

    /**
     * Opens the non-blocking stream socket to the configured SMTP host.
     *
     * @return resource|false The connected non-blocking socket, or false on failure
     */
    protected function establishSocket()
    {
        $address = 'tcp://' . $this->config->smtpHost . ':' . $this->config->smtpPort;
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            0,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
        );

        if (!is_resource($socket)) {
            return false;
        }

        @stream_set_blocking($socket, false);

        return $socket;
    }

    /**
     * Drives the TLS handshake on the open socket one non-blocking step.
     *
     * The crypto seam: tests override it to simulate the handshake without a real TLS peer.
     *
     * @return int|bool True once secured, 0 while the handshake needs more steps, false on failure
     */
    protected function enableCrypto(): int|bool
    {
        return @stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    }

    /**
     * Advances the async connect; on completion enters the handshake or reads the greeting.
     */
    private function processConnecting(): void
    {
        $read = [];
        $write = [$this->socket];
        $except = [];
        if (@stream_select($read, $write, $except, 0, 0) === false) {
            $this->settle(MailSendOutcome::failed('SMTP connection failed', false));
            return;
        }

        if ($write === []) {
            return;
        }

        if ($this->config->security === SmtpSecurity::TLS) {
            $this->handshakeIsStartTls = false;
            $this->phase = SmtpConnectionPhase::HANDSHAKE;
            return;
        }

        $this->phase = SmtpConnectionPhase::READING;
    }

    /**
     * Advances the TLS handshake; on completion resumes the conversation or reads the greeting.
     *
     * @param float $nowMs Current time in milliseconds
     */
    private function processHandshake(float $nowMs): void
    {
        $enabled = $this->enableCrypto();
        if ($enabled === 0) {
            return;
        }

        if ($enabled !== true) {
            $this->settle(MailSendOutcome::failed('SMTP TLS handshake failed', false));
            return;
        }

        if ($this->handshakeIsStartTls) {
            $this->applyAction($this->dialog->onSecured(), $nowMs);
            return;
        }

        $this->phase = SmtpConnectionPhase::READING;
    }

    /**
     * Flushes pending write bytes; returns to reading once the buffer drains.
     */
    private function processWriting(): void
    {
        $written = @fwrite($this->socket, $this->pendingWrite);
        if ($written === false) {
            $this->settle(MailSendOutcome::failed('SMTP socket write failed', false));
            return;
        }

        $this->pendingWrite = substr($this->pendingWrite, $written);
        if ($this->pendingWrite === '') {
            $this->phase = SmtpConnectionPhase::READING;
        }
    }

    /**
     * Reads available reply bytes, feeding each complete reply to the dialog.
     *
     * @param float $nowMs Current time in milliseconds
     */
    private function processReading(float $nowMs): void
    {
        $this->consumeReplies($nowMs);
        if ($this->phase !== SmtpConnectionPhase::READING) {
            return;
        }

        $read = [$this->socket];
        $write = [];
        $except = [];
        if (@stream_select($read, $write, $except, 0, 0) === false) {
            $this->settle(MailSendOutcome::failed('SMTP socket read failed', false));
            return;
        }

        if ($read === []) {
            return;
        }

        $chunk = @fread($this->socket, 8192);
        if ($chunk === false) {
            $this->settle(MailSendOutcome::failed('SMTP socket read failed', false));
            return;
        }

        $this->readBuffer .= $chunk;
        $this->consumeReplies($nowMs);

        if ($this->phase === SmtpConnectionPhase::READING && $chunk === '' && @feof($this->socket)) {
            $this->applyAction($this->dialog->onDisconnect(), $nowMs);
        }
    }

    /**
     * Feeds complete buffered replies to the dialog until it changes phase or the buffer empties.
     *
     * @param float $nowMs Current time in milliseconds
     */
    private function consumeReplies(float $nowMs): void
    {
        while ($this->phase === SmtpConnectionPhase::READING) {
            $reply = SmtpReply::parse($this->readBuffer);
            if ($reply === null) {
                return;
            }

            $this->applyAction($this->dialog->onReply($reply), $nowMs);
        }
    }

    /**
     * Carries out one dialog directive against the socket.
     *
     * @param SmtpAction $action The dialog's directive
     * @param float $nowMs Current time in milliseconds
     */
    private function applyAction(SmtpAction $action, float $nowMs): void
    {
        switch ($action->kind) {
            case SmtpActionKind::SEND:
                $this->pendingWrite = (string)$action->bytes;
                $this->phase = SmtpConnectionPhase::WRITING;
                break;

            case SmtpActionKind::START_TLS:
                // Discard any bytes buffered before the TLS handshake: a MITM can inject
                // plaintext after the "220 Ready" that would otherwise be parsed as the
                // post-TLS EHLO reply (STARTTLS command injection, cf. CVE-2011-0411).
                $this->readBuffer = '';
                $this->handshakeIsStartTls = true;
                $this->phase = SmtpConnectionPhase::HANDSHAKE;
                break;

            case SmtpActionKind::FINISH:
                $this->settle($action->outcome ?? MailSendOutcome::delivered());
                break;
        }
    }

    /**
     * Prepends the transport-owned Date and Message-ID headers to the encoded message.
     *
     * @param string $encoded Deterministically encoded RFC 5322 message
     * @param float $nowMs Current time in milliseconds, used for Date and Message-ID
     * @return string The message with Date and Message-ID prepended
     */
    private function stampHeaders(string $encoded, float $nowMs): string
    {
        $seconds = (int)($nowMs / 1000);
        $messageId = '<' . substr(hash('sha256', $encoded . $nowMs), 0, 32) . '@' . $this->messageIdDomain() . '>';

        return 'Date: ' . date('r', $seconds) . self::CRLF
            . 'Message-ID: ' . $messageId . self::CRLF
            . $encoded;
    }

    /**
     * Derives the Message-ID right-hand side from the sender address domain.
     *
     * @return string Sender domain, or `localhost` when the address has none
     */
    private function messageIdDomain(): string
    {
        $at = strrpos($this->config->fromAddress, '@');
        if ($at === false || $at === strlen($this->config->fromAddress) - 1) {
            return 'localhost';
        }

        return substr($this->config->fromAddress, $at + 1);
    }

    /**
     * Settles the outcome, closes the socket, and returns the transport to idle.
     *
     * @param MailSendOutcome $outcome The terminal outcome
     */
    private function settle(MailSendOutcome $outcome): void
    {
        $this->closeSocket();
        $this->phase = SmtpConnectionPhase::IDLE;
        $this->dialog = null;
        $this->pendingWrite = '';
        $this->readBuffer = '';
        $this->result = $outcome;
    }

    /**
     * Closes the stream socket if open.
     */
    private function closeSocket(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }

        $this->socket = null;
    }
}
