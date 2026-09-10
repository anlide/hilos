<?php

declare(strict_types=1);

namespace Hilos\Mail;

use Hilos\Constants\EnvConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Mail\Exception\MailConfigException;

/**
 * MailTransportConfig - the resolved transport settings for one send (HIL-197).
 *
 * The value {@see MailTransportFactory} reads to build a concrete
 * {@see MailTransportInterface}: the SMTP endpoint and credentials, the sender identity
 * applied at encode time, the send timeout, and the directory the file transport writes
 * to. {@see transport} pins the driver (`smtp` or `file`); left null it auto-selects the
 * file transport whenever {@see smtpHost} is empty, so a project with no relay configured
 * still produces a verifiable .eml artifact. Secrets live only here, never in DB settings.
 * Built from the `MAIL_*` env values via {@see fromEnv()}; tests build one directly.
 */
final class MailTransportConfig
{
    /**
     * @param string $fromAddress Sender email address applied when encoding
     * @param string $fileDir Directory the file transport writes .eml artifacts to
     * @param ?string $transport Forced driver `smtp`|`file`, or null to auto-select
     * @param string $smtpHost SMTP host, empty when no relay is configured
     * @param int $smtpPort SMTP port
     * @param SmtpSecurity $security Transport-security mode
     * @param ?string $username SMTP AUTH username, or null for an unauthenticated relay
     * @param ?string $password SMTP AUTH password, or null for an unauthenticated relay
     * @param ?string $fromName Sender display name, or null for the bare address
     * @param int $timeoutMs Per-send timeout in milliseconds
     */
    public function __construct(
        public readonly string $fromAddress,
        public readonly string $fileDir,
        public readonly ?string $transport = null,
        public readonly string $smtpHost = '',
        public readonly int $smtpPort = 587,
        public readonly SmtpSecurity $security = SmtpSecurity::STARTTLS,
        public readonly ?string $username = null,
        public readonly ?string $password = null,
        public readonly ?string $fromName = null,
        public readonly int $timeoutMs = 10000,
    ) {
    }

    /**
     * Builds the transport recipe from the `MAIL_*` environment values.
     *
     * The optional-secret keys (forced driver, SMTP credentials, sender display name) map
     * an empty env value to null so an unset relay stays unauthenticated and an unset
     * driver auto-selects. The pool sizing keys (MAIL_WORKER_COUNT, MAIL_MAX_CONCURRENT)
     * are read by the mailer and agent, not by the transport, and are absent here.
     *
     * @return self Resolved transport settings
     * @throws EnvException When a MAIL_* env value is missing from the catalog or has the wrong type
     * @throws MailConfigException When MAIL_SMTP_SECURITY is not a recognized mode
     */
    public static function fromEnv(): self
    {
        return new self(
            Hilos::$env[EnvConstants::MAIL_FROM_ADDRESS]->string(),
            Hilos::$env[EnvConstants::MAIL_FILE_DIR]->string(),
            self::nullIfEmpty(Hilos::$env[EnvConstants::MAIL_TRANSPORT]->string()),
            Hilos::$env[EnvConstants::MAIL_SMTP_HOST]->string(),
            Hilos::$env[EnvConstants::MAIL_SMTP_PORT]->int(),
            self::parseSecurity(Hilos::$env[EnvConstants::MAIL_SMTP_SECURITY]->string()),
            self::nullIfEmpty(Hilos::$env[EnvConstants::MAIL_SMTP_USERNAME]->string()),
            self::nullIfEmpty(Hilos::$env[EnvConstants::MAIL_SMTP_PASSWORD]->string()),
            self::nullIfEmpty(Hilos::$env[EnvConstants::MAIL_FROM_NAME]->string()),
            Hilos::$env[EnvConstants::MAIL_TIMEOUT_MS]->int(),
        );
    }

    /**
     * Decides whether these settings resolve to the file transport rather than a relay.
     *
     * The rule lives here rather than in {@see MailTransportFactory} because two
     * callers now need it and only one of them builds a transport: the factory picks
     * the class to send with, and the auth layer asks whether a one-time code mailed
     * from this installation would reach anybody at all - a .eml written to a
     * directory reaches nobody, so an address is not a way to deliver a code when
     * this answers true (HIL-830).
     *
     * @return bool True for an explicit `file` selection or auto-selection with no SMTP host
     */
    public function usesFileTransport(): bool
    {
        if ($this->transport === MailTransportFactory::TRANSPORT_FILE) {
            return true;
        }

        if ($this->transport === MailTransportFactory::TRANSPORT_SMTP) {
            return false;
        }

        return $this->smtpHost === '';
    }

    /**
     * @param string $value Security mode read from MAIL_SMTP_SECURITY
     * @return SmtpSecurity Matched transport-security mode
     * @throws MailConfigException When the value is not a recognized mode
     */
    private static function parseSecurity(string $value): SmtpSecurity
    {
        return SmtpSecurity::tryFrom(strtolower(trim($value)))
            ?? throw new MailConfigException("MAIL_SMTP_SECURITY '{$value}' is not one of tls|starttls|none");
    }

    /**
     * @param string $value Raw env string
     * @return ?string The trimmed value, or null when it is empty
     */
    private static function nullIfEmpty(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
