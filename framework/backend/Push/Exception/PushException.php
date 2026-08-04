<?php

declare(strict_types=1);

namespace Hilos\Push\Exception;

use Hilos\HilosException;
use Hilos\Sms\Exception\SmsException;

/**
 * PushException - base exception for the web-push subsystem (HIL-199).
 *
 * The one family a caller of the push subsystem documents, mirroring
 * {@see SmsException}. Concrete subtypes narrow the cause (an
 * unconfigured or invalid VAPID setup, an endpoint that cannot map to a request);
 * catching this base catches every push-domain failure.
 */
class PushException extends HilosException
{
}
