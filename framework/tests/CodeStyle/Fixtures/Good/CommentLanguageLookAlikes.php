<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good;

// English — Español → English · Español.
// FIXME(F-121, 2026-11-20): старая метка.
/* FIXME(F-122, 31.10.2026): закрыть позже. */
$example = 'Кириллица в данных';
$example = <<<TEXT
FIXME(F-121, 2026-11-21): текст в heredoc.
TEXT;
$oldLabel = 'FIXME(F-121, 2026-11-20): waiting'
    . ' · чужой · попытка 1';
$splitDate = "FIXME(F-122, " . "31.10.2026): старая метка";
$example = 'FIXME(F-123, 2026-11-21): in English' . $example . ' · данные';
