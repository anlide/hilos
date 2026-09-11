<?php

declare(strict_types=1);

namespace Hilos\Mail;

/**
 * MailTransportFactory - builds the configured mail transport (HIL-197).
 *
 * Turns a {@see MailTransportConfig} into a concrete {@see MailTransportInterface}: an
 * explicit `smtp` or `file` selection wins, and with no selection it auto-picks the file
 * transport whenever no SMTP host is configured — so a project without a relay still
 * produces a verifiable .eml artifact instead of failing to send. That selection rule
 * is the config's own ({@see MailTransportConfig::usesFileTransport()}), because the
 * auth layer asks the same question without ever building a transport. The factory is
 * pure; reading env into the config happens at the facade boundary.
 *
 * An explicit {@see TRANSPORT_FILE} with a directory to write into is the DECLARED test
 * mode (HIL-827): the installation mails nobody on purpose and the code screen says so.
 * Auto-selection reaches the same class without anybody declaring anything, and that
 * difference is {@see MailTransportConfig::isTestMode()} rather than a second env key.
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
        if ($config->usesFileTransport()) {
            return new FileMailTransport($config->fileDir, $config->fromAddress, $config->fromName);
        }

        return new SmtpMailTransport($config);
    }
}
