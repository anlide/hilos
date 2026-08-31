<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Enforces class C of error-suppression.md: a file primitive that owes an exception
 * is called through `Hilos\Fs`, and only the seam itself suppresses its failure.
 *
 * ERROR-SUPPRESSION judges whether a marker is there; this rule judges what the
 * marked call then does. The two are separate ids because their subject differs and
 * because a baseline record is keyed by id: a correct marker above a hand-rolled
 * `fopen` is exactly the shape that used to pass, and it is the shape this rule ends.
 *
 * A hit is raised by two signs, at most one per suppressed call:
 *
 * 1. Opening a file under `@` outside the seam, whatever the next line does. There is
 *    no legitimate reason to hold a handle the seam did not open — the caller that
 *    needs one line at a time takes `FsPath::readLines()`.
 * 2. A suppressed primitive addressed by PATH whose result is checked, where the
 *    checking branch throws. That is class C written by hand: the failure becomes an
 *    exception either way, so it belongs behind the seam, which raises a typed
 *    `Fs/Exception/*` the caller converts at its own boundary.
 *
 * The deliberate degrade and the teardown step (class D) stay legal by construction:
 * they do not open a file, and their result is either not examined at all — `@unlink`
 * while tearing down, including inside a `catch` that rethrows what it caught — or
 * turns into `null`, a log line or a no-op rather than into an exception.
 *
 * Stream and socket primitives (`fwrite`, `fread`, `fclose`, `feof`, `stream_*`,
 * `socket_*`) are not judged at all: they work over a descriptor rather than a path,
 * and class B — a non-blocking socket answering `EAGAIN` — would light up on every
 * tick of the event loop.
 *
 * A suppression written over a whole assignment — `@$handle = fopen($path, 'rb');` — is
 * read by the call it covers, so moving the `@` one token left changes nothing: the rule
 * anchors every sign on the call rather than on the sign. List destructuring
 * (`@[$a, $b] = ...`) is deliberately not read — it carries no single covered call for
 * the two signs to be about.
 *
 * Only real tokens are read, so `@fopen` inside a docblock or a string literal is not
 * a suppression and cannot be a hit.
 */
final class FsSeamRule implements CodeStyleRule
{
    public const string ID = 'FS-SEAM';

    private const string DOC = 'docs/agents/code-style/error-suppression.md';

    /**
     * The seam, matched by the tail of the path: a rule is handed a path relative to
     * the root it scans and is never told which root that is, so the anchor is the
     * segment rather than the whole path — the same reading
     * {@see EmptyStringSentinelRule::isInZone()} does of its zone.
     */
    private const string SEAM_PATH = 'Fs/FsPath.php';

    /**
     * Sign 1: the primitives that hand back an open handle.
     *
     * @var array<int, string>
     */
    private const array OPENING_FUNCTIONS = ['fopen', 'tmpfile'];

    /**
     * Sign 2: the primitives addressed by a path, whose failure the caller is expected
     * to convert rather than to suppress.
     *
     * @var array<int, string>
     */
    private const array PATH_PRIMITIVES = [
        'fopen',
        'tmpfile',
        'file_get_contents',
        'file_put_contents',
        'file',
        'readfile',
        'rename',
        'copy',
        'unlink',
        'rmdir',
        'mkdir',
        'chmod',
        'touch',
        'filesize',
        'tempnam',
        'scandir',
        'symlink',
        'link',
        'realpath',
        'glob',
        'disk_free_space',
    ];

    /**
     * The tokens an assignment target is made of, walked over on the way to its `=`.
     * Whitespace and comments stand among them because the walk reads raw tokens rather
     * than significant ones — it has to count brackets, which the significant readers skip.
     *
     * @var array<int, int>
     */
    private const array TARGET_TOKENS = [
        T_WHITESPACE,
        T_COMMENT,
        T_DOC_COMMENT,
        T_OBJECT_OPERATOR,
        T_NULLSAFE_OBJECT_OPERATOR,
        T_DOUBLE_COLON,
        T_STRING,
        T_VARIABLE,
    ];

    /**
     * @return string Rule id
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * @return string Owning document
     */
    public function doc(): string
    {
        return self::DOC;
    }

