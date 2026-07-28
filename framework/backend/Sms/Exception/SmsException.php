<?php

declare(strict_types=1);

namespace Hilos\Sms\Exception;

use Hilos\HilosException;

/**
 * SmsException - base exception for the SMS subsystem (HIL-285).
 *
 * The one family a caller of the SMS subsystem documents, mirroring
 * {@see \Hilos\Mail\Exception\MailException}. Concrete subtypes narrow the cause
 * (an unknown template key, an invalid provider config); catching this base catches
 * every SMS-domain failure.
 */
class SmsException extends HilosException
{
}
