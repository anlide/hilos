<?php

declare(strict_types=1);

namespace Hilos\Database\Migration;

use Hilos\Database\DatabaseException;
use Hilos\Hilos;

/** Explicit source target; reading its facade metadata never changes the active application. */
final readonly class FrameworkMigrationProject
{
    public string $root;

    /**
     * @param string $root Source track directory, such as backend/Database/Migration/Schema
     * @param class-string<Hilos> $facade Project facade declaration
     * @throws DatabaseException When the track path is empty or the facade is not a Hilos class
     */
    public function __construct(string $root, public string $facade)
    {
        if ($root === '' || !is_a($facade, Hilos::class, true)) {
            throw new DatabaseException('A framework migration target needs a source track and a Hilos facade');
        }
        $resolved = realpath($root);
        $this->root = $resolved === false ? rtrim($root, '/\\') : $resolved;
    }
}
