<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Environment;

use Hilos\Constants\EnvConstants;
use Hilos\Environment\EnvCatalogConstants;
use Hilos\Environment\EnvCatalogStub;
use PHPUnit\Framework\TestCase;

/**
 * Holds the command channel to the loopback address for a node that declared none.
 *
 * Live admin:grant, admin:revoke and admin:create ride this channel, and the environment gate
 * lets them through - it judges the environment, not the caller, and those commands are not
 * test-only. Binding the port is therefore the thing that keeps a stranger away, so a node
 * that never named an address must not end up listening on every interface by default.
 *
 * The value is checked rather than left to review because it reads as an inconvenience: a
 * later pass would return it to 0.0.0.0 "so docker works" without knowing what it guards.
 * Docker does not need it - every demo stack names COMMAND_HOST for its daemon service, and
 * DemoStackComposeGuardTest is what makes sure it keeps doing so.
 *
 * @see DemoStackComposeGuardTest for the guard that keeps the stacks explicit
 */
final class CommandChannelDefaultTest extends TestCase
{
    /** The address a node binds its command channel to when it declares none. */
    private const string EXPECTED_DEFAULT = '127.0.0.1';

    /**
     * A node that declares no command host listens on the loopback address alone.
     */
    public function testCommandHostDefaultsToLoopback(): void
    {
        $entry = EnvCatalogStub::getCatalog()[EnvConstants::COMMAND_HOST->name];

        $this->assertSame(
            self::EXPECTED_DEFAULT,
            $entry[EnvCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE],
            'the command channel would accept live admin commands from every interface by default'
        );
    }
}
