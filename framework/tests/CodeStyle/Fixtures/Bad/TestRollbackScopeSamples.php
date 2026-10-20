<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad;

use Hilos\Database\Database;

final class TestRollbackScopeSamples
{
    public function open(?Database $database): void
    {
        Database::beginRollbackScopeForTest();
        $database->beginRollbackScopeForTest();
        $database?->beginRollbackScopeForTest();
        Database::BEGINROLLBACKSCOPEFORTEST();
    }
}
