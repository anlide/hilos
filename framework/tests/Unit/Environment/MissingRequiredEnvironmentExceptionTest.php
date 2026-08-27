<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Environment;

use Hilos\Environment\Exception\MissingRequiredEnvironmentException;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Holds the startup refusal to the shape an operator reads it in.
 *
 * The message is the whole feature: it is what appears in `docker logs` when a node will not
 * come up, and the operator copies the names out of it into an .env. So the header has to say
 * which installation refused, and every missing name has to stand on its own line - a list
 * folded into one sentence is exactly what a person mis-reads at the end of a setup.
 */
final class MissingRequiredEnvironmentExceptionTest extends TestCase
{
    public function testMessageHeadsWithTheFacadeAndListsOneNamePerLine(): void
    {
        $exception = MissingRequiredEnvironmentException::forNames(
            Hilos::class,
            ['HTTP_STATUS_PORT', 'DAEMON_LOG_FILE', 'APP_ENV'],
        );

        $this->assertSame(
            Hilos::class . " refuses to start, required environment values are missing:\n"
            . "- HTTP_STATUS_PORT\n"
            . "- DAEMON_LOG_FILE\n"
            . '- APP_ENV',
            $exception->getMessage(),
        );
    }

    public function testSingleMissingNameStillCarriesTheListPrefix(): void
    {
        $exception = MissingRequiredEnvironmentException::forNames(Hilos::class, ['APP_ENV']);

        $this->assertSame(
            Hilos::class . " refuses to start, required environment values are missing:\n- APP_ENV",
            $exception->getMessage(),
        );
    }
}
