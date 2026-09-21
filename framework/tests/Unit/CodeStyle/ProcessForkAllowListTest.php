<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\CodeStyle;

use Hilos\Tests\CodeStyle\Rule\ProcessForkRule;
use PHPUnit\Framework\TestCase;

/**
 * Pins the process fork allow-list contract on synthetic records: empty today, and
 * every allowed entry must name the leaf ticket where the owner allowed the fork.
 */
final class ProcessForkAllowListTest extends TestCase
{
    public function testEmptyAllowListIsAccepted(): void
    {
        $this->assertSame([], ProcessForkRule::allowListProblems(ProcessForkRule::ALLOWED_FORKS));
    }

    public function testEntryWithLeafAndReasonIsAccepted(): void
    {
        $this->assertSame(
            [],
            ProcessForkRule::allowListProblems([
                'framework/tests/Unit/FooTest.php' => 'HIL-1234 — the child has to outlive the parent',
            ]),
        );
    }

    public function testEntryWithoutLeafIsRejected(): void
    {
        $this->assertSame(
            ['allow-list entry "framework/tests/Unit/FooTest.php" names no leaf where the owner allowed the fork: needed for a live child'],
            ProcessForkRule::allowListProblems([
                'framework/tests/Unit/FooTest.php' => 'needed for a live child',
            ]),
        );
    }

    public function testEntryWithoutReasonIsRejected(): void
    {
        $this->assertSame(
            ['allow-list entry "framework/tests/Unit/FooTest.php" gives no reason: HIL-1234 — '],
            ProcessForkRule::allowListProblems([
                'framework/tests/Unit/FooTest.php' => 'HIL-1234 — ',
            ]),
        );
    }

    public function testKeyThatIsNoPhpFileIsRejected(): void
    {
        $this->assertSame(
            ['allow-list key "framework/tests/Unit" is no PHP file path'],
            ProcessForkRule::allowListProblems([
                'framework/tests/Unit' => 'HIL-1234 — the child has to outlive the parent',
            ]),
        );
    }
}
