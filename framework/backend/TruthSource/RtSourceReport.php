<?php

declare(strict_types=1);

namespace Hilos\TruthSource;

/** Immutable scalar values of one worker's existing per-agent source report. */
final readonly class RtSourceReport
{
    /**
     * @param string $agentId Reporting agent
     * @param list<string> $collections Declared collection order, including duplicates
     * @param list<string> $partial Collections short of an operation
     * @param array<string, list<string>> $keys Named row scopes in original map order
     * @param array<string, string> $sets Set scopes in original map order
     */
    public function __construct(
        public string $agentId,
        public array $collections,
        public array $partial,
        public array $keys,
        public array $sets,
    ) {
    }
}
