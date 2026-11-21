<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad;

use PHPUnit\Framework\TestCase;

// Комментарий к списку.
/* Первый блок. */ /* Второй блок на той же строке. */
/**
 * Первая строка.
 * Вторая строка.
 */
final class CommentLanguageSamples extends TestCase
{
    // One Cyrillic letter here: я.
    // FIXME(F-121, 2026-11-21): на границе.
    /** FIXME(F-121, 31.10.2026): старая метка.
     * Следующая строка требует перевода. */
    public function parked(): void
    {
        $this->markTestSkipped(
            'FIXME(F-121, 2026-11-22): explanation follows'
            /* Примечание между литералами. */
            . ' · пояснение',
        );
        $this->markTestSkipped("FIXME(F-122): нет даты");
        $this->markTestSkipped('FIXME(F-123, 2026-02-30): невозможная дата
            и продолжение на следующей строке');
    }
}
