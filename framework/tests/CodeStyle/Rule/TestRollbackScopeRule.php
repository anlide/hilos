<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\RootKind;
use Hilos\Tests\CodeStyle\Violation;

/**
 * The rollback-scope door belongs to the harness and technical suites alone.
 * IntegrationTestRule separately closes it inside marked cases and their helpers.
 * Only literal member calls are judged; declarations, strings and dynamic names are not calls here.
 */
final readonly class TestRollbackScopeRule implements CodeStyleRule
{
    public const string ID = 'TEST-ROLLBACK-SCOPE';

    private const string HARNESS = 'framework/backend/Testing/RollbackIntegrationTestCase.php';

    /**
     * @param string $root Scanned root relative to the repository
     * @param RootKind $kind Explicit source kind, independent of directory names
     */
    public function __construct(private string $root, private RootKind $kind)
    {
    }

    /** @return string Stable report id */
    public function id(): string
    {
        return self::ID;
    }

    /** @return string Canonical integration-test contract */
    public function doc(): string
    {
        return 'docs/agents/testing/integration-tests.md';
    }

    /**
     * @param string $relativePath Path relative to the scanned root
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens PHP source tokens
     * @return iterable<Violation> Calls outside the harness and technical test roots
     */
    public function check(string $relativePath, array $tokens): iterable
    {
        if ($this->kind === RootKind::Suite || $this->root . '/' . $relativePath === self::HARNESS) {
            return;
        }
        $tokens = array_values(array_filter($tokens, static fn(string|array $token): bool => !is_array($token)
            || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        foreach ($tokens as $cursor => $token) {
            if (!is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'beginrollbackscopefortest') {
                continue;
            }
            $before = $tokens[$cursor - 1] ?? null;
            if (($tokens[$cursor + 1] ?? null) !== '(' || !is_array($before)
                || !in_array($before[0], [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                continue;
            }
            yield new Violation(self::ID, $relativePath, $token[2],
                'beginRollbackScopeForTest() belongs to the rollback harness or a technical test root');
        }
    }
}
