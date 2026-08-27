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
 */
final class PhpunitIssueGateTest extends TestCase
{
    /**
     * The attributes a configuration is required to set, all of them to "true".
     */
    private const REQUIRED_ATTRIBUTES = ['failOnDeprecation', 'failOnNotice', 'failOnWarning'];

    public function testEveryPhpunitConfigFailsOnIssues(): void
    {
        // The list is spelled out rather than globbed: a demo added without its gate must fail
        // here, and a glob would simply not see it. A sixth demo is added to THIS list.
        $root = dirname(__DIR__, 3);
        $configs = [
            $root . '/framework/tests/phpunit.xml',
            $root . '/demo/chat/tests/phpunit.xml',
            $root . '/demo/cluster/tests/phpunit.xml',
            $root . '/demo/simple-poll/tests/phpunit.xml',
            $root . '/demo/tasks/tests/phpunit.xml',
        ];

        foreach ($configs as $config) {
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
}
