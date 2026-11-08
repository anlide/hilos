<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket;

use EventBase;
use EventBufferEvent;
use EventSslContext;
use PHPUnit\Framework\TestCase;
use WeakReference;

/** Native owner references must outlive SSL callbacks and remain visible to cycle collection. */
final class NativePeerCertificateLifetimeTest extends TestCase
{
    /** Each SSL ownership option keeps its callback context until the native handle is released exactly once. */
    public function testSslContextSurvivesItsCallerAndIsReleasedByRepeatedFree(): void
    {
        $base = new EventBase();
        foreach ([
            0,
            EventBufferEvent::OPT_CLOSE_ON_FREE,
            EventBufferEvent::OPT_DEFER_CALLBACKS,
            EventBufferEvent::OPT_CLOSE_ON_FREE | EventBufferEvent::OPT_DEFER_CALLBACKS,
        ] as $options) {
            $context = new EventSslContext(EventSslContext::TLS_CLIENT_METHOD, [EventSslContext::OPT_VERIFY_PEER => false]);
            $owner = WeakReference::create($context);
            $event = EventBufferEvent::sslSocket($base, null, $context, EventBufferEvent::SSL_CONNECTING, $options);
            unset($context);
            try {
                $this->assertNotNull($owner->get(), 'SSL ex_data still borrows the PHP context');
            } finally {
                $event->free();
                $event->free();
            }
            $this->assertNull($owner->get(), 'free must release its context reference');
            $this->assertNull($event->sslGetPeerCertificate());
            $base->loop(EventBase::LOOP_NONBLOCK);
        }
    }

    /** A callback that captures its own paired owner is a collectable cycle, even with pending native bytes. */
    public function testPairedCallbackCyclesReleaseBothOwners(): void
    {
        $base = new EventBase();
        $pair = EventBufferEvent::createPair($base);
        $owners = array_map(WeakReference::create(...), $pair);
        foreach ($pair as $event) {
            $event->setCallbacks(static function () use ($event): void {
                $event->read(8192);
            }, null, null);
        }
        $pair[0]->write('pending');
        unset($pair, $event);
        gc_collect_cycles();
        foreach ($owners as $owner) {
            $this->assertNull($owner->get(), 'neither the borrowed self nor a callback cycle owns a survivor');
        }
        $base->loop(EventBase::LOOP_NONBLOCK);
    }

    /** Freeing a plain native wrapper leaves the caller's Socket usable, including with retained buffer aliases. */
    public function testRepeatedFreePreservesThePhpOwnedSocket(): void
    {
        $fixture = new MutualTlsFixture();
        [$socket, $peer] = $fixture->connectSockets();
        $base = new EventBase();
        $event = new EventBufferEvent($base, $socket);
        $input = $event->input;
        $output = $event->output;
        try {
            $event->free();
            $event->free();
            unset($event, $input, $output);
            $this->assertSame(5, socket_write($socket, 'alive'));
            $this->assertSame('alive', socket_read($peer, 5));
        } finally {
            $fixture->close();
        }
    }
}
