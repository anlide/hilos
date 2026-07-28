<?php

declare(strict_types=1);

namespace Hilos\Mail\Exception;

/**
 * Thrown when a result is consumed from a transport that has none ready (HIL-197).
 *
 * Callers must gate on {@see \Hilos\Mail\MailTransportInterface::hasResult()} before
 * calling {@see \Hilos\Mail\MailTransportInterface::consumeResult()}.
 */
final class MailResultUnavailableException extends MailException
{
    public function __construct()
    {
        parent::__construct('No mail send result is available to consume');
    }
}
