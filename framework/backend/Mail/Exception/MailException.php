<?php

declare(strict_types=1);

namespace Hilos\Mail\Exception;

use Hilos\HilosException;

/**
 * Base exception for the mail subsystem (HIL-197).
 *
 * Transport send failures are not thrown — they settle as a failed
 * {@see \Hilos\Mail\MailSendOutcome}. This family covers misuse of the transport
 * contract (starting a busy transport, consuming an absent result).
 */
class MailException extends HilosException
{
}