    /**
     * @param string $relativePath File path relative to the scanned root
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return iterable<Violation> One entry per suppressed call that goes around the seam
     */
    public function check(string $relativePath, array $tokens): iterable
    {
        if ($this->isSeam($relativePath)) {
            return;
        }

        $lines = $this->lineNumbers($tokens);

        foreach ($tokens as $index => $token) {
            if ($token !== '@') {
                continue;
            }

            $callIndex = $this->coveredCall($tokens, $index);
            if ($callIndex === null) {
                continue;
            }

            $function = $this->suppressedFunction($tokens, $callIndex);
            if ($function === null) {
                continue;
            }

            if (in_array($function, self::OPENING_FUNCTIONS, true)) {
                yield new Violation(
                    self::ID,
                    $relativePath,
                    $lines[$callIndex],
                    'a file is opened under @ outside the Fs seam; read it through Hilos\Fs\FsPath instead',
                );
                continue;
            }

            if (in_array($function, self::PATH_PRIMITIVES, true) && $this->failureBecomesThrow($tokens, $callIndex)) {
                yield new Violation(
                    self::ID,
                    $relativePath,
                    $lines[$callIndex],
                    'a suppressed ' . $function . '() turns its failure into an exception outside the Fs seam;'
                        . ' call Hilos\Fs\FsPath and catch its Fs exception',
                );
            }
        }
    }

    /**
     * @param string $relativePath File path relative to the scanned root
     * @return bool True when this file is the seam itself, the one place allowed to suppress
     */
    private function isSeam(string $relativePath): bool
    {
        return str_ends_with('/' . $relativePath, '/' . self::SEAM_PATH);
    }

    /**
     * Finds the call a suppression covers, which is the index every sign is then judged
     * from. The sign stands either in front of the call itself — `$h = @fopen(...)` — or
     * in front of a whole assignment — `@$h = fopen(...)`; both spell the same
     * suppression, and anchoring on the call is what makes the rule read them alike.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index of the `@` token
     * @return int|null Index the covered call starts at, or null when `@` covers no single call
     */
    private function coveredCall(array $tokens, int $index): ?int
    {
        $next = $this->significantIndex($tokens, $index, 1);
        if ($next === null) {
            return null;
        }

        $token = $tokens[$next];
        if (!is_array($token) || $token[0] !== T_VARIABLE) {
            return $next;
        }

        $assignment = $this->assignmentOperator($tokens, $next);

        return $assignment === null ? null : $this->significantIndex($tokens, $assignment, 1);
    }

    /**
     * Steps over the target of an assignment a suppression covers — a variable and the
     * property, static member or offset chain hanging off it — and stops on the `=` that
     * ends it. Anything else on the way means the `@` covers no assignment, and the shape
     * is skipped the way an unrecognised one is.
     *
     * No list of compound operators is needed: `token_get_all()` gives `.=`, `??=`, `&=`,
     * `==`, `===` and `=>` each their own multi-character token, so a bare `=` arrives as
     * a single-character one and is a plain assignment and nothing else.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $variableIndex Index of the variable the assignment target starts at
     * @return int|null Index of the `=` that ends the target, or null when this is no assignment
     */
    private function assignmentOperator(array $tokens, int $variableIndex): ?int
    {
        $depth = 0;

        for ($cursor = $variableIndex + 1; isset($tokens[$cursor]); $cursor++) {
            $token = $tokens[$cursor];
            if ($token === '[') {
                $depth++;
                continue;
            }

            if ($token === ']') {
                $depth--;
                continue;
            }

            if ($depth > 0) {
                continue;
            }

            if ($token === '=') {
                return $cursor;
            }

            if (!is_array($token) || !in_array($token[0], self::TARGET_TOKENS, true)) {
                return null;
            }
        }

        return null;
    }

