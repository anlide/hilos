<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Daemon\Cron\CronRule;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the public expression check of the cron rule (HIL-760).
 *
 * Locks the promise the check exists for: an expression it accepts is one {@see CronRule} can run
 * field by field, so counting fields is no longer a second, more forgiving notion of validity —
 * "0 3 * * abc" carries five fields and never fires.
 */
final class CronRuleValidationTest extends TestCase
{
    public function testAcceptsTheExpressionShapesTheRuleRuns(): void
    {
        $this->assertTrue(CronRule::isValidExpression('* * * * *'));
        $this->assertTrue(CronRule::isValidExpression('0 3 * * *'));
        $this->assertTrue(CronRule::isValidExpression('*/5 * * * *'));
        $this->assertTrue(CronRule::isValidExpression('0 0-6/2 1-15 1,6,12 0-6'));
        $this->assertTrue(CronRule::isValidExpression('  0 3 * * *  '));
    }

    public function testRejectsGarbageInsideAFieldEvenWhenTheCountIsRight(): void
    {
        $this->assertFalse(CronRule::isValidExpression('0 3 * * abc'));
        $this->assertFalse(CronRule::isValidExpression('every 3 * * *'));
        $this->assertFalse(CronRule::isValidExpression('0 3 * * 1,x'));
        $this->assertFalse(CronRule::isValidExpression('*/0 * * * *'));
        $this->assertFalse(CronRule::isValidExpression('*/x * * * *'));
        $this->assertFalse(CronRule::isValidExpression('5-1 * * * *'));
    }

    public function testRejectsValuesOutsideTheFieldBounds(): void
    {
        $this->assertFalse(CronRule::isValidExpression('60 * * * *'));
        $this->assertFalse(CronRule::isValidExpression('* 24 * * *'));
        $this->assertFalse(CronRule::isValidExpression('* * 0 * *'));
        $this->assertFalse(CronRule::isValidExpression('* * * 13 *'));
        $this->assertFalse(CronRule::isValidExpression('* * * * 7'));
    }

    public function testRejectsAnythingButFiveFields(): void
    {
        $this->assertFalse(CronRule::isValidExpression(''));
        $this->assertFalse(CronRule::isValidExpression('   '));
        $this->assertFalse(CronRule::isValidExpression('0 3 * *'));
        $this->assertFalse(CronRule::isValidExpression('0 3 * * * *'));
        // Double space splits into six fields, which is exactly what the rule itself would see.
        $this->assertFalse(CronRule::isValidExpression('0  3 * * *'));
    }

    public function testAcceptedExpressionIsOneTheRuleCanFireOn(): void
    {
        $this->assertTrue(CronRule::isValidExpression('* * * * *'));

        $rule = new CronRule('validation-test', '* * * * *');
        // The rule refuses to run twice in one minute, so wind its baseline back a minute.
        $rule->lastRun -= 1.0;

        $this->assertTrue($rule->shouldRun());
    }

    public function testRejectedExpressionIsOneTheRuleNeverFiresOn(): void
    {
        $this->assertFalse(CronRule::isValidExpression('* * * * abc'));

        $rule = new CronRule('validation-test', '* * * * abc');
        $rule->lastRun -= 1.0;

        $this->assertFalse($rule->shouldRun());
    }
}
