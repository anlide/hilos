<?php

declare(strict_types=1);

namespace Hilos\Mail;

/**
 * MailTransportConfig - the resolved transport settings for one send (HIL-197).
 *
 * The value {@see MailTransportFactory} reads to build a concrete
 * {@see MailTransportInterface}: the SMTP endpoint and credentials, the sender identity
 * applied at encode time, the send timeout, and the directory the file transport writes
 * to. {@see transport} pins the driver (`smtp` or `file`); left null it auto-selects the
 * file transport whenever {@see smtpHost} is empty, so a project with no relay configured
 * still produces a verifiable .eml artifact. Secrets live only here, never in DB settings.
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
}
