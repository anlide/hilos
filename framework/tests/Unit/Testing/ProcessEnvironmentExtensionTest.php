<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Testing;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

/**
 * The extension puts back what a test changed in the process environment.
 * The second case depends on the first, so the pair stays ordered when the suite runs in reverse.
 */
final class ProcessEnvironmentExtensionTest extends TestCase
{
    private const string PROBE = 'HILOS_PROCESS_ENVIRONMENT_PROBE';

    /** Erases APP_ENV and sets the probe the following case expects to be gone. */
    public function testErasesAppEnvAndSetsTheProbe(): void
    {
        putenv('APP_ENV');
        putenv(self::PROBE . '=probe');

        self::assertFalse(getenv('APP_ENV'));
        self::assertSame('probe', getenv(self::PROBE));
    }

    /** APP_ENV is test again and the probe is gone. */
    #[Depends('testErasesAppEnvAndSetsTheProbe')]
    public function testExtensionRestoresAppEnvAndRemovesTheProbe(): void
    {
        $reason = 'The process environment extension is not registered or did not restore the environment';

        self::assertSame('test', getenv('APP_ENV'), $reason);
        self::assertFalse(getenv(self::PROBE), $reason);
    }
}
