<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database;

use Hilos\Constants\EnvConstants;
use Hilos\Database\MigrationClaimHolder;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * The name a process writes into the schema rollout claim, and which of them may take its row back (HIL-1228).
 *
 * A node's start is named after the node alone, because the name has to survive its own restart to
 * be recognised; every other process adds its pid, because two of them alive on one host are two
 * holders. Only the start may take back a row with its own name.
 */
final class MigrationClaimHolderTest extends TestCase
{
    /** @var string Node id a clustered case configures */
    private const string NODE = 'node-3';

    private ?EnvAccessor $previousEnv = null;

    private string|false $previousNode = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousEnv = Hilos::$env;
        $this->previousNode = getenv(EnvConstants::CLUSTER_NODE_ID->name);
        Hilos::$env = new EnvAccessor();
    }

    protected function tearDown(): void
    {
        if ($this->previousNode === false) {
            putenv(EnvConstants::CLUSTER_NODE_ID->name);
        } else {
            putenv(EnvConstants::CLUSTER_NODE_ID->name . '=' . $this->previousNode);
        }
        Hilos::$env = $this->previousEnv;

        parent::tearDown();
    }

    public function testANodeStartIsNamedAfterTheClusterNodeAndMayTakeItsRowBack(): void
    {
        putenv(EnvConstants::CLUSTER_NODE_ID->name . '=' . self::NODE);

        $holder = MigrationClaimHolder::nodeStart();

        $this->assertSame(self::NODE, $holder->name);
        $this->assertTrue($holder->mayTakeBack);
    }

    public function testANodeOutsideAClusterIsNamedAfterItsHost(): void
    {
        putenv(EnvConstants::CLUSTER_NODE_ID->name . '=');

        $this->assertSame((string)gethostname(), MigrationClaimHolder::nodeStart()->name);
    }

    public function testASurroundingBlankDoesNotCountAsANodeName(): void
    {
        putenv(EnvConstants::CLUSTER_NODE_ID->name . '=  ');

        $this->assertSame((string)gethostname(), MigrationClaimHolder::nodeStart()->name);
    }

    public function testAnyOtherProcessAddsItsPidAndNeverTakesItsRowBack(): void
    {
        putenv(EnvConstants::CLUSTER_NODE_ID->name . '=' . self::NODE);

        $holder = MigrationClaimHolder::process();

        $this->assertSame(self::NODE . ':' . getmypid(), $holder->name);
        $this->assertFalse($holder->mayTakeBack);
    }
}
