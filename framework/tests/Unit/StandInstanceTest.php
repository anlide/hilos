<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/stand-instance.php';

/**
 * The environment of a stand instance: every instance lands on networks of its own, none of them
 * a default stand's, and a number that names no instance is refused rather than mapped somewhere.
 *
 * Whether the compose files really read these variables is not answerable without docker; the
 * full run answers it every time it raises a stand.
 *
 * The file under test is a plain script rather than a class, so it is required by path, the same
 * way `scripts/test-stands.php` requires it.
 */
final class StandInstanceTest extends TestCase
{
    public function testEveryInstanceHasNetworksOfItsOwn(): void
    {
        $defaults = [];
        $variables = [];
        foreach (STAND_INSTANCE_BLOCKS as $stands) {
            foreach ($stands as $variable => $octet) {
                $variables[] = $variable;
                $defaults[] = $octet;
            }
        }
        $this->assertSame(19, count($variables));
        $this->assertSame($variables, array_values(array_unique($variables)));
        $this->assertSame($defaults, array_values(array_unique($defaults)));

        $taken = $defaults;
        $lowestDefault = min($defaults);
        for ($instance = STAND_INSTANCE_MIN; $instance <= STAND_INSTANCE_MAX; $instance++) {
            $environment = standInstanceEnvironment($instance);
            $networks = array_values(array_diff(
                array_keys($environment),
                [STAND_INSTANCE_SUFFIX_VAR],
                STAND_INSTANCE_PORT_VARS,
            ));
            $this->assertSame($variables, $networks);
            foreach ($variables as $variable) {
                $octet = (int) $environment[$variable];
                $this->assertNotContains($octet, $taken, "instance {$instance} reuses octet {$octet} for {$variable}");
                $this->assertGreaterThanOrEqual(1, $octet);
                $this->assertLessThan($lowestDefault, $octet);
                $taken[] = $octet;
            }
        }
    }

    public function testAnInstanceSuffixesItsNamesAndFreesItsPorts(): void
    {
        $environment = standInstanceEnvironment(2);

        $this->assertSame('-s2', $environment[STAND_INSTANCE_SUFFIX_VAR]);
        foreach (STAND_INSTANCE_PORT_VARS as $variable) {
            $this->assertSame(STAND_INSTANCE_ANY_PORT, $environment[$variable]);
        }
    }

    public function testANumberOutsideTheRangeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        standInstanceEnvironment(STAND_INSTANCE_MAX + 1);
    }

    /**
     * These are the octets the first block held before the second block, and no later block
     * shifts them.
     */
    public function testTheOctetsInstancesHoldDoNotMove(): void
    {
        $this->assertSame('30', standInstanceEnvironment(1)['HILOS_STAND_NET_CHAT']);
        $this->assertSame('49', standInstanceEnvironment(2)['HILOS_STAND_NET_FRAMEWORK']);
        $this->assertSame('50', standInstanceEnvironment(3)['HILOS_STAND_NET_CHAT']);
        $this->assertSame('69', standInstanceEnvironment(4)['HILOS_STAND_NET_FRAMEWORK']);
        $this->assertSame('119', standInstanceEnvironment(9)['HILOS_STAND_NET_FRAMEWORK']);
    }

    public function testTheSixPlannedStandsTakeTheSecondBlock(): void
    {
        $instance1 = standInstanceEnvironment(1);
        $this->assertSame('120', $instance1['HILOS_STAND_NET_PMD']);
        $this->assertSame('125', $instance1['HILOS_STAND_NET_CONCERT_TICKETS']);

        $instance9 = standInstanceEnvironment(9);
        $this->assertSame('168', $instance9['HILOS_STAND_NET_PMD']);
        $this->assertSame('173', $instance9['HILOS_STAND_NET_CONCERT_TICKETS']);
    }

    /** The three cluster stands that open the third block start at octet 3 and end at 29. */
    public function testTheThreeNewClusterStandsTakeTheThirdBlock(): void
    {
        $instance1 = standInstanceEnvironment(1);
        $this->assertSame('3', $instance1['HILOS_STAND_NET_ROBLOX_GAME_SERVER_CLUSTER']);
        $this->assertSame('5', $instance1['HILOS_STAND_NET_CONCERT_TICKETS_CLUSTER']);

        $instance9 = standInstanceEnvironment(9);
        $this->assertSame('27', $instance9['HILOS_STAND_NET_ROBLOX_GAME_SERVER_CLUSTER']);
        $this->assertSame('29', $instance9['HILOS_STAND_NET_CONCERT_TICKETS_CLUSTER']);
    }

    /**
     * The cluster runner hands its container a fixed list of variables, so a cluster network
     * missing from that list would leave the stand on its default octet, inside every instance.
     */
    public function testTheClusterRunnerForwardsEveryClusterNetwork(): void
    {
        $runner = file_get_contents(__DIR__ . '/../../docker/cluster/run');
        $this->assertIsString($runner, 'framework/docker/cluster/run is unreadable');

        $forwarded = 0;
        foreach (STAND_INSTANCE_BLOCKS as $stands) {
            foreach (array_keys($stands) as $variable) {
                if (!str_ends_with($variable, '_CLUSTER')) {
                    continue;
                }

                $forwarded++;
                $this->assertStringContainsString(
                    '--env "' . $variable . '=$' . $variable . '"',
                    $runner,
                    "framework/docker/cluster/run does not forward {$variable}",
                );
            }
        }
        $this->assertGreaterThan(0, $forwarded, 'no cluster network was found in the octet table');
    }
}
