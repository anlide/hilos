<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AbstractClient header and cookie parsing shared by the
 * WebSocket and HTTP server paths.
 */
final class AbstractClientHeaderParsingTest extends TestCase
{
    public function testParseHeadersLowercasesNamesAndPreservesValues(): void
    {
        $probe = WebSocketClientTestProbe::createSocketless();

        $this->assertSame(
            [
                'sec-websocket-key' => 'UnitKeyValue==',
                'x-custom' => 'Spaced-Value',
            ],
            $probe->exposeParseHeaders([
                'GET /ws HTTP/1.1',
                'Sec-WebSocket-Key: UnitKeyValue==',
                'X-CuStOm:  Spaced-Value ',
                'broken-line-without-colon',
                '',
                'after-empty: ignored',
            ]),
        );
    }

    public function testParseCookiesReadsLowercaseWireCookieHeader(): void
    {
        $probe = WebSocketClientTestProbe::createSocketless();

        $this->assertSame(
            ['session' => 'abc', 'lang' => 'en'],
            $probe->exposeParseCookies(['cookie' => 'session=abc; lang=en']),
        );
    }

    public function testParseCookiesReadsCanonicalCookieHeaderFromHandBuiltMap(): void
    {
        $probe = WebSocketClientTestProbe::createSocketless();

        $this->assertSame(
            ['session' => 'abc'],
            $probe->exposeParseCookies(['Cookie' => 'session=abc']),
        );
    }
}
