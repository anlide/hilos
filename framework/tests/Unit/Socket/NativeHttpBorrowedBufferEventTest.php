<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket;

use Event;
use EventBase;
use EventBufferEvent;
use EventHttp;
use EventHttpRequest;
use PHPUnit\Framework\TestCase;
use WeakReference;

/** A live HTTP connection's callback argument is a native root of its borrowed PHP wrapper. */
final class NativeHttpBorrowedBufferEventTest extends TestCase
{
    /** GC must preserve the callback argument until explicit retirement, including before later native input. */
    public function testBorrowedCallbackOwnerSurvivesGcBeforeLateNativeInput(): void
    {
        $fixture = new MutualTlsFixture();
        $base = new EventBase();
        $http = new EventHttp($base);
        $listener = $fixture->listen();
        $this->assertTrue(socket_getsockname($listener, $host, $port));
        $this->assertTrue($http->accept($listener));
        $request = null;
        $owner = null;
        $http->setDefaultCallback(static function (EventHttpRequest $incoming) use (&$request, &$owner): void {
            $request = $incoming;
            $borrowed = $incoming->getBufferEvent();
            $owner = WeakReference::create($borrowed);
            $borrowed->setCallbacks(static function (): void {}, null, static function (): void {});
            $borrowed->enable(Event::READ);
        });
        $client = new EventBufferEvent($base);
        try {
            $this->assertTrue($client->connect('127.0.0.1:' . $port));
            $this->assertTrue($client->enable(Event::READ | Event::WRITE));
            $this->assertTrue($client->write("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n"));
            $deadline = microtime(true) + 5.0;
            while ($request === null && microtime(true) < $deadline) {
                $base->loop(EventBase::LOOP_NONBLOCK);
            }
            $this->assertInstanceOf(EventHttpRequest::class, $request);
            $this->assertInstanceOf(WeakReference::class, $owner);
            gc_collect_cycles();

            // Fail before polling a dangling native callback argument on an unrepaired module.
            $this->assertNotNull($owner->get(), 'the live HTTP bufferevent still stores this PHP callback argument');
            $borrowed = $owner->get();
            $this->assertTrue($client->write('later'));
            $deadline = microtime(true) + 5.0;
            while ($borrowed->input->length < 5 && microtime(true) < $deadline) {
                $base->loop(EventBase::LOOP_NONBLOCK);
            }
            $this->assertSame('later', $borrowed->read(5), 'native input still reaches the borrowed handle after GC');
            $this->assertSame(Event::READ, $borrowed->getEnabled() & Event::READ);
            $borrowed->free();
            $borrowed->free();
            unset($borrowed);
            gc_collect_cycles();
            $this->assertNull($owner->get(), 'explicit retirement releases the native root for cycle collection');
        } finally {
            if ($request !== null) {
                // Replace the callbacks before any further poll, also after the safe old-module failure.
                $cleanup = $request->getBufferEvent();
                $cleanup->setCallbacks(null, null, null);
                $cleanup->free();
                unset($cleanup);
            }
            $client->free();
            unset($client, $request, $http);
            $fixture->close();
            gc_collect_cycles();
        }
    }
}
