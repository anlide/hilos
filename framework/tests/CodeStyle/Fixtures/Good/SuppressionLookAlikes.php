<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good;

use OutOfBoundsException;

/**
 * Negative sample: ERROR-SUPPRESSION reads tokens, so `@` in a docblock tag, in a
 * string literal or in front of an attribute is not a suppression at all, and a
 * properly marked call is legal by the rule itself.
 *
 * The marked call is a class-D degrade rather than the `fopen` it used to be: FS-SEAM
 * reports an open outside the seam whatever its marker says, and a fixture written to
 * prove what is NOT a suppression must not carry a real hit of another rule.
 */
final class SuppressionLookAlikes
{
    /**
     * @param string $path Path of a file that may be absent
     * @return array<int, string> Text that merely mentions suppression
     * @throws OutOfBoundsException never thrown here; the tag is a look-alike
     */
    #[SampleAttribute]
    public function describe(string $path): array
    {
        // warning-suppressed: a sidecar that is not there has no size, the row below reads that as unknown
        $size = @filesize($path . '.meta');

        return [
            '@unlink($path)',
            "@file_get_contents({$path})",
            $size === false ? 'unknown' : (string)$size,
        ];
    }
}
