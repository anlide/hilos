<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\CodeStyle;

use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\Rule\EmptyStringSentinelRule;
use Hilos\Tests\CodeStyle\Rule\ErrorSuppressionRule;
use Hilos\Tests\CodeStyle\Rule\LineLengthRule;
use Hilos\Tests\CodeStyle\Rule\MagicRepeatRule;
use Hilos\Tests\CodeStyle\Rule\PayloadSentinelRule;
use Hilos\Tests\CodeStyle\Rule\PhpDocFqnRule;
use Hilos\Tests\CodeStyle\Rule\RandomSourceRule;
use Hilos\Tests\CodeStyle\Rule\RtStateReachRule;
use Hilos\Tests\CodeStyle\Rule\WireKeyCaseRule;
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
    /**
     * Segments the fixture tree repeats from the real zone. Not all of them — a
     * segment that only adds another path proves nothing the ones here do not.
     * Each of these four stands for a decision the zone makes: a nested segment,
     * a top-level one, a segment added by a later phase, and `Socket`, taken whole
     * rather than by its subdirectories — the only one whose fixture sits DIRECTLY
     * in the segment, which is what tells a segment match from a prefix match.
     *
     * @var array<int, string>
     */
    private const array ZONE_SEGMENTS = ['Core/Router', 'Tables', 'Core/CLI', 'Socket'];

    /** Fixture root judged with no zone at all, kept out of the scan above. */
    private const string WHOLE_ROOT_FIXTURES = 'WholeRoot';

    public function testRulesReportExactlyTheSeededViolations(): void
    {
        $this->assertSame(
            [
                'EMPTY-STRING-SENTINEL Bad/Core/CLI/EmptySentinel.php:19 — ?? \'\' turns a missing value '
                    . 'into an empty string; keep it null or make the field required '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'EMPTY-STRING-SENTINEL Bad/Core/Router/EmptySentinel.php:23 — ?? \'\' turns a missing value '
                    . 'into an empty string; keep it null or make the field required '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'EMPTY-STRING-SENTINEL Bad/Core/Router/EmptySentinel.php:24 — ?? \'\' turns a missing value '
                    . 'into an empty string; keep it null or make the field required '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'EMPTY-STRING-SENTINEL Bad/Core/Router/EmptySentinel.php:26 — ?? \'\' turns a missing value '
                    . 'into an empty string; keep it null or make the field required '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'EMPTY-STRING-SENTINEL Bad/Core/Router/MatchDefault.php:23 — match falls back to \'\' '
                    . 'where the value is missing; keep it null or make the field required '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'EMPTY-STRING-SENTINEL Bad/Core/Router/TernaryBranch.php:22 — a ternary branch hands back '
                    . '\'\' where the value is missing; keep it null or make the field required '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'EMPTY-STRING-SENTINEL Bad/Core/Router/TernaryBranch.php:23 — a ternary branch hands back '
                    . '\'\' where the value is missing; keep it null or make the field required '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'EMPTY-STRING-SENTINEL Bad/Core/Router/TernaryBranch.php:24 — a ternary branch hands back '
                    . '\'\' where the value is missing; keep it null or make the field required '
                    . '(see docs/agents/code-style/method-contracts.md)',
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
                'LINE-LENGTH Bad/LineLengthSamples.php:15 — line is 158 characters, limit 150 '
                    . '(see docs/agents/code-style/line-length.md)',
                'LINE-LENGTH Bad/LineLengthSamples.php:21 — line is 154 characters, limit 150 '
                    . '(see docs/agents/code-style/line-length.md)',
                'LINE-LENGTH Bad/LineLengthSamples.php:23 — line is 167 characters, limit 150 '
                    . '(see docs/agents/code-style/line-length.md)',
                'MAGIC-REPEAT Bad/MagicRepeatSamples.php:21 — 1000 occurs 2 times in this file; name it '
                    . 'with a constant that carries the unit (see docs/agents/code-style/magic-values.md)',
                'MAGIC-REPEAT Bad/MagicRepeatSamples.php:21 — 1000 occurs 2 times in this file; name it '
                    . 'with a constant that carries the unit (see docs/agents/code-style/magic-values.md)',
                'MAGIC-REPEAT Bad/MagicRepeatSamples.php:30 — 2000.0 occurs 2 times in this file; name it '
                    . 'with a constant that carries the unit (see docs/agents/code-style/magic-values.md)',
                'MAGIC-REPEAT Bad/MagicRepeatSamples.php:38 — 2000.0 occurs 2 times in this file; name it '
                    . 'with a constant that carries the unit (see docs/agents/code-style/magic-values.md)',
                'MAGIC-REPEAT Bad/MagicRepeatSamples.php:49 — 3000 occurs 2 times in this file; name it '
                    . 'with a constant that carries the unit (see docs/agents/code-style/magic-values.md)',
                'MAGIC-REPEAT Bad/MagicRepeatSamples.php:49 — 3000 occurs 2 times in this file; name it '
                    . 'with a constant that carries the unit (see docs/agents/code-style/magic-values.md)',
                'MAGIC-REPEAT Bad/MagicRepeatSamples.php:61 — 4000 occurs 2 times in this file; name it '
                    . 'with a constant that carries the unit (see docs/agents/code-style/magic-values.md)',
                'MAGIC-REPEAT Bad/MagicRepeatSamples.php:62 — 4000 occurs 2 times in this file; name it '
                    . 'with a constant that carries the unit (see docs/agents/code-style/magic-values.md)',
                'PAYLOAD-SENTINEL Bad/PayloadMarkerWithoutReason.php:28 — the `// external-boundary:` '
                    . 'marker above the fallback names no reason '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'PAYLOAD-SENTINEL Bad/PayloadSentinelSamples.php:33 — ?? \'\' mints a stub where the payload '
                    . 'field is missing; refuse the payload or let the field be null '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'PAYLOAD-SENTINEL Bad/PayloadSentinelSamples.php:34 — ?? 0 mints a stub where the payload '
                    . 'field is missing; refuse the payload or let the field be null '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'PAYLOAD-SENTINEL Bad/PayloadSentinelSamples.php:35 — ?? 0.0 mints a stub where the payload '
                    . 'field is missing; refuse the payload or let the field be null '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'PAYLOAD-SENTINEL Bad/PayloadSentinelSamples.php:47 — a ternary branch hands back "" where '
                    . 'the payload field is missing; refuse the payload or let the field be null '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'PAYLOAD-SENTINEL Bad/PayloadSentinelSamples.php:50 — match falls back to 0 where the payload '
                    . 'field is missing; refuse the payload or let the field be null '
                    . '(see docs/agents/code-style/method-contracts.md)',
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
                'RANDOM-SOURCE Bad/RandomSourceSamples.php:23 — RandomHelper::bytes() falls back to '
                    . 'pseudorandom; a secret takes secureBytes()/secureHex(), and a value that only has to '
                    . 'be unique takes this file into the rule\'s list '
                    . '(see docs/agents/code-style/random-source.md)',
                'RANDOM-SOURCE Bad/RandomSourceSamples.php:24 — RandomHelper::hex() falls back to '
                    . 'pseudorandom; a secret takes secureBytes()/secureHex(), and a value that only has to '
                    . 'be unique takes this file into the rule\'s list '
                    . '(see docs/agents/code-style/random-source.md)',
                'RANDOM-SOURCE Bad/RandomSourceSamples.php:25 — RandomHelper::integer() falls back to '
                    . 'pseudorandom; a secret takes secureBytes()/secureHex(), and a value that only has to '
                    . 'be unique takes this file into the rule\'s list '
                    . '(see docs/agents/code-style/random-source.md)',
                'RT-STATE-REACH Bad/RtStateReach.php:20 — getStateCollection() reaches backing RT state '
                    . 'outside Database/ and Runtime/ (see docs/agents/runtime/rt-state.md)',
                'RT-STATE-REACH Bad/RtStateReach.php:21 — getStateItem() reaches backing RT state '
                    . 'outside Database/ and Runtime/ (see docs/agents/runtime/rt-state.md)',
                'RT-STATE-REACH Bad/RtStateReach.php:22 — getStateItem() reaches backing RT state '
                    . 'outside Database/ and Runtime/ (see docs/agents/runtime/rt-state.md)',
                'RT-STATE-REACH Bad/RtStateReach.php:23 — $this->stateCollection reaches backing RT state '
                    . 'outside Database/ and Runtime/ (see docs/agents/runtime/rt-state.md)',
                'EMPTY-STRING-SENTINEL Bad/Socket/EmptySentinel.php:25 — ?? \'\' turns a missing value '
                    . 'into an empty string; keep it null or make the field required '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'EMPTY-STRING-SENTINEL Bad/Tables/EmptySentinel.php:20 — ?? \'\' turns a missing value '
                    . 'into an empty string; keep it null or make the field required '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'EMPTY-STRING-SENTINEL Bad/Tables/MarkerWithoutReason.php:22 — the `// external-boundary:` '
                    . 'marker above the fallback names no reason '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'EMPTY-STRING-SENTINEL Bad/Tables/MarkerWithoutReason.php:32 — the `// external-boundary:` '
                    . 'marker above the fallback names no reason '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'WIRE-KEY-CASE Bad/WireKeyCaseSamples.php:15 — field key \'created_at\' is not camelCase; '
                    . 'one spelling has to serve PHP, the wire and TS '
                    . '(see docs/agents/code-style/cross-layer-field-names.md)',
                'WIRE-KEY-CASE Bad/WireKeyCaseSamples.php:17 — field key \'ValueSource\' is not camelCase; '
                    . 'one spelling has to serve PHP, the wire and TS '
                    . '(see docs/agents/code-style/cross-layer-field-names.md)',
                'WIRE-KEY-CASE Bad/WireKeyCaseSamples.php:19 — field key \'override_value\' is not camelCase; '
                    . 'one spelling has to serve PHP, the wire and TS '
                    . '(see docs/agents/code-style/cross-layer-field-names.md)',
                'WIRE-KEY-CASE Bad/WireKeyCaseSamples.php:19 — field key \'default_value\' is not camelCase; '
                    . 'one spelling has to serve PHP, the wire and TS '
                    . '(see docs/agents/code-style/cross-layer-field-names.md)',
                'WIRE-KEY-CASE Bad/WireKeyCaseSamples.php:21 — field key \'default_kind\' is not camelCase; '
                    . 'one spelling has to serve PHP, the wire and TS '
                    . '(see docs/agents/code-style/cross-layer-field-names.md)',
                'EMPTY-STRING-SENTINEL WholeRoot/Playground/JudgedAnyway.php:22 — ?? \'\' turns a missing '
                    . 'value into an empty string; keep it null or make the field required '
                    . '(see docs/agents/code-style/method-contracts.md)',
            ],
            $this->reportFixtureViolations(),
            'Fixture report drifted: a rule either stopped catching a seeded case or started '
                . 'reporting a legitimate one.',
        );
    }

    /**
     * Scans the fixture tree with the same scanner and rules the guard test uses.
     * The whole-root mode of the empty-string rule needs a root where nothing is
     * outside a zone, so it gets a fixture root of its own — judged separately and
     * reported under its own prefix, the way the guard test names its roots.
     *
     * @return array<int, string> Reported lines, ordered by file path and then by occurrence
     */
    private function reportFixtureViolations(): array
    {
        $fixtures = dirname(__DIR__, 2) . '/CodeStyle/Fixtures';
        $lines = $this->reportRoot(new SourceScanner($fixtures, [self::WHOLE_ROOT_FIXTURES]), $this->rules());
        $wholeRoot = $this->reportRoot(
            new SourceScanner($fixtures . '/' . self::WHOLE_ROOT_FIXTURES),
            [EmptyStringSentinelRule::forWholeRoot()],
            self::WHOLE_ROOT_FIXTURES,
        );

        return array_merge($lines, $wholeRoot);
    }

    /**
     * @param SourceScanner $scanner Scanner over one fixture root
     * @param array<int, CodeStyleRule> $rules Rules to run over that root, in report order
     * @param string|null $prefix Path put back in front of every reported file, when the root is not the top one
     * @return array<int, string> Reported lines, ordered by file path and then by occurrence
     */
    private function reportRoot(SourceScanner $scanner, array $rules, ?string $prefix = null): array
    {
        $sources = [];
        foreach ($scanner->files() as $file) {
            $sources[$scanner->relativePath($file)] = (string)file_get_contents($file->getPathname());
        }
        ksort($sources);

        $lines = [];
        foreach ($sources as $relativePath => $contents) {
            $tokens = token_get_all($contents);
            foreach ($rules as $rule) {
                foreach ($rule->check($relativePath, $tokens) as $violation) {
                    $reported = $prefix === null ? $violation : $violation->withPathPrefix($prefix);
                    $lines[] = $reported->describe($rule->doc());
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
        return [
            new PhpDocFqnRule(),
            new RtStateReachRule(),
            new ErrorSuppressionRule(),
            new RandomSourceRule(),
            new MagicRepeatRule(),
            EmptyStringSentinelRule::forZone(self::ZONE_SEGMENTS),
            new PayloadSentinelRule(),
            new WireKeyCaseRule(),
            new LineLengthRule(),
        ];
    }
}
