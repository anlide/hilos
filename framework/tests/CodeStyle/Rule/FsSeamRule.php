<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\RootKind;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Enforces class C of error-suppression.md: a file primitive that owes an exception
 * is called through `Hilos\Fs`, and only the seam itself suppresses its failure.
 *
 * ERROR-SUPPRESSION judges whether a marker is there; this rule judges what the
 * marked call then does — and, by its third sign, what an unmarked call does. The
 * two are separate ids because their subject differs and because a baseline record
 * is keyed by id: a correct marker above a hand-rolled `fopen` is exactly the shape
 * that used to pass, and it is the shape this rule ends.
 *
 * A hit is raised by three signs, at most one per call:
 *
 * 1. Opening a file under `@` outside the seam, whatever the next line does. There is
 *    no legitimate reason to hold a handle the seam did not open — the caller that
 *    needs one line at a time takes `FsPath::readLines()`, and the one that jumps
 *    inside a file takes `FsPath::readWith()`.
 * 2. A suppressed primitive addressed by PATH whose result is checked, where the
 *    checking branch throws. That is class C written by hand: the failure becomes an
 *    exception either way, so it belongs behind the seam, which raises a typed
 *    `Fs/Exception/*` the caller converts at its own boundary.
 * 3. A primitive addressed by PATH called WITHOUT `@` whose false result is tested:
 *    negated, compared to `false` on either side, the left of `?:`, the condition of
 *    a ternary, the bare condition of an `if`, `elseif` or `while` alone or inside an
 *    `&&` / `||` chain, or assigned to a variable whose first read after the statement,
 *    within the enclosing block, is one of these. Inside a Hilos process the managers'
 *    error handler turns the warning the primitive raises into an exception and ends
 *    the process, and every primitive of the list raises it BEFORE returning `false` —
 *    so the tested branch is dead, whatever it does. Leaving the `@` off buys nothing;
 *    the way out is the seam. A result nobody tests is not this sign: its failure ends
 *    the process by that policy, which is a different defect.
 *
 * Two primitives of the list are outside the third sign because they fail in silence
 * (checked on PHP 8.4): `realpath()` and `glob()` answer `false` and `[]` with no
 * warning, so the check after them is alive. Sign 2 keeps judging them when suppressed.
 * And a root declared {@see RootKind::Standalone} is outside the third sign entirely:
 * a script there runs as a PHP process of its own, loads no framework class and
 * installs no warning handler, so its false branch runs, and `Hilos\Fs` is not there
 * to call.
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
 * anchors every sign on the call rather than on the sign, and the third sign reads such
 * a call as suppressed. List destructuring (`@[$a, $b] = ...`) is deliberately not
 * read — it carries no single covered call for the signs to be about.
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
     * Signs 2 and 3: the primitives addressed by a path, whose failure the caller is
     * expected to convert rather than to suppress or to test.
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
        'filemtime',
        'fileperms',
        'stat',
        'lstat',
        'readlink',
        'tempnam',
        'scandir',
        'opendir',
        'symlink',
        'link',
        'realpath',
        'glob',
        'disk_free_space',
        'disk_total_space',
        'hash_file',
        'md5_file',
        'sha1_file',
        'parse_ini_file',
    ];

    /**
     * The primitives of the list that fail without a warning, so the check after them
     * is alive: sign 3 skips them, sign 2 keeps judging them.
     *
     * @var array<int, string>
     */
    private const array SILENT_PRIMITIVES = ['realpath', 'glob'];

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
     * What may stand before a name that is NOT a call of the builtin: a method or a
     * static member wearing its name, and a declaration of one.
     *
     * @var array<int, int>
     */
    private const array NOT_A_CALL_BEFORE = [
        T_OBJECT_OPERATOR,
        T_NULLSAFE_OBJECT_OPERATOR,
        T_DOUBLE_COLON,
        T_FUNCTION,
        T_NEW,
        T_CONST,
    ];

    /**
     * The comparisons a false test is written with, on either side of `false`.
     *
     * @var array<int, int>
     */
    private const array FALSE_COMPARISONS = [T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_EQUAL, T_IS_NOT_EQUAL];

    /**
     * The operators a bare condition is chained with.
     *
     * @var array<int, int>
     */
    private const array CHAIN_OPERATORS = [T_BOOLEAN_AND, T_BOOLEAN_OR, T_LOGICAL_AND, T_LOGICAL_OR];

    /**
     * The keywords whose condition a bare test stands in for sign 3. Sign 2 reads only
     * `if`: its subject is a branch that throws, and a loop has no such branch.
     *
     * @var array<int, int>
     */
    private const array CONDITION_KEYWORDS = [T_IF, T_ELSEIF, T_WHILE];

    /**
     * @param RootKind $kind What the code under the scanned root is: sign 3 is withheld from a standalone root
     */
    public function __construct(private readonly RootKind $kind)
    {
    }

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
     * @return iterable<Violation> One entry per call that goes around the seam
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

            $function = $this->calledFunction($tokens, $callIndex);
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

        if ($this->kind === RootKind::Standalone) {
            return;
        }

        foreach ($tokens as $index => $token) {
            $function = $this->unsuppressedPrimitive($tokens, $index);
            if ($function === null || !$this->falseTested($tokens, $index)) {
                continue;
            }

            yield new Violation(
                self::ID,
                $relativePath,
                $lines[$index],
                'an unsuppressed ' . $function . '() is checked for false, a branch no Hilos process reaches:'
                    . ' its warning ends the process first; call Hilos\Fs\FsPath and catch its Fs exception',
            );
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
     * The same walk as {@see assignmentOperator()} taken backwards from the `=`: steps
     * over the target and says whether a `@` stands in front of it, which is the whole
     * assignment written under a suppression.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $assignmentIndex Index of the `=`
     * @return bool True when the assignment ending at that `=` is covered by `@`
     */
    private function assignmentUnderSuppression(array $tokens, int $assignmentIndex): bool
    {
        $depth = 0;

        for ($cursor = $assignmentIndex - 1; $cursor >= 0; $cursor--) {
            $token = $tokens[$cursor];
            if ($token === ']') {
                $depth++;
                continue;
            }

            if ($token === '[') {
                $depth--;
                continue;
            }

            if ($depth > 0) {
                continue;
            }

            if ($token === '@') {
                return true;
            }

            if (!is_array($token) || !in_array($token[0], self::TARGET_TOKENS, true)) {
                return false;
            }
        }

        return false;
    }

    /**
     * Reads the function a call names. A name written fully qualified is one token, so
     * the tail after the separator is what names the builtin.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $callIndex Index the call starts at
     * @return string|null Lower-cased function name, or null when the index holds something else than a call
     */
    private function calledFunction(array $tokens, int $callIndex): ?string
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
     * Reads a call of a sign-3 primitive that no `@` covers — neither in front of the
     * call nor in front of the assignment it is the right side of. A method or a static
     * member wearing the primitive's name, and a declaration of one, are not calls of it.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index to read
     * @return string|null Lower-cased primitive name, or null when this is not such a call
     */
    private function unsuppressedPrimitive(array $tokens, int $index): ?string
    {
        $function = $this->calledFunction($tokens, $index);
        if (
            $function === null
            || !in_array($function, self::PATH_PRIMITIVES, true)
            || in_array($function, self::SILENT_PRIMITIVES, true)
        ) {
            return null;
        }

        $before = $this->significantIndex($tokens, $index, -1);
        $token = $before === null ? null : $tokens[$before];
        if ($token === '@' || (is_array($token) && in_array($token[0], self::NOT_A_CALL_BEFORE, true))) {
            return null;
        }

        if ($token === '=' && $this->assignmentUnderSuppression($tokens, $before)) {
            return null;
        }

        return $function;
    }

    /**
     * Decides sign 3: the call itself stands in a tested position, or it is assigned to
     * a variable whose first read after the statement, within the enclosing block, does.
     * "First read" rather than "next statement" because the check is written a statement
     * or two later often enough — a size is taken, then the handle is tested.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $callIndex Index the call starts at
     * @return bool True when the false result of this call is tested
     */
    private function falseTested(array $tokens, int $callIndex): bool
    {
        $openIndex = $this->significantIndex($tokens, $callIndex, 1);
        $closeIndex = $openIndex === null ? null : $this->closingParen($tokens, $openIndex);
        if ($closeIndex === null) {
            return false;
        }

        if ($this->testedAt($tokens, $callIndex, $closeIndex)) {
            return true;
        }

        $variable = $this->assignedVariable($tokens, $callIndex);
        if ($variable === null) {
            return false;
        }

        $readIndex = $this->firstReadInBlock($tokens, $callIndex, $variable);

        return $readIndex !== null && $this->testedAt($tokens, $readIndex, $readIndex);
    }

    /**
     * The tested positions of sign 3, read around an expression: a call from its name to
     * its closing parenthesis, or a variable, which starts and ends on one token.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $from Index the expression starts at
     * @param int $to Index the expression ends at
     * @return bool True when the expression's false value is tested where it stands
     */
    private function testedAt(array $tokens, int $from, int $to): bool
    {
        $before = $this->significantIndex($tokens, $from, -1);
        $after = $this->significantIndex($tokens, $to, 1);
        $beforeToken = $before === null ? null : $tokens[$before];
        $afterToken = $after === null ? null : $tokens[$after];

        if ($beforeToken === '!' || $afterToken === '?') {
            return true;
        }

        if ($this->comparesToFalse($tokens, $after, 1) || $this->comparesToFalse($tokens, $before, -1)) {
            return true;
        }

        $bare = ($beforeToken === '(' || $this->isChainOperator($beforeToken))
            && ($afterToken === ')' || $this->isChainOperator($afterToken));

        return $bare && $this->enclosingConditionEnd($tokens, $from, self::CONDITION_KEYWORDS) !== null;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int|null $operatorIndex Index of the token next to the expression, or null at the file's edge
     * @param int $step Direction the operand of the comparison lies in
     * @return bool True when that token is a comparison whose other operand is `false`
     */
    private function comparesToFalse(array $tokens, ?int $operatorIndex, int $step): bool
    {
        if ($operatorIndex === null) {
            return false;
        }

        $operator = $tokens[$operatorIndex];
        if (!is_array($operator) || !in_array($operator[0], self::FALSE_COMPARISONS, true)) {
            return false;
        }

        $operand = $this->significantToken($tokens, $operatorIndex, $step);

        return is_array($operand) && $operand[0] === T_STRING && strtolower($operand[1]) === 'false';
    }

    /**
     * @param string|array{0: int, 1: string, 2: int}|null $token Token to read
     * @return bool True when the token is one of the operators a condition is chained with
     */
    private function isChainOperator(string|array|null $token): bool
    {
        return is_array($token) && in_array($token[0], self::CHAIN_OPERATORS, true);
    }

    /**
     * Finds the first read of a variable after the statement that assigns it, and stops
     * at the closing brace of the block the statement stands in: a read further out
     * belongs to another life of the variable.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $callIndex Index the assigned call starts at
     * @param string $variable Name of the variable the call is assigned to
     * @return int|null Index of the first token reading that variable, or null when the block holds none
     */
    private function firstReadInBlock(array $tokens, int $callIndex, string $variable): ?int
    {
        $statementEnd = $this->statementEnd($tokens, $callIndex);
        if ($statementEnd === null) {
            return null;
        }

        $depth = 0;

        for ($cursor = $statementEnd + 1; isset($tokens[$cursor]); $cursor++) {
            $token = $tokens[$cursor];
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
                continue;
            }

            if ($token === '}') {
                if ($depth === 0) {
                    return null;
                }
                $depth--;
                continue;
            }

            if (is_array($token) && $token[0] === T_VARIABLE && $token[1] === $variable) {
                return $cursor;
            }
        }

        return null;
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
        $condition = $this->enclosingConditionEnd($tokens, $index, [T_IF]);
        if ($condition !== null) {
            return $this->branchThrows($tokens, $condition);
        }

        $variable = $this->assignedVariable($tokens, $index);

        return $variable !== null && $this->nextStatementRejects($tokens, $index, $variable);
    }

    /**
     * Finds the condition the expression stands inside, opened by one of the given
     * keywords. The walk looks for a parenthesis the expression is nested in rather than
     * for the keyword itself, so a call wrapped in another one —
     * `if (strlen(@file_get_contents($p)) === 0)` — is not read as standing in the
     * condition.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index the expression starts at
     * @param array<int, int> $keywords Keyword tokens whose condition counts
     * @return int|null Index of the closing parenthesis of the condition, or null when there is none
     */
    private function enclosingConditionEnd(array $tokens, int $index, array $keywords): ?int
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

            return is_array($keyword) && in_array($keyword[0], $keywords, true) ? $this->closingParen($tokens, $cursor) : null;
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
     * @param int $index Index the call starts at
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
