<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\CodeStyle;

use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\Rule\ErrorSuppressionRule;
use Hilos\Tests\CodeStyle\Rule\PhpDocFqnRule;
use Hilos\Tests\CodeStyle\Rule\RtStateReachRule;
use Hilos\Tests\CodeStyle\SourceScanner;
use PHPUnit\Framework\TestCase;

/**
 * Runs the code-style rules over the deliberately broken fixtures and pins the
 * exact expected report — both what must be caught and what must stay silent.
 *
 * The guard test leaves the fixture directory out of its scan, so this test is the
 * only thing that proves the rules still fire.
 */
final class RuleFixtureTest extends TestCase
{
    public function testRulesReportExactlyTheSeededViolations(): void
    {
        $this->assertSame(
            [
                'ERROR-SUPPRESSION Bad/ErrorSuppressionSamples.php:20 — @ silences a warning with no '
                    . '`// warning-suppressed:` marker on the line above '
                    . '(see docs/agents/code-style/error-suppression.md)',
                'ERROR-SUPPRESSION Bad/ErrorSuppressionSamples.php:22 — @ silences a warning with no '
                    . '`// warning-suppressed:` marker on the line above '
                    . '(see docs/agents/code-style/error-suppression.md)',
                'ERROR-SUPPRESSION Bad/ErrorSuppressionSamples.php:25 — the `// warning-suppressed:` marker '
                    . 'above the call names no reason (see docs/agents/code-style/error-suppression.md)',
                'ERROR-SUPPRESSION Bad/ErrorSuppressionSamples.php:29 — @ silences a warning with no '
                    . '`// warning-suppressed:` marker on the line above '
                    . '(see docs/agents/code-style/error-suppression.md)',
                'PHPDOC-FQN Bad/PhpDocFqnSamples.php:13 — @property-read references \Hilos\Core\Hilos '
                    . 'instead of an imported short name (see docs/agents/code-style/phpdoc.md)',
                'PHPDOC-FQN Bad/PhpDocFqnSamples.php:14 — @method references \Hilos\Tests\CodeStyle\Violation '
                    . 'instead of an imported short name (see docs/agents/code-style/phpdoc.md)',
                'PHPDOC-FQN Bad/PhpDocFqnSamples.php:14 — @method references \SplFileInfo '
                    . 'instead of an imported short name (see docs/agents/code-style/phpdoc.md)',
                'PHPDOC-FQN Bad/PhpDocFqnSamples.php:15 — @extends references \Hilos\Tests\CodeStyle\Baseline '
                    . 'instead of an imported short name (see docs/agents/code-style/phpdoc.md)',
                'PHPDOC-FQN Bad/PhpDocFqnSamples.php:15 — @extends references \SplFileInfo '
                    . 'instead of an imported short name (see docs/agents/code-style/phpdoc.md)',
                'PHPDOC-FQN Bad/PhpDocFqnSamples.php:16 — @implements references '
                    . '\Hilos\Tests\CodeStyle\CodeStyleRule '
                    . 'instead of an imported short name (see docs/agents/code-style/phpdoc.md)',
                'PHPDOC-FQN Bad/PhpDocFqnSamples.php:21 — {@see} references \Hilos\Tests\CodeStyle\SourceScanner '
                    . 'instead of an imported short name (see docs/agents/code-style/phpdoc.md)',
                'PHPDOC-FQN Bad/PhpDocFqnSamples.php:23 — @var references \SplFileInfo '
                    . 'instead of an imported short name (see docs/agents/code-style/phpdoc.md)',
                'PHPDOC-FQN Bad/PhpDocFqnSamples.php:28 — @param references \DateTimeImmutable '
                    . 'instead of an imported short name (see docs/agents/code-style/phpdoc.md)',
                'PHPDOC-FQN Bad/PhpDocFqnSamples.php:29 — @return references \Hilos\Tests\CodeStyle\Violation '
                    . 'instead of an imported short name (see docs/agents/code-style/phpdoc.md)',
                'PHPDOC-FQN Bad/PhpDocFqnSamples.php:30 — @throws references \OutOfBoundsException '
                    . 'instead of an imported short name (see docs/agents/code-style/phpdoc.md)',
                'RT-STATE-REACH Bad/RtStateReach.php:20 — getStateCollection() reaches backing RT state '
                    . 'outside Database/ and Runtime/ (see docs/agents/runtime/rt-state.md)',
                'RT-STATE-REACH Bad/RtStateReach.php:21 — getStateItem() reaches backing RT state '
                    . 'outside Database/ and Runtime/ (see docs/agents/runtime/rt-state.md)',
                'RT-STATE-REACH Bad/RtStateReach.php:22 — getStateItem() reaches backing RT state '
                    . 'outside Database/ and Runtime/ (see docs/agents/runtime/rt-state.md)',
                'RT-STATE-REACH Bad/RtStateReach.php:23 — $this->stateCollection reaches backing RT state '
                    . 'outside Database/ and Runtime/ (see docs/agents/runtime/rt-state.md)',
            ],
            $this->reportFixtureViolations(),
            'Fixture report drifted: a rule either stopped catching a seeded case or started '
                . 'reporting a legitimate one.',
        );
    }

    /**
     * Scans the fixture tree with the same scanner and rules the guard test uses.
     *
     * @return array<int, string> Reported lines, ordered by file path and then by occurrence
     */
    private function reportFixtureViolations(): array
    {
        $scanner = new SourceScanner(dirname(__DIR__, 2) . '/CodeStyle/Fixtures');
        $sources = [];
        foreach ($scanner->files() as $file) {
            $sources[$scanner->relativePath($file)] = (string)file_get_contents($file->getPathname());
        }
        ksort($sources);

        $lines = [];
        foreach ($sources as $relativePath => $contents) {
            $tokens = token_get_all($contents);
            foreach ($this->rules() as $rule) {
                foreach ($rule->check($relativePath, $tokens) as $violation) {
                    $lines[] = $violation->describe($rule->doc());
                }
            }
        }

        return $lines;
    }

    /**
     * @return array<int, CodeStyleRule> Rules under test, in report order
     */
    private function rules(): array
    {
        return [new PhpDocFqnRule(), new RtStateReachRule(), new ErrorSuppressionRule()];
    }
}
