<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad;

/**
 * Deliberately broken sample: three lines below run past the limit — one of ordinary
 * code, one of a docblock, one of an interpolated string — so LINE-LENGTH must report
 * each of them once and say how wide it is.
 */
final class LineLengthSamples
{
    /**
     * @param string $host Host the connection was opened to, named here at a length that carries this docblock line well past the hundred and fiftieth column
     * @param int $port Port the connection was opened on
     * @return string One line saying what was reached
     */
    public function describe(string $host, int $port): string
    {
        $trail = str_pad($host, 40, '.') . str_pad((string)$port, 30, '.') . str_pad('reached', 50, '.') . str_pad('answered inside the window', 60, '.');

        return "connected to {$host} on port {$port}, the handshake answered inside the window, and the trail of the attempt reads {$trail} for whoever reads the log";
    }
}
