<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users;

use Hilos\Users\AccountErasure;
use PHPUnit\Framework\TestCase;

/** The combined project answer for a circle of erased accounts (HIL-1200). */
final class AccountErasureTest extends TestCase
{
    public function testCombinesSharedAndDistinctFamiliesAndKeepsFileOrder(): void
    {
        $first = new AccountErasure(['messages' => 2, 'events' => 1], ['first']);
        $second = new AccountErasure(['messages' => 3, 'attachments' => 4], ['second', 'third']);

        $combined = $first->plus($second);

        self::assertSame(['messages' => 5, 'events' => 1, 'attachments' => 4], $combined->rowsErased);
        self::assertSame(['first', 'second', 'third'], $combined->publishedFiles);
    }

    public function testCombiningTwoEmptyAnswersIsEmpty(): void
    {
        $combined = (new AccountErasure([], []))->plus(new AccountErasure([], []));

        self::assertSame([], $combined->rowsErased);
        self::assertSame([], $combined->publishedFiles);
    }
}
