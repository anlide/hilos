<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\CodeStyle;

use Hilos\Tests\CodeStyle\Baseline;
use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\Rule\EmptyStringSentinelRule;
use Hilos\Tests\CodeStyle\Rule\ErrorSuppressionRule;
use Hilos\Tests\CodeStyle\Rule\MagicRepeatRule;
use Hilos\Tests\CodeStyle\Rule\PhpDocFqnRule;
use Hilos\Tests\CodeStyle\Rule\RtStateReachRule;
use Hilos\Tests\CodeStyle\Rule\WireKeyCaseRule;
use Hilos\Tests\CodeStyle\SourceScanner;
use PHPUnit\Framework\TestCase;

/**
 * Runs the machine-checkable code-style rules over the whole monorepo and fails on
 * anything the baseline does not already own.
 *
 * A new demo is covered by the glob, so there is no activation step to forget.
 */
final class CodeStyleGuardTest extends TestCase
{
    /**
     * Paths left out of a scanned root, pinned exactly rather than by directory
     * name. The checker's own fixtures are broken on purpose and are judged by
     * RuleFixtureTest instead.
     *
     * @var array<string, array<int, string>>
     */
    private const array EXCLUDED_PATHS = ['framework/tests' => ['CodeStyle/Fixtures']];

    /**
     * Rules that judge production code only, listed here because the scanned root
     * is known here and nowhere else: a rule receives the path relative to its
     * root, so `framework/tests/Unit/X.php` reaches it as `Unit/X.php` and reads
     * exactly like a backend file. A suite is allowed what production is not —
     * suppressing a warning while it arranges a failure, or repeating the same
     * number in a dozen assertions, where a constant would hide from the reader
     * the very value under test.
     *
     * @var array<int, string>
     */
    private const array BACKEND_ONLY_RULES = [ErrorSuppressionRule::ID, MagicRepeatRule::ID];


    public function testSourcesCarryNoCodeStyleViolationsBeyondTheBaseline(): void
    {
        $reported = $this->reportedViolations();
        $baseline = Baseline::fromText($this->baselineText());

        if (getenv('CODESTYLE_BASELINE_UPDATE') === '1') {
            file_put_contents($this->repositoryRoot() . '/' . Baseline::PATH, $baseline->render($reported));
            $this->fail('Baseline regenerated from the current tree — review the diff before committing it.');
        }

        $this->assertSame(
            [],
            $baseline->reconcile($reported),
            'Code-style rules are checked by machine. Fix the lines below, or — if the debt is old and'
                . ' owned by a leaf — record it in ' . Baseline::PATH . ' (regenerate with'
                . ' CODESTYLE_BASELINE_UPDATE=1):',
        );
    }

    /**
     * @return array<string, array<int, string>> Violation lines keyed by "<rule id> <path from repository root>"
     */
    private function reportedViolations(): array
    {
        $reported = [];

        foreach ($this->scannedRoots() as $root) {
            $rules = $this->rulesFor($root);
            $scanner = new SourceScanner($this->repositoryRoot() . '/' . $root, self::EXCLUDED_PATHS[$root] ?? []);
            foreach ($scanner->files() as $file) {
                $relativePath = $scanner->relativePath($file);
                $tokens = token_get_all((string)file_get_contents($file->getPathname()));
                foreach ($rules as $rule) {
                    foreach ($rule->check($relativePath, $tokens) as $violation) {
                        $reported[$rule->id() . ' ' . $root . '/' . $relativePath][]
                            = $violation->withPathPrefix($root)->describe($rule->doc());
                    }
                }
            }
        }

        return $reported;
    }

    /**
     * Roots are optional: the framework also ships without the demos, and a missing
     * one is not a configuration error. A demo's `data/` directory is a sibling of
     * these roots, so the walk never reaches the root-owned MariaDB files.
     *
     * @return array<int, string> Scanned roots, relative to the repository root
     */
    private function scannedRoots(): array
    {
        $roots = ['framework/backend', 'framework/tests'];
        foreach (glob($this->repositoryRoot() . '/demo/*', GLOB_ONLYDIR) ?: [] as $demo) {
            $roots[] = 'demo/' . basename($demo) . '/backend';
            $roots[] = 'demo/' . basename($demo) . '/tests';
        }

        return $roots;
    }

    /**
     * @return array<int, CodeStyleRule> Rules under the guard, in report order
     */
    private function rules(): array
    {
        return [
            new PhpDocFqnRule(),
            new RtStateReachRule(),
            new ErrorSuppressionRule(),
            new MagicRepeatRule(),
            new EmptyStringSentinelRule(),
            new WireKeyCaseRule(),
        ];
    }

    /**
     * @param string $root Scanned root, relative to the repository root
     * @return array<int, CodeStyleRule> Rules that judge this root, in report order
     */
    private function rulesFor(string $root): array
    {
        if (str_ends_with($root, '/backend')) {
            return $this->rules();
        }

        return array_values(array_filter(
            $this->rules(),
            static fn(CodeStyleRule $rule): bool => !in_array($rule->id(), self::BACKEND_ONLY_RULES, true),
        ));
    }

    /**
     * @return string Baseline contents, empty when the file does not exist yet
     */
    private function baselineText(): string
    {
        $path = $this->repositoryRoot() . '/' . Baseline::PATH;

        return is_file($path) ? (string)file_get_contents($path) : '';
    }

    /**
     * @return string Absolute path of the repository root
     */
    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 4);
    }
}