    /**
     * Reads the function a suppression covers. A name written fully qualified is one
     * token, so the tail after the separator is what names the builtin.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $callIndex Index the covered call starts at
     * @return string|null Lower-cased function name, or null when `@` covers something else than a call
     */
    private function suppressedFunction(array $tokens, int $callIndex): ?string
    {
        $name = $tokens[$callIndex];
        if (!is_array($name) || !in_array($name[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
            return null;
        }

        if ($this->significantToken($tokens, $callIndex, 1) !== '(') {
            return null;
        }

        $separator = strrpos($name[1], '\\');

        return strtolower($separator === false ? $name[1] : substr($name[1], $separator + 1));
    }

    /**
     * Decides sign 2 by the two shapes that turn a suppressed failure into an
     * exception, and by no other: the call stands in the condition of an `if` whose
     * branch throws, or it is assigned and the next statement is such an `if` over
     * that same variable. A result nobody examines is class D and stays silent.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index the covered call starts at
     * @return bool True when the failure of this call reaches a `throw`
     */
    private function failureBecomesThrow(array $tokens, int $index): bool
    {
        $condition = $this->enclosingConditionEnd($tokens, $index);
        if ($condition !== null) {
            return $this->branchThrows($tokens, $condition);
        }

        $variable = $this->assignedVariable($tokens, $index);

        return $variable !== null && $this->nextStatementRejects($tokens, $index, $variable);
    }

    /**
     * Finds the `if` whose condition the call stands inside. The walk looks for a
     * parenthesis this call is nested in rather than for the keyword itself, so a call
     * wrapped in another one — `if (strlen(@file_get_contents($p)) === 0)` — is not
     * read as standing in the condition.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index the covered call starts at
     * @return int|null Index of the closing parenthesis of the condition, or null when there is none
     */
    private function enclosingConditionEnd(array $tokens, int $index): ?int
    {
        $depth = 0;

        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            $token = $tokens[$cursor];
            if ($token === ';' || $token === '{' || $token === '}') {
                return null;
            }

            if ($token === ')') {
                $depth++;
                continue;
            }

            if ($token !== '(') {
                continue;
            }

            if ($depth > 0) {
                $depth--;
                continue;
            }

            $keyword = $this->significantToken($tokens, $cursor, -1);

            return is_array($keyword) && $keyword[0] === T_IF ? $this->closingParen($tokens, $cursor) : null;
        }

        return null;
    }

    /**
     * Reads back from the call to the variable it is assigned to. The suppression sign
     * may stand between the two — `$ok = @unlink($p)` — or in front of the whole
     * assignment — `@$ok = unlink($p)`; stepping over it here is what makes the two
     * spellings one shape rather than two.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index the covered call starts at
     * @return string|null Name of the variable the call is assigned to, or null when it is not assigned
     */
    private function assignedVariable(array $tokens, int $index): ?string
    {
        $assignment = $this->significantIndex($tokens, $index, -1);
        if ($assignment !== null && $tokens[$assignment] === '@') {
            $assignment = $this->significantIndex($tokens, $assignment, -1);
        }

        if ($assignment === null || $tokens[$assignment] !== '=') {
            return null;
        }

        $target = $this->significantToken($tokens, $assignment, -1);

        return is_array($target) && $target[0] === T_VARIABLE ? $target[1] : null;
    }

    /**
     * Reads the statement that follows the assignment: an `if` naming the same variable
     * whose branch throws is the hand-rolled conversion this rule is about. Anything
     * else in between means the result travels on as data, which is a different defect.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index the covered call starts at
     * @param string $variable Name of the variable the call is assigned to
     * @return bool True when the very next statement rejects that variable by throwing
     */
    private function nextStatementRejects(array $tokens, int $index, string $variable): bool
    {
        $statementEnd = $this->statementEnd($tokens, $index);
        if ($statementEnd === null) {
            return false;
        }

        $keywordIndex = $this->significantIndex($tokens, $statementEnd, 1);
        $keyword = $keywordIndex === null ? null : $tokens[$keywordIndex];
        if (!is_array($keyword) || $keyword[0] !== T_IF) {
            return false;
        }

        $openIndex = $this->significantIndex($tokens, $keywordIndex, 1);
        if ($openIndex === null || $tokens[$openIndex] !== '(') {
            return false;
        }

        $closeIndex = $this->closingParen($tokens, $openIndex);
        if ($closeIndex === null || !$this->namesVariable($tokens, $openIndex, $closeIndex, $variable)) {
            return false;
        }

        return $this->branchThrows($tokens, $closeIndex);
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $openIndex Index of the opening parenthesis of the condition
     * @param int $closeIndex Index of its closing parenthesis
     * @param string $variable Name of the variable the condition has to mention
     * @return bool True when the condition reads that variable
     */
    private function namesVariable(array $tokens, int $openIndex, int $closeIndex, string $variable): bool
    {
        for ($cursor = $openIndex + 1; $cursor < $closeIndex; $cursor++) {
            $token = $tokens[$cursor];
            if (is_array($token) && $token[0] === T_VARIABLE && $token[1] === $variable) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $closeIndex Index of the closing parenthesis of an `if` condition
     * @return bool True when the branch that condition guards throws
     */
    private function branchThrows(array $tokens, int $closeIndex): bool
    {
        $bodyIndex = $this->significantIndex($tokens, $closeIndex, 1);
        if ($bodyIndex === null) {
            return false;
        }

        if ($tokens[$bodyIndex] !== '{') {
            return $this->throwsBefore($tokens, $bodyIndex, $this->statementEnd($tokens, $bodyIndex));
        }

        return $this->throwsBefore($tokens, $bodyIndex, $this->closingBrace($tokens, $bodyIndex));
    }

    /**
     * The walk starts ON the first token of the branch rather than after it: a
     * brace-less branch is its own statement, so `if (...) throw new X();` carries
     * the `throw` at exactly that index and would be stepped over.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $from Index the search starts at
     * @param int|null $to Index the search stops at, or null when the branch never closes
     * @return bool True when a `throw` stands between the two
     */
    private function throwsBefore(array $tokens, int $from, ?int $to): bool
    {
        if ($to === null) {
            return false;
        }

        for ($cursor = $from; $cursor < $to; $cursor++) {
            $token = $tokens[$cursor];
            if (is_array($token) && $token[0] === T_THROW) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index inside the statement
     * @return int|null Index of the semicolon that ends it, or null when the file ends first
     */
    private function statementEnd(array $tokens, int $index): ?int
    {
        $depth = 0;

        for ($cursor = $index; isset($tokens[$cursor]); $cursor++) {
            $token = $tokens[$cursor];
            if ($token === '(' || $token === '[' || $token === '{') {
                $depth++;
                continue;
            }

            if ($token === ')' || $token === ']' || $token === '}') {
                $depth--;
                continue;
            }

            if ($token === ';' && $depth <= 0) {
                return $cursor;
            }
        }

        return null;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $openIndex Index of an opening parenthesis
     * @return int|null Index of its closing parenthesis, or null when the file ends first
     */
    private function closingParen(array $tokens, int $openIndex): ?int
    {
        $depth = 0;

        for ($cursor = $openIndex; isset($tokens[$cursor]); $cursor++) {
            if ($tokens[$cursor] === '(') {
                $depth++;
                continue;
            }

            if ($tokens[$cursor] === ')' && --$depth === 0) {
                return $cursor;
            }
        }

        return null;
    }

    /**
     * Braces arrive as single-character tokens except where PHP opens one itself — the
     * `${` and `{$` of an interpolated string — which close with a plain `}` and are
     * counted here so a string in the branch cannot end it early.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $openIndex Index of an opening brace
     * @return int|null Index of its closing brace, or null when the file ends first
     */
    private function closingBrace(array $tokens, int $openIndex): ?int
    {
        $depth = 0;

        for ($cursor = $openIndex; isset($tokens[$cursor]); $cursor++) {
            $token = $tokens[$cursor];
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
                continue;
            }

            if ($token === '}' && --$depth === 0) {
                return $cursor;
            }
        }

        return null;
    }

    /**
     * Single-character tokens carry no line of their own, so the walk keeps the line
     * the last multi-character token ended on.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return array<int, int> Start line of every token, single-character ones included
     */
    private function lineNumbers(array $tokens): array
    {
        $lines = [];
        $line = 1;

        foreach ($tokens as $index => $token) {
            if (!is_array($token)) {
                $lines[$index] = $line;
                continue;
            }

            $lines[$index] = $token[2];
            $line = $token[2] + substr_count($token[1], "\n");
        }

        return $lines;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index to walk away from
     * @param int $step Direction to walk in
     * @return string|array{0: int, 1: string, 2: int}|null Nearest token that is not whitespace or a comment
     */
    private function significantToken(array $tokens, int $index, int $step): string|array|null
    {
        $found = $this->significantIndex($tokens, $index, $step);

        return $found === null ? null : $tokens[$found];
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index to walk away from
     * @param int $step Direction to walk in
     * @return int|null Index of the nearest token that is not whitespace or a comment
     */
    private function significantIndex(array $tokens, int $index, int $step): ?int
    {
        for ($cursor = $index + $step; isset($tokens[$cursor]); $cursor += $step) {
            $token = $tokens[$cursor];
            if (!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $cursor;
            }
        }

        return null;
    }
}
