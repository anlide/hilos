<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\CodeStyle;

use Hilos\Tests\CodeStyle\CodeStyleRule;
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
                'CODE-FQN Bad/BlockingResolutionSamples.php:30 — \dns_get_mx is written out in code; a global '
                    . 'function or constant takes the short name and no import '
                    . '(see docs/agents/code-style/qualified-names.md)',
                // Line 32 is the userland function wearing a builtin's name: CODE-FQN judges how it is
                // written and BLOCKING-RESOLUTION says nothing, which is the whole point of seeding it.
                'CODE-FQN Bad/BlockingResolutionSamples.php:32 — '
                    . '\Hilos\Tests\CodeStyle\Fixtures\Bad\Resolver\gethostbyname is written out in code; '
                    . 'import it and use the short name '
                    . '(see docs/agents/code-style/qualified-names.md)',
                'BLOCKING-RESOLUTION Bad/BlockingResolutionSamples.php:26 — gethostbyname() blocks the '
                    . 'process until a nameserver answers; resolve the name outside the loop, or name this '
                    . 'file in the rule\'s list with a reason '
                    . '(see docs/agents/code-style/blocking-resolution.md)',
                'BLOCKING-RESOLUTION Bad/BlockingResolutionSamples.php:27 — gethostbynamel() blocks the '
                    . 'process until a nameserver answers; resolve the name outside the loop, or name this '
                    . 'file in the rule\'s list with a reason '
                    . '(see docs/agents/code-style/blocking-resolution.md)',
                'BLOCKING-RESOLUTION Bad/BlockingResolutionSamples.php:28 — gethostbyaddr() blocks the '
                    . 'process until a nameserver answers; resolve the name outside the loop, or name this '
                    . 'file in the rule\'s list with a reason '
                    . '(see docs/agents/code-style/blocking-resolution.md)',
                'BLOCKING-RESOLUTION Bad/BlockingResolutionSamples.php:29 — dns_get_record() blocks the '
                    . 'process until a nameserver answers; resolve the name outside the loop, or name this '
                    . 'file in the rule\'s list with a reason '
                    . '(see docs/agents/code-style/blocking-resolution.md)',
                'BLOCKING-RESOLUTION Bad/BlockingResolutionSamples.php:30 — dns_get_mx() blocks the '
                    . 'process until a nameserver answers; resolve the name outside the loop, or name this '
                    . 'file in the rule\'s list with a reason '
                    . '(see docs/agents/code-style/blocking-resolution.md)',
                'BLOCKING-RESOLUTION Bad/BlockingResolutionSamples.php:31 — checkdnsrr() blocks the '
                    . 'process until a nameserver answers; resolve the name outside the loop, or name this '
                    . 'file in the rule\'s list with a reason '
                    . '(see docs/agents/code-style/blocking-resolution.md)',
                'MALFORMED-INPUT-MARKER Bad/Cluster/Exception/UnmarkedRefusal.php:18 — UnmarkedRefusal is '
                    . 'declared where input is parsed and carries no MalformedInput; implement it, extend a '
                    . 'marked base, or name the class in the rule\'s exempt list with a reason '
                    . '(see docs/agents/code-style/exceptions.md)',
                'CODE-FQN Bad/CodeFqnSamples.php:12 — \RuntimeException is written out in code; import it '
                    . 'and use the short name (see docs/agents/code-style/qualified-names.md)',
                'CODE-FQN Bad/CodeFqnSamples.php:20 — \Hilos\Tests\CodeStyle\SourceScanner is written out in '
                    . 'code; import it and use the short name (see docs/agents/code-style/qualified-names.md)',
                'CODE-FQN Bad/CodeFqnSamples.php:21 — \Throwable is written out in code; import it '
                    . 'and use the short name (see docs/agents/code-style/qualified-names.md)',
                'CODE-FQN Bad/CodeFqnSamples.php:25 — \Hilos\Tests\CodeStyle\Baseline is written out in '
                    . 'code; import it and use the short name (see docs/agents/code-style/qualified-names.md)',
                'CODE-FQN Bad/CodeFqnSamples.php:37 — Rule\CodeFqnSample is written against the current '
                    . 'namespace; import it and use the short name '
                    . '(see docs/agents/code-style/qualified-names.md)',
                'CODE-FQN Bad/CodeFqnSamples.php:39 — \strlen is written out in code; a global function or '
                    . 'constant takes the short name and no import '
                    . '(see docs/agents/code-style/qualified-names.md)',
                'CODE-FQN Bad/CodeFqnUnresolved.php:21 — JsonException resolves to no class; '
                    . 'the import is missing (see docs/agents/code-style/qualified-names.md)',
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
                'PAYLOAD-SENTINEL Bad/DiffReaderSentinelSamples.php:30 — ?? \'\' mints a stub where the diff '
                    . 'does not carry the key; an absent key means the field did not change, so read it with '
                    . 'patch* (see docs/agents/code-style/method-contracts.md)',
                'PAYLOAD-SENTINEL Bad/DiffReaderSentinelSamples.php:31 — optionalString() answers null to a '
                    . 'key the diff does not carry and clears a field it never touched; read it with its '
                    . 'patch* twin (see docs/agents/code-style/method-contracts.md)',
                'PAYLOAD-SENTINEL Bad/DiffReaderSentinelSamples.php:41 — match falls back to 0 where the diff '
                    . 'does not carry the key; an absent key means the field did not change, so read it with '
                    . 'patch* (see docs/agents/code-style/method-contracts.md)',
                'PAYLOAD-SENTINEL Bad/DiffReaderSentinelSamples.php:50 — ?? 0.0 mints a stub where the diff '
                    . 'does not carry the key; an absent key means the field did not change, so read it with '
                    . 'patch* (see docs/agents/code-style/method-contracts.md)',
                'PAYLOAD-SENTINEL Bad/DiffReaderSentinelSamples.php:51 — optionalInt() answers null to a key '
                    . 'the diff does not carry and clears a field it never touched; read it with its patch* '
                    . 'twin (see docs/agents/code-style/method-contracts.md)',
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
                'FS-SEAM Bad/FsSeamBypassSamples.php:28 — a file is opened under @ outside the Fs seam; '
                    . 'read it through Hilos\Fs\FsPath instead '
                    . '(see docs/agents/code-style/error-suppression.md)',
                'FS-SEAM Bad/FsSeamBypassSamples.php:35 — a suppressed file_get_contents() turns its failure '
                    . 'into an exception outside the Fs seam; call Hilos\Fs\FsPath and catch its Fs exception '
                    . '(see docs/agents/code-style/error-suppression.md)',
                'FS-SEAM Bad/FsSeamBypassSamples.php:50 — a suppressed unlink() turns its failure into an '
                    . 'exception outside the Fs seam; call Hilos\Fs\FsPath and catch its Fs exception '
                    . '(see docs/agents/code-style/error-suppression.md)',
                'FS-SEAM Bad/FsSeamBypassSamples.php:62 — a file is opened under @ outside the Fs seam; '
                    . 'read it through Hilos\Fs\FsPath instead '
                    . '(see docs/agents/code-style/error-suppression.md)',
                'FS-SEAM Bad/FsSeamBypassSamples.php:76 — a suppressed unlink() turns its failure into an '
                    . 'exception outside the Fs seam; call Hilos\Fs\FsPath and catch its Fs exception '
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
                'PHPDOC-FQN Bad/PhpDocFqnSamples.php:41 — @return references \Socket '
                    . 'instead of an imported short name (see docs/agents/code-style/phpdoc.md)',
                'PHPDOC-FQN Bad/PhpDocFqnSamples.php:41 — @return references \Socket '
                    . 'instead of an imported short name (see docs/agents/code-style/phpdoc.md)',
                'PHPDOC-FQN Bad/PhpDocFqnSamples.php:50 — {@see} references Rule\PhpDocFqnRule '
                    . 'relative to the current namespace (see docs/agents/code-style/phpdoc.md)',
                'PHPDOC-FQN Bad/PhpDocFqnSamples.php:51 — {@see} references Baseline, which is neither '
                    . 'imported nor declared in this namespace (see docs/agents/code-style/phpdoc.md)',
                'CODE-FQN Bad/RandomSourceSamples.php:25 — \Hilos\Utils\Helpers\RandomHelper is written out '
                    . 'in code; import it and use the short name (see docs/agents/code-style/qualified-names.md)',
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
                'PAYLOAD-SENTINEL Bad/RowReaderSentinelSamples.php:28 — ?? \'\' mints a stub where the '
                    . 'payload field is missing; refuse the payload or let the field be null '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'PAYLOAD-SENTINEL Bad/RowReaderSentinelSamples.php:38 — ?? 0 mints a stub where the payload '
                    . 'field is missing; refuse the payload or let the field be null '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'PAYLOAD-SENTINEL Bad/RowReaderSentinelSamples.php:46 — a ternary branch hands back "" where '
                    . 'the payload field is missing; refuse the payload or let the field be null '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'RT-STATE-REACH Bad/RtStateReach.php:22 — getStateCollection() reaches backing RT state '
                    . 'outside Database/ and Runtime/ (see docs/agents/runtime/rt-state.md)',
                'RT-STATE-REACH Bad/RtStateReach.php:23 — getStateItem() reaches backing RT state '
                    . 'outside Database/ and Runtime/ (see docs/agents/runtime/rt-state.md)',
                'RT-STATE-REACH Bad/RtStateReach.php:24 — getStateItem() reaches backing RT state '
                    . 'outside Database/ and Runtime/ (see docs/agents/runtime/rt-state.md)',
                'RT-STATE-REACH Bad/RtStateReach.php:25 — $this->stateCollection reaches backing RT state '
                    . 'outside Database/ and Runtime/ (see docs/agents/runtime/rt-state.md)',
                'SECRET-IN-QUERY Bad/SecretInQuerySamples.php:24 — query param '
                    . 'HilosHttpHeaders::HILOS_SESSION_TOKEN is read from the url; a session token or any '
                    . 'other secret arrives in a cookie or a header '
                    . '(see docs/agents/antipatterns/secret-in-query.md)',
                'SECRET-IN-QUERY Bad/SecretInQuerySamples.php:25 — query param \'token\' is read from the '
                    . 'url; a session token or any other secret arrives in a cookie or a header '
                    . '(see docs/agents/antipatterns/secret-in-query.md)',
                'SECRET-IN-QUERY Bad/SecretInQuerySamples.php:26 — query param \'code\' is read from the '
                    . 'url; a session token or any other secret arrives in a cookie or a header '
                    . '(see docs/agents/antipatterns/secret-in-query.md)',
                'SECRET-IN-QUERY Bad/SecretInQuerySamples.php:27 — query param \'invite\' is read from the '
                    . 'url; a session token or any other secret arrives in a cookie or a header '
                    . '(see docs/agents/antipatterns/secret-in-query.md)',
                'EMPTY-STRING-SENTINEL Bad/Socket/EmptySentinel.php:25 — ?? \'\' turns a missing value '
                    . 'into an empty string; keep it null or make the field required '
                    . '(see docs/agents/code-style/method-contracts.md)',
                'MALFORMED-INPUT-MARKER Bad/Socket/WebSocket/WebSocketException.php:19 — WebSocketException '
                    . 'is a base its children inherit the marker through, and its own declaration no longer '
                    . 'implements MalformedInput (see docs/agents/code-style/exceptions.md)',
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
                'DB-OBJECT-MUTATE Database/Object/Collection/ObjectStoreMutate.php:26 — $this->objects is '
                    . 'written directly outside Objects; go through $this[$id] = $object for a new row, or '
                    . 'hydrate() for a row read out of storage (see docs/agents/orm/object.md)',
                'DB-OBJECT-MUTATE Database/Object/Collection/ObjectStoreMutate.php:27 — $this->objects is '
                    . 'written directly outside Objects; go through $this[$id] = $object for a new row, or '
                    . 'hydrate() for a row read out of storage (see docs/agents/orm/object.md)',
                'DB-OBJECT-MUTATE Database/Object/Collection/ObjectStoreMutate.php:28 — $this->objects is '
                    . 'written directly outside Objects; go through $this[$id] = $object for a new row, or '
                    . 'hydrate() for a row read out of storage (see docs/agents/orm/object.md)',
                'DB-OBJECT-MUTATE Database/Object/Collection/ObjectStoreMutate.php:29 — unset() drops a row of '
                    . 'the object store directly; use unset($this[$id]), which announces the loss and lets the '
                    . 'view drop its wrapper (see docs/agents/orm/object.md)',
                'DB-OBJECT-MUTATE Database/Object/Collection/ObjectStoreMutate.php:30 — $this->objects is '
                    . 'written directly outside Objects; go through $this[$id] = $object for a new row, or '
                    . 'hydrate() for a row read out of storage (see docs/agents/orm/object.md)',
                'DB-OBJECT-MUTATE Database/Object/Collection/ObjectStoreMutate.php:31 — $this->objects is '
                    . 'written directly outside Objects; go through $this[$id] = $object for a new row, or '
                    . 'hydrate() for a row read out of storage (see docs/agents/orm/object.md)',
                // Storage first and signatures after, because the rule walks the file twice: the two
                // halves are reported in the order they are checked, not in line order.
                'VIEW-WRAPPER-BIND Database/View/Item/ByRefWrapperSamples.php:25 — $this->_object is bound to '
                    . 'a variable by reference; assign the value, so the wrapper keeps the row it was built '
                    . 'from (see docs/agents/orm/collection-iteration.md)',
                'VIEW-WRAPPER-BIND Database/View/Item/ByRefWrapperSamples.php:36 — $this->_object is bound to '
                    . 'a variable by reference; assign the value, so the wrapper keeps the row it was built '
                    . 'from (see docs/agents/orm/collection-iteration.md)',
                'VIEW-WRAPPER-BIND Database/View/Item/ByRefWrapperSamples.php:23 — $object is declared by '
                    . 'reference in the wrapper layer; take the value, so no caller has to hand over a '
                    . 'variable (see docs/agents/orm/collection-iteration.md)',
                'VIEW-WRAPPER-BIND Database/View/Item/ByRefWrapperSamples.php:34 — $object is declared by '
                    . 'reference in the wrapper layer; take the value, so no caller has to hand over a '
                    . 'variable (see docs/agents/orm/collection-iteration.md)',
                'RT-STATE-MUTATE Runtime/State/Collection/StateMutateSubclass.php:25 — $this->states is written '
                    . 'directly outside RtStates; go through add(), remove() or clear() '
                    . '(see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/State/Collection/StateMutateSubclass.php:26 — $this->states is written '
                    . 'directly outside RtStates; go through add(), remove() or clear() '
                    . '(see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/State/Collection/StateMutateSubclass.php:27 — $this->states is written '
                    . 'directly outside RtStates; go through add(), remove() or clear() '
                    . '(see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/State/Collection/StateMutateSubclass.php:28 — $this->states is written '
                    . 'directly outside RtStates; go through add(), remove() or clear() '
                    . '(see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/State/Collection/StateMutateSubclass.php:29 — $this->states is written '
                    . 'directly outside RtStates; go through add(), remove() or clear() '
                    . '(see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/View/Actions/Collection/RtStateMutate.php:23 — add() mutates the backing '
                    . 'RT state collection outside the base actions; call the base RtActions method instead '
                    . '(see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/View/Actions/Collection/RtStateMutate.php:24 — remove() mutates the '
                    . 'backing RT state collection outside the base actions; call the base RtActions method '
                    . 'instead (see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/View/Actions/Collection/RtStateMutate.php:25 — clear() mutates the '
                    . 'backing RT state collection outside the base actions; call the base RtActions method '
                    . 'instead (see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/View/Actions/Collection/RtStateMutate.php:37 — add() mutates the backing '
                    . 'RT state collection outside the base actions; call the base RtActions method instead '
                    . '(see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/View/Actions/Collection/RtStateMutate.php:38 — unset() drops a key of '
                    . 'the backing RT state collection outside the base actions; call the base RtActions method '
                    . 'instead (see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/View/Actions/Collection/RtStateMutate.php:47 — add() mutates the backing '
                    . 'RT state collection outside the base actions; call the base RtActions method instead '
                    . '(see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/View/Actions/Collection/RtStateMutate.php:48 — remove() mutates the '
                    . 'backing RT state collection outside the base actions; call the base RtActions method '
                    . 'instead (see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/View/Actions/Collection/RtStateMutate.php:49 — clear() mutates the '
                    . 'backing RT state collection outside the base actions; call the base RtActions method '
                    . 'instead (see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/View/Actions/Collection/RtStateMutate.php:59 — add() mutates the backing '
                    . 'RT state collection outside the base actions; call the base RtActions method instead '
                    . '(see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/View/Actions/Collection/RtStateMutate.php:60 — remove() mutates the '
                    . 'backing RT state collection outside the base actions; call the base RtActions method '
                    . 'instead (see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/View/Actions/Collection/RtStateMutate.php:61 — clear() mutates the '
                    . 'backing RT state collection outside the base actions; call the base RtActions method '
                    . 'instead (see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/View/Actions/Collection/RtStateMutate.php:62 — a key of the backing RT '
                    . 'state collection is written outside the base actions; call the base RtActions method '
                    . 'instead (see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/View/Actions/Collection/RtStateMutate.php:63 — unset() drops a key of '
                    . 'the backing RT state collection outside the base actions; call the base RtActions method '
                    . 'instead (see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/View/Actions/Collection/RtStateMutate.php:64 — a key of the backing RT '
                    . 'state collection is written outside the base actions; call the base RtActions method '
                    . 'instead (see docs/agents/runtime/rt-state.md)',
                'RT-STATE-MUTATE Runtime/View/Actions/Collection/RtStateMutate.php:78 — add() mutates the backing '
                    . 'RT state collection outside the base actions; call the base RtActions method instead '
                    . '(see docs/agents/runtime/rt-state.md)',
                'VIEW-WRAPPER-BIND Runtime/View/Collection/ByRefFactorySamples.php:27 — '
                    . '$this->_stateCollection is bound to a variable by reference; assign the value, so the '
                    . 'wrapper keeps the row it was built from (see docs/agents/orm/collection-iteration.md)',
                'VIEW-WRAPPER-BIND Runtime/View/Collection/ByRefFactorySamples.php:25 — $stateCollection is '
                    . 'declared by reference in the wrapper layer; take the value, so no caller has to hand '
                    . 'over a variable (see docs/agents/orm/collection-iteration.md)',
                'VIEW-WRAPPER-BIND Runtime/View/Collection/ByRefFactorySamples.php:34 — $state is declared by '
                    . 'reference in the wrapper layer; take the value, so no caller has to hand over a '
                    . 'variable (see docs/agents/orm/collection-iteration.md)',
                // The closure's parameter, seeded to prove that a factory handed over as a callback is
                // read the same way a method is; a `use (&$captured)` clause is not, and Good/ByRefLookAlikes
                // stays absent from this report for that reason.
                'VIEW-WRAPPER-BIND Runtime/View/Collection/ByRefFactorySamples.php:47 — $state is declared by '
                    . 'reference in the wrapper layer; take the value, so no caller has to hand over a '
                    . 'variable (see docs/agents/orm/collection-iteration.md)',
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
        $fixtures = $this->fixtureRoot();
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
     * @return string Absolute path of the fixture root, which the rules that read neighbours are handed
     */
    private function fixtureRoot(): string
    {
        return dirname(__DIR__, 2) . '/CodeStyle/Fixtures';
    }

    /**
     * @return array<int, CodeStyleRule> Rules under test, in report order
     */
    private function rules(): array
    {
        return [
            new CodeFqnRule($this->fixtureRoot()),
            new PhpDocFqnRule($this->fixtureRoot()),
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
            EmptyStringSentinelRule::forZone(self::ZONE_SEGMENTS),
            new PayloadSentinelRule(),
            new WireKeyCaseRule(),
            new LineLengthRule(),
        ];
    }
}
