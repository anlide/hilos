<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\CliOptionsTree;

use Hilos\Tests\CodeStyle\Fixtures\CliOptionsTree\Foreign\ScopeNames;

/**
 * Reads a literal, a constant of its own and a constant of another class, each
 * declared. A spare name is declared and never read, and a copy of the array is
 * read under another variable: neither is a finding.
 */
final class QuietCommand implements CommandInterface
{
    /** @var list<string> Option names this command reads */
    public const array OPTIONS = [
        'window',
        self::OPTION_FACETS,
        ScopeNames::SCOPE,
        'spare',
    ];

    /** Option naming the facet list. */
    private const string OPTION_FACETS = 'facets';

    /**
     * @param array<string, mixed> $options Parsed options
     * @param list<string> $args Positional arguments
     * @return int Exit code
     */
    public function execute(array $options, array $args): int
    {
        $window = $options['window'] ?? '0';
        $facets = isset($options[self::OPTION_FACETS]) ? $options[self::OPTION_FACETS] : $window;
        $scopeGiven = array_key_exists(ScopeNames::SCOPE, $options);
        $copied = $options;
        $ignored = $copied['nope'] ?? '0';

        return $this->run($options, $args) + ($scopeGiven ? 1 : 0) + strlen((string)$facets) + strlen((string)$ignored);
    }

    /**
     * @param array<string, mixed> $options Parsed options
     * @param list<string> $args Positional arguments
     * @return int Exit code
     */
    private function run(array $options, array $args): int
    {
        return count($args);
    }
}
