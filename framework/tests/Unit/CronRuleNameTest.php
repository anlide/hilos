<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Daemon\Cron\CronRuleOrigin;
use Hilos\Core\Daemon\Cron\Exception\CronRuleNameException;
use PHPUnit\Framework\TestCase;

/**
 * The name of a cron rule is accepted only for the origin it was declared with.
 *
 * Project is the default. A project name must not start with the reserved prefix, and a framework
 * name must. The refusal names the rule, the prefix and the origin the name requires, and no rule
 * is created.
 */
final class CronRuleNameTest extends TestCase
{
    public function testProjectOriginIsTheDefaultForAnOrdinaryName(): void
    {
        $rule = new CronRule('cleanup_history', '*/30 * * * *');

        $this->assertSame('cleanup_history', $rule->name);
        $this->assertSame('*/30 * * * *', $rule->expression);
    }

    public function testDefaultProjectOriginRejectsReservedPrefix(): void
    {
        $this->assertReservedNameRefused(static fn () => new CronRule('hilos_cleanup_history', '* * * * *'));
    }

    public function testExplicitProjectOriginRejectsReservedPrefix(): void
    {
        $this->assertReservedNameRefused(
            static fn () => new CronRule('hilos_cleanup_history', '* * * * *', CronRuleOrigin::PROJECT),
        );
    }

    public function testFrameworkOriginAcceptsReservedPrefix(): void
    {
        $rule = new CronRule('hilos_cleanup_history', '* * * * *', CronRuleOrigin::FRAMEWORK);

        $this->assertSame('hilos_cleanup_history', $rule->name);
        $this->assertSame('* * * * *', $rule->expression);
    }

    public function testFrameworkOriginRejectsNameWithoutReservedPrefix(): void
    {
        try {
            new CronRule('cleanup_history', '* * * * *', CronRuleOrigin::FRAMEWORK);
            $this->fail('A framework rule must carry the reserved prefix');
        } catch (CronRuleNameException $exception) {
            $this->assertStringContainsString('cleanup_history', $exception->getMessage());
            $this->assertStringContainsString(CronRule::FRAMEWORK_NAME_PREFIX, $exception->getMessage());
            $this->assertStringContainsString('project origin is required', $exception->getMessage());
        }
    }

    /** @param callable(): CronRule $create Construction that must be refused */
    private function assertReservedNameRefused(callable $create): void
    {
        try {
            $create();
            $this->fail('A project rule must not use the reserved prefix');
        } catch (CronRuleNameException $exception) {
            $this->assertStringContainsString('hilos_cleanup_history', $exception->getMessage());
            $this->assertStringContainsString(CronRule::FRAMEWORK_NAME_PREFIX, $exception->getMessage());
            $this->assertStringContainsString('framework origin is required', $exception->getMessage());
        }
    }
}
