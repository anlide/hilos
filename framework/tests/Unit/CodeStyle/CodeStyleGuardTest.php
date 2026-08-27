<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\CodeStyle;

use Hilos\Tests\CodeStyle\Baseline;
use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\RootKind;
use Hilos\Tests\CodeStyle\Rule\BlockingResolutionRule;
use Hilos\Tests\CodeStyle\Rule\CodeFqnRule;
use Hilos\Tests\CodeStyle\Rule\EmptyStringSentinelRule;
use Hilos\Tests\CodeStyle\Rule\ErrorSuppressionRule;
use Hilos\Tests\CodeStyle\Rule\FsSeamRule;
use Hilos\Tests\CodeStyle\Rule\LineLengthRule;
use Hilos\Tests\CodeStyle\Rule\MagicRepeatRule;
use Hilos\Tests\CodeStyle\Rule\MalformedInputMarkerRule;
use Hilos\Tests\CodeStyle\Rule\ObjectStoreMutationRule;
use Hilos\Tests\CodeStyle\Rule\PayloadSentinelRule;
use Hilos\Tests\CodeStyle\Rule\PhpDocFqnRule;
use Hilos\Tests\CodeStyle\Rule\RandomSourceRule;
use Hilos\Tests\CodeStyle\Rule\RtStateMutationRule;
use Hilos\Tests\CodeStyle\Rule\RtStateReachRule;
use Hilos\Tests\CodeStyle\Rule\SecretInQueryRule;
use Hilos\Tests\CodeStyle\Rule\ViewWrapperBindingRule;
use Hilos\Tests\CodeStyle\Rule\WireKeyCaseRule;
use Hilos\Tests\CodeStyle\ScannedRoots;
use Hilos\Tests\CodeStyle\SourceScanner;
use Hilos\Tests\CodeStyle\Throws\CrossFileRule;
use Hilos\Tests\CodeStyle\Throws\SourceIndex;
use Hilos\Tests\CodeStyle\Throws\ThrowsPropagationRule;
use PHPUnit\Framework\TestCase;

