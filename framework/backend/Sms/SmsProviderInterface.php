<?php

declare(strict_types=1);

namespace Hilos\Sms;

/**
 * SmsProviderInterface - the seam every SMS provider implements (HIL-285).
 *
 * Splits the provider knowledge (endpoint, credentials, field map, success rule) from the
 * two ways a send is driven, mirroring the OAuth provider split: an {@see HttpSmsProvider}
 * yields an {@see SmsHttpRequest} the sharded agent replays over non-blocking sockets, while
 * a {@see DirectSmsProvider} settles the send in-process for dev/e2e. This base carries only
 * the stable key both share, produced with no I/O.
 */
interface SmsProviderInterface
{
    /**
     * Returns the stable provider key, e.g. `generic` or `stub`.
     *
     * @return string Provider key
     */
    public function getKey(): string;
}
