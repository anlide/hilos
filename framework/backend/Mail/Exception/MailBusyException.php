<?php

declare(strict_types=1);

namespace Hilos\Mail\Exception;

/**
 * Thrown when a send is started on a transport that is still busy with one (HIL-197).
 *
 * A transport carries a single in-flight send; callers must gate on
 * {@see \Hilos\Mail\MailTransportInterface::isBusy()} before starting the next.
 */
final class MailBusyException extends MailException
{
    public function __construct()
    {
        parent::__construct('Mail transport is busy with an in-flight send');
    }
}
