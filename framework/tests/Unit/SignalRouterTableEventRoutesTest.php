<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Router\SignalRouter;
use PHPUnit\Framework\TestCase;

/**
 * Tests for table route declarations on {@see SignalRouter}.
 */
final class SignalRouterTableEventRoutesTest extends TestCase
{
    public function testReturnsDeclaredUniqueTableKeysForEvent(): void
    {
        $router = new class extends SignalRouter {
            public function __construct()
            {
                parent::__construct();

                $this->config = [
                    'table_event_routes' => [
                        'user_updated' => ['adminUsers', 'hilosUsers', 'adminUsers', '', 12],
                    ],
                ];
            }
        };

        $this->assertSame(['adminUsers', 'hilosUsers'], $router->getTableKeysForEvent('user_updated'));
    }

    public function testUnknownEventReturnsEmptyList(): void
    {
        $this->assertSame([], (new SignalRouter())->getTableKeysForEvent('missing'));
    }
}
