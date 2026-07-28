<?php

declare(strict_types=1);

namespace Hilos\Push\Exception;

/**
 * PushConfigException - the VAPID configuration is missing or invalid (HIL-199).
 *
 * Raised while building a web-push request when the effective VAPID setup cannot sign
 * or encrypt a send: an empty key pair or subject, a key that is not a valid P-256
 * point, or an endpoint URL with no resolvable host. The channel agent catches it and
 * settles the send as a permanent failure rather than letting it crash the tick loop.
 */
final class PushConfigException extends PushException
{
}
