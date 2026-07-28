<?php

declare(strict_types=1);

namespace Hilos\Mail;

/**
 * MailTransportFactory - builds the configured mail transport (HIL-197).
 *
 * Turns a {@see MailTransportConfig} into a concrete {@see MailTransportInterface}: an
 * explicit `smtp` or `file` selection wins, and with no selection it auto-picks the file
 * transport whenever no SMTP host is configured — so a project without a relay still
 * produces a verifiable .eml artifact instead of failing to send. The factory is pure;
 * reading env into the config happens at the facade boundary.
 */
final class MailTransportFactory
{
    /** Selection value forcing the SMTP transport. */
    public const string TRANSPORT_SMTP = 'smtp';

    /** Selection value forcing the file transport. */
    public const string TRANSPORT_FILE = 'file';

    /**
     * Builds the transport the config selects.
     *
     * @param MailTransportConfig $config Resolved transport settings
     * @return MailTransportInterface The SMTP transport, or the file transport when selected or auto-picked
     */
    public function create(MailTransportConfig $config): MailTransportInterface
    {
        if ($this->usesFileTransport($config)) {
            return new FileMailTransport($config->fileDir, $config->fromAddress, $config->fromName);
        }

        return new SmtpMailTransport($config);
    }

    /**
     * Decides whether the config resolves to the file transport.
     *
     * @param MailTransportConfig $config Resolved transport settings
     * @return bool True for an explicit `file` selection or auto-selection with no SMTP host
     */
    private function usesFileTransport(MailTransportConfig $config): bool
    {
        if ($config->transport === self::TRANSPORT_FILE) {
            return true;
        }

        if ($config->transport === self::TRANSPORT_SMTP) {
            return false;
        }

        return $config->smtpHost === '';
    }
}
