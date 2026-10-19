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
        $taken = array_values(STAND_INSTANCE_DEFAULT_OCTETS);
        for ($instance = STAND_INSTANCE_MIN; $instance <= STAND_INSTANCE_MAX; $instance++) {
            $environment = standInstanceEnvironment($instance);
            foreach (array_keys(STAND_INSTANCE_DEFAULT_OCTETS) as $variable) {
                $octet = (int) $environment[$variable];
                $this->assertNotContains($octet, $taken, "instance {$instance} reuses octet {$octet} for {$variable}");
                $this->assertLessThan(min(STAND_INSTANCE_DEFAULT_OCTETS), $octet);
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
}
