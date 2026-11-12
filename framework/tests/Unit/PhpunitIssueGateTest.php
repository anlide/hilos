<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit test for the issue gate every PHPUnit configuration in the repository must carry.
 *
 * A deprecation, a notice or a warning is reported by PHPUnit but does not fail the run unless
 * the configuration asks it to, so a run counting them stays green and the count is read by
 * nobody. That is how a real defect stayed in the tree until it cost a wrong session (HIL-673).
 * The three attributes turn the count into a verdict, and this test keeps them on: a suite that
 * loses them, or a demo born without them, is caught here instead of going quiet.
 * The same list requires the process environment extension.
 */
final class PhpunitIssueGateTest extends TestCase
{
    /**
     * The attributes a configuration is required to set, all of them to "true".
     */
    private const REQUIRED_ATTRIBUTES = ['failOnDeprecation', 'failOnNotice', 'failOnWarning'];

    public function testEveryPhpunitConfigFailsOnIssues(): void
    {
        foreach ($this->phpunitConfigs() as $config) {
            $this->assertFileExists($config);

            $xml = simplexml_load_file($config);
            $this->assertNotFalse($xml, "cannot parse {$config}");

            foreach (self::REQUIRED_ATTRIBUTES as $attribute) {
                $value = $xml[$attribute];
                $this->assertNotNull($value, "{$config} does not set {$attribute}");
                $this->assertSame('true', (string)$value, "{$config} sets {$attribute} to a value other than true");
            }
        }
    }

    /**
     * Each watched configuration registers the process environment extension.
     */
    public function testEveryPhpunitConfigRestoresTheProcessEnvironment(): void
    {
        foreach ($this->phpunitConfigs() as $config) {
            $this->assertFileExists($config);

            $xml = simplexml_load_file($config);
            $this->assertNotFalse($xml, "cannot parse {$config}");

            $requiredClass = 'Hilos\Testing\ProcessEnvironment\ProcessEnvironmentExtension';
            $registeredClass = null;
            foreach ($xml->extensions->bootstrap as $bootstrap) {
                $class = $bootstrap['class'];
                if ($class !== null && (string)$class === $requiredClass) {
                    $registeredClass = (string)$class;
                }
            }

            $this->assertSame(
                $requiredClass,
                $registeredClass,
                "{$config} does not register the process environment extension",
            );
        }
    }

    /**
     * The named PHPUnit configurations this gate watches.
     *
     * Spelled out rather than globbed: a demo added without its gate must fail here, and a
     * glob would simply not see it. A new demo is added to this list.
     *
     * @return list<string> Absolute paths of the watched configurations
     */
    private function phpunitConfigs(): array
    {
        $root = dirname(__DIR__, 3);

        return [
            $root . '/framework/tests/phpunit.xml',
            $root . '/demo/binance-btc-tracker/tests/phpunit.xml',
            $root . '/demo/chat/tests/phpunit.xml',
            $root . '/demo/concert-tickets/tests/phpunit.xml',
            $root . '/demo/ecommerce-shop/tests/phpunit.xml',
            $root . '/demo/file-gallery-manager/tests/phpunit.xml',
            $root . '/demo/online-testing/tests/phpunit.xml',
            $root . '/demo/pmd/tests/phpunit.xml',
            $root . '/demo/polls/tests/phpunit.xml',
            $root . '/demo/preferans/tests/phpunit.xml',
            $root . '/demo/roblox-game-server/tests/phpunit.xml',
            $root . '/demo/solar-system-model/tests/phpunit.xml',
            $root . '/demo/tasks/tests/phpunit.xml',
        ];
    }
}
