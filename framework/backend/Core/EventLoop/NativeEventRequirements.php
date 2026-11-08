<?php

declare(strict_types=1);

namespace Hilos\Core\EventLoop;

use EventBase;
use EventBufferEvent;
use EventSslContext;
use Hilos\Core\EventLoop\Exception\NativeEventUnavailableException;

/**
 * Checks the loaded native API without autoloading substitutes or opening sockets.
 */
final class NativeEventRequirements
{
    /**
     * @throws NativeEventUnavailableException When ext-event lacks the required TLS or peer-certificate API
     */
    public static function assertAvailable(): void
    {
        if (!extension_loaded('event')
            || !class_exists(EventBase::class, false)
            || !class_exists(EventSslContext::class, false)
            || !class_exists(EventBufferEvent::class, false)
            || !method_exists(EventBufferEvent::class, 'sslSocket')
            || !method_exists(EventBufferEvent::class, 'sslGetPeerCertificate')
        ) {
            throw new NativeEventUnavailableException(
                'Required ext-event TLS capabilities are unavailable. Install the Hilos ext-event build: '
                . 'framework/docker/native/event/README.md',
            );
        }
    }
}
