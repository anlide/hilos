<?php

declare(strict_types=1);

namespace Hilos\Mail\Exception;

/**
 * The mail transport configuration read from env is invalid (HIL-197).
 *
 * Raised by {@see \Hilos\Mail\MailTransportConfig::fromEnv()} when an env value cannot
 * map to the transport recipe — today only an unrecognized MAIL_SMTP_SECURITY mode. A
 * type/missing failure on a single env key surfaces as the accessor's own EnvException;
 * this covers the domain-level mismatch the accessor cannot catch.
 */
final class MailConfigException extends MailException
{
}