/**
 * Runs the machine-checkable code-style rules over the whole monorepo and fails on
 * anything the baseline does not already own.
 *
 * Which roots those are, and which rules each of them earns, is declared by
 * {@see ScannedRoots}; what stays here is what only the guard knows — the paths left
 * out of a root and the zone of the rule that is phased in one subsystem at a time.
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
     * The root the empty-string rule judges by a list of segments rather than whole.
     * A framework subsystem is turned on one phase at a time, so the zone below is
     * the part of it already cleaned; every other root is judged entire.
     */
    private const string PHASED_EMPTY_STRING_ROOT = 'framework/backend';

    /**
     * Segments of {@see self::PHASED_EMPTY_STRING_ROOT} the empty-string rule judges,
     * listed here for the same reason {@see RootKind} exists: the rule is handed a
     * path relative to its root and never learns which root that is. The list grows
     * one leaf at a time — turned on at once, the baseline of the root would become
     * a list of exceptions rather than a list of owed work.
     *
     * @var array<int, string>
     */
    private const array PHASED_EMPTY_STRING_ZONE = [
        'Core/Router',
        'Core/Page',
        'Core/Sync',
        'Core/Agent',
        'Core/Analytics',
        'Core/Browser',
        'Core/CLI',
        'Core/Daemon',
        'Core/Feature',
        'Core/Table/DTO',
        'Core/Source',
        'Socket',
        'Cluster/Peer/DTO',
        'API',
        'Auth',
        'Backup',
        'Database',
        'LLM',
        'Log',
        'Mail',
        'Notification',
        'Pages',
        'ProtectedMode',
        'Push',
        'Runtime',
        'Sms',
        'Tables',
        'Utils',
        'Hilos.php',
    ];

    public function testSourcesCarryNoCodeStyleViolationsBeyondTheBaseline(): void
    {
        $reported = $this->reportedViolations();
        $baseline = Baseline::fromText($this->baselineText());

        if (getenv('CODESTYLE_BASELINE_UPDATE') === '1') {
            $update = $baseline->update($reported);
            if ($update->text() !== null) {
                file_put_contents($this->repositoryRoot() . '/' . Baseline::PATH, $update->text());
            }
            $this->fail($update->message());
        }

        $this->assertSame(
            [],
            $baseline->reconcile($reported),
            'Code-style rules are checked by machine. Fix the lines below, or — if the debt is old and'
                . ' owned by a leaf — record it in ' . Baseline::PATH . ' (regenerate with'
                . ' CODESTYLE_BASELINE_UPDATE=1). ' . ThrowsPropagationRule::SCOPE,
        );
    }

    /**
     * @return array<string, array<int, string>> Violation lines keyed by "<rule id> <path from repository root>"
     */
    private function reportedViolations(): array
    {
        $reported = [];

        foreach (ScannedRoots::all($this->repositoryRoot()) as $root => $kind) {
            $rules = $this->rulesFor($root, $kind);
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

        return [...$reported, ...$this->reportedCrossFileViolations()];
    }

    /**
     * The second source of violations, feeding the same report and therefore the same
     * baseline file. It gets no guard test of its own on purpose: the baseline is one
     * file, and `CODESTYLE_BASELINE_UPDATE=1` rewrites it whole from the violations of
     * the test that regenerates it, so a second test would erase the other's records
     * every time either one was run.
     *
     * @return array<string, array<int, string>> Violation lines keyed by "<rule id> <path from repository root>"
     */
    private function reportedCrossFileViolations(): array
    {
        $index = SourceIndex::forRoots($this->repositoryRoot(), $this->indexedRoots());
        $reported = [];
        foreach ($this->crossFileRules() as $rule) {
            foreach ($rule->check($index) as $violation) {
                $reported[$rule->id() . ' ' . $violation->relativePath][] = $violation->describe($rule->doc());
            }
        }

        return $reported;
    }

    /**
     * The index spans every production root, and the throws rule now judges all of it:
     * a demo calls the framework, so an index cut down to part of the tree would answer
     * "no contract" for half the calls inside it and pass them in silence. A suite is
     * left out because nothing the rule judges calls into one.
     *
     * @return array<int, string> Roots the cross-file index is built over, relative to the repository root
     */
    private function indexedRoots(): array
    {
        return array_keys(array_filter(
            ScannedRoots::all($this->repositoryRoot()),
            static fn(RootKind $kind): bool => $kind === RootKind::Production,
        ));
    }

    /**
     * @return array<int, CrossFileRule> Cross-file rules under the guard, in report order
     */
    private function crossFileRules(): array
    {
        return [ThrowsPropagationRule::forWholeIndex()];
    }

    /**
     * A demo and a test suite have no subsystem outside the mechanism to phase,
     * `scripts/` has none at all, and a new demo root arrives through the glob with
     * no activation step — so only the framework backend is judged by a segment
     * list, and every other root entire.
     *
     * @param string $root Scanned root, relative to the repository root
     * @return array<int, CodeStyleRule> Rules under the guard, in report order
     */
    private function rules(string $root): array
    {
        return [
            new CodeFqnRule($this->repositoryRoot() . '/' . $root),
            new PhpDocFqnRule($this->repositoryRoot() . '/' . $root),
            new RtStateReachRule(),
            new RtStateMutationRule(),
            new ObjectStoreMutationRule(),
            new ViewWrapperBindingRule(),
            new ErrorSuppressionRule(),
            new FsSeamRule(),
            new RandomSourceRule(),
            new BlockingResolutionRule(),
            new MalformedInputMarkerRule(),
            new SecretInQueryRule(),
            new MagicRepeatRule(),
            $root === self::PHASED_EMPTY_STRING_ROOT
                ? EmptyStringSentinelRule::forZone(self::PHASED_EMPTY_STRING_ZONE)
                : EmptyStringSentinelRule::forWholeRoot(),
            new PayloadSentinelRule(),
            new WireKeyCaseRule(),
            new LineLengthRule(),
        ];
    }

    /**
     * @param string $root Scanned root, relative to the repository root
     * @param RootKind $kind What the code under that root is
     * @return array<int, CodeStyleRule> Rules that judge this root, in report order
     */
    private function rulesFor(string $root, RootKind $kind): array
    {
        return array_values(array_filter(
            $this->rules($root),
            static fn(CodeStyleRule $rule): bool => $kind->allows($rule->id()),
        ));
    }

    /**
     * @return string Baseline contents, empty when the file does not exist yet
     */
    private function baselineText(): string
    {
        $path = $this->repositoryRoot() . '/' . Baseline::PATH;

        // external-boundary: no baseline file yet is a baseline of no entries, which empty text parses to
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
