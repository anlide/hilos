<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good;

final class TestRollbackScopeSamples
{
    public function beginRollbackScopeForTest(): void
    {
        $description = 'Database::beginRollbackScopeForTest()';
        // Database::beginRollbackScopeForTest();
        strlen($description);
    }
}
