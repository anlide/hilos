<?php

declare(strict_types=1);

namespace Hilos\Environment;

/** Fresh dotenv files read independently of the accessor's process caches. */
final readonly class EnvFiles
{
    /**
     * @param array<string, string> $env Active .env entries in file order
     * @param array<string, string> $example .env.example entries in file order
     */
    public function __construct(
        public array $env,
        public array $example,
    ) {
    }
}
