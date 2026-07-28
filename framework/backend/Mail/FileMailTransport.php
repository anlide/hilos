<?php

declare(strict_types=1);

namespace Hilos\Mail;

use Hilos\Mail\Exception\MailBusyException;
use Hilos\Mail\Exception\MailResultUnavailableException;

/**
 * FileMailTransport - a dev/e2e transport that writes each message as a .eml file (HIL-197).
 *
 * The auto transport when no SMTP host is configured, and the explicit choice under
 * MAIL_TRANSPORT=file. It encodes the message exactly as an SMTP transport would and
 * writes it to the mail directory, then reports success — giving dev and e2e a verifiable
 * artifact of every email (which finally makes code-gated auth flows e2e-testable) instead
 * of a line in the log. The send is synchronous: it settles within {@see start()}, so
 * {@see tick()} is a no-op.
 */
final class FileMailTransport implements MailTransportInterface
{
    /** @var MailMessageEncoder Shared wire-format encoder. */
    private MailMessageEncoder $encoder;

    /** @var ?MailSendOutcome Settled outcome awaiting consumption, or null. */
    private ?MailSendOutcome $result = null;

    /**
     * @param string $directory Directory the .eml artifacts are written to
     * @param string $fromAddress Sender email address applied when encoding
     * @param ?string $fromName Sender display name, or null for the bare address
     */
    public function __construct(
        private readonly string $directory,
        private readonly string $fromAddress,
        private readonly ?string $fromName = null,
    ) {
        $this->encoder = new MailMessageEncoder();
    }

    /**
     * Encodes the message and writes it as a .eml artifact, settling the outcome at once.
     *
     * @param EmailMessage $message The recipient message to write
     * @param float $nowMs Current time in milliseconds, used to name the artifact
     * @throws MailBusyException When a settled result has not been consumed yet
     */
    public function start(EmailMessage $message, float $nowMs): void
    {
        if ($this->result !== null) {
            throw new MailBusyException();
        }

        $encoded = $this->encoder->encode($message, $this->fromAddress, $this->fromName);
        $this->result = $this->write($encoded, $nowMs);
    }

    /**
     * No-op: a file send settles synchronously in {@see start()}.
     *
     * @param float $nowMs Current time in milliseconds (unused)
     */
    public function tick(float $nowMs): void
    {
    }

    /**
     * @return bool Always false — a file send never stays in flight across ticks
     */
    public function isBusy(): bool
    {
        return false;
    }

    /**
     * @return bool True while a settled outcome awaits consumption
     */
    public function hasResult(): bool
    {
        return $this->result !== null;
    }

    /**
     * @return MailSendOutcome The write outcome
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
     * Drops any unconsumed outcome.
     */
    public function close(): void
    {
        $this->result = null;
    }

    /**
     * Writes the encoded message to a uniquely named .eml artifact.
     *
     * @param string $encoded Encoded wire message
     * @param float $nowMs Current time in milliseconds, used to name the artifact
     * @return MailSendOutcome Delivered on success, or a permanent failure when the write fails
     */
    private function write(string $encoded, float $nowMs): MailSendOutcome
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o775, true) && !is_dir($this->directory)) {
            return MailSendOutcome::failed('mail file directory is not writable', true);
        }

        $path = $this->directory . '/' . (int)$nowMs . '-' . substr(hash('sha256', $encoded), 0, 16) . '.eml';
        if (file_put_contents($path, $encoded) === false) {
            return MailSendOutcome::failed('mail file could not be written', true);
        }

        return MailSendOutcome::delivered();
    }
}
