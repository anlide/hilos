<?php

declare(strict_types=1);

namespace Hilos\Sms\Exception;

use Hilos\Sms\GenericHttpSmsProvider;

/**
 * SmsConfigException - the SMS gateway configuration is invalid (HIL-285).
 *
 * Raised by {@see GenericHttpSmsProvider::buildRequest()} when the resolved
 * config cannot map to a gateway request - today only an endpoint URL with no host. A
 * type/missing failure on a single env key surfaces as the accessor's own EnvException;
 * this covers the domain-level mismatch. The channel agent catches it and settles the send
 * as a permanent failure rather than letting it crash the tick loop.
 */
final class SmsConfigException extends SmsException
{
}
