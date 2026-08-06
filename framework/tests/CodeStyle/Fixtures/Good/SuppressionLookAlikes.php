<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good;

use OutOfBoundsException;

/**
 * Negative sample: ERROR-SUPPRESSION reads tokens, so `@` in a docblock tag, in a
 * string literal or in front of an attribute is not a suppression at all, and a
 * properly marked call is legal by the rule itself.
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
        // warning-suppressed: the handle is checked right below instead
        $handle = @fopen($path, 'rb');

        return [
            '@unlink($path)',
            "@file_get_contents({$path})",
            $handle === false ? 'unreadable' : 'open',
        ];
    }
}
