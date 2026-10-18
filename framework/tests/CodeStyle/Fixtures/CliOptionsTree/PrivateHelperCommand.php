<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\CliOptionsTree;

/**
 * Reads an option from a private helper. The helper is part of the command, so
 * the read is judged, and handing the variable to that helper is not.
 */
final class PrivateHelperCommand implements CommandInterface
{
    /**
     * @param array<string, mixed> $options Parsed options
     * @param list<string> $args Positional arguments
     * @return int Exit code
     */
    public function execute(array $options, array $args): int
    {
        return self::resolveInstantPayload($options) + count($args);
    }

    /**
     * @param array<string, mixed> $options Parsed options
     * @return int Exit code
     */
    private static function resolveInstantPayload(array $options): int
    {
        $given = array_key_exists('instant', $options);

        return $given ? 1 : 0;
    }
}
