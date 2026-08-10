<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Runtime\State\Collection\Connections as StateConnections;
use Demo\Chat\Runtime\State\Item\Connection as StateConnection;
use Demo\Chat\Runtime\View\Collection\Connections;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the session-scoped accept-key delegate the session-host seam
 * iterates instead of reaching into runtime state.
 *
 * The delegate itself is framework-owned since HIL-509 — it lives on the session
 * stage of the connections View base — so what these cases prove of chat is that
 * standing on that stage is all it takes to have it.
 */
final class ConnectionsAcceptKeysForSessionTokenTest extends TestCase
{
    private const string TOKEN = 'session-token-a';
    private const string OTHER_TOKEN = 'session-token-b';

    /**
     * Builds a View collection over a fixture state collection holding two
     * connections on {@see self::TOKEN} and one on {@see self::OTHER_TOKEN}.
     *
     * @return Connections View collection wrapping the fixture connections
     */
    private function connections(): Connections
    {
        $state = StateConnections::init();
        $state->add(StateConnection::create('accept-1', 10, self::TOKEN));
        $state->add(StateConnection::create('accept-2', null, self::TOKEN));
        $state->add(StateConnection::create('accept-3', 20, self::OTHER_TOKEN));

        $view = Connections::init();
        $view->setStateCollection($state);

        return $view;
    }

    public function testReturnsAcceptKeysOfEveryConnectionOnTheToken(): void
    {
        $this->assertSame(
            ['accept-1', 'accept-2'],
            $this->connections()->acceptKeysForSessionToken(self::TOKEN),
        );
    }

    public function testReturnsOnlyTheTokensOwnConnections(): void
    {
        $this->assertSame(
            ['accept-3'],
            $this->connections()->acceptKeysForSessionToken(self::OTHER_TOKEN),
        );
    }

    public function testUnknownTokenYieldsNoKeys(): void
    {
        $this->assertSame([], $this->connections()->acceptKeysForSessionToken('no-such-token'));
    }

    public function testEmptyTokenYieldsNoKeys(): void
    {
        $this->assertSame([], $this->connections()->acceptKeysForSessionToken(''));
    }
}
