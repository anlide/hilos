<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Enforces exceptions.md: an exception declared where the node reads the wire either
 * says "this input could not be parsed" by carrying the `MalformedInput` marker, or
 * is named here as one that means something else.
 *
 * The marker exists so a read path can tell a refused input from a broken node
 * without keeping a list of class names, and a list nobody keeps goes stale the
 * quiet way: the next parsing failure is written, nobody remembers the marker, and
 * the log spends the error level on the ordinary work of an open port. The rule
 * turns that silence into a failing test on the very commit that adds the class.
 *
 * It fires on two shapes. A class declared in one of {@see self::WIRE_EXCEPTION_DIRS}
 * that neither implements the marker, nor inherits it from one of
 * {@see self::MARKED_BASES}, nor is named in {@see self::EXEMPT} — that is the new
 * failure nobody marked. And a class named in `MARKED_BASES` whose own declaration
 * has lost `implements MalformedInput` — that is the marker being taken off a base
 * a whole branch inherits it through, which the first shape cannot see, because the
 * two bases that carry the most children do not live in a judged directory at all.
 *
 * A directory is judged by its own path and not by what sits under it, which is why
 * `Socket/Exception` and `Socket/Exception/Base` are both listed: they are two sets
 * of classes with two different answers, and one entry standing for both would mean
 * the day somebody adds a third subdirectory it is judged without anyone deciding so.
 *
 * The exempt list lives here and not in `baseline.txt` for the reason
 * {@see RandomSourceRule} gives: a baseline record names the leaf that will pay it
 * off and may only shrink, while these classes are a standing answer — they will
 * never carry the marker, because they are not about parsing. Its entries are grouped
 * under the reason they share rather than repeated one per class: the errno names of
 * the socket API are twenty-two spellings of a single answer, and twenty-two copies
 * of one sentence would read as twenty-two decisions when there is only one. Adding
 * a class under an existing reason is the point of the rule, not a way around it —
 * a class that needs a new sentence written for it is usually a class that needs the
 * marker instead.
 */
final class MalformedInputMarkerRule implements CodeStyleRule
{
    public const string ID = 'MALFORMED-INPUT-MARKER';

    private const string DOC = 'docs/agents/code-style/exceptions.md';

    /** The marker, named without its namespace the way a declaration writes it. */
    private const string MARKER = 'MalformedInput';

    /**
     * Directories whose exceptions are about reading what arrived, each path read
     * relative to the root it sits in and matched against the directory of the file
     * itself, never against a parent of it.
     *
     * @var array<int, string>
     */
    private const array WIRE_EXCEPTION_DIRS = [
        'Core/Exception',
        'Core/Agent/Exception',
        'Core/Http/Exception',
        'Socket/Exception',
        'Socket/Exception/Base',
        'Socket/WebSocket/Exception',
        'Socket/Worker/Exception',
        'Cluster/Exception',
    ];

    /**
     * Classes a child inherits the marker from, so a child of one of them needs no
     * declaration of its own. Two of them — `InvalidFormatException` and
     * `WebSocketException` — carry whole branches, which is why the rule also watches
     * their own files for the marker going missing.
     *
     * @var array<int, string>
     */
    private const array MARKED_BASES = [
        'InvalidFormatException',
        'WebSocketException',
        'MessageTooLongException',
        'PeerTransportException',
    ];

    /**
     * Classes of the judged directories that mean something other than "the input
     * could not be parsed", listed under the reason they mean it for.
     *
     * @var array<string, array<int, string>>
     */
    private const array EXEMPT = [
        'refused on meaning rather than on shape: the value parsed, and the domain said no. The shape half of'
        . ' this family is InvalidFormatException, which carries the marker' => [
            'DuplicateValueException',
            'EmptyValueException',
            'ItemNotFoundForDeleteException',
            'ItemNotFoundForUpdateException',
            'ValidationException',
            'ValueTooLongException',
            'ValueTooShortException',
        ],
        'the code is at fault, not the input: a caller handed a method something it cannot work with' => [
            'InvalidStateException',
            'LogicException',
            'MissingRequiredParameterException',
            'NotImplementedException',
            'UnsupportedOperationException',
        ],
        'two classes of the tree carry this name and neither is about parsing: the framework one blames the'
        . ' caller, the socket one is the EINVAL the kernel answered a syscall with' => ['InvalidArgumentException'],
        'the host failed the process — a file, a child — where no input was being read' => [
            'FileReadException',
            'ProcessException',
        ],
        'the agent subsystem could not find, start or wire something, so no payload was read at all' => [
            'AgentCreationFailedException',
            'AgentDaemonCreationFailedException',
            'AgentDaemonNotRegisteredException',
            'AgentException',
            'AgentIndexRequiredException',
            'AgentNotFoundException',
            'AgentNotLinkedToWorkerException',
            'NoSuitableWorkerException',
            'WorkerClientNotFoundException',
        ],
        'the agent index is built inside the process, so a malformed one is our own mistake' => [
            'InvalidAgentIndexException',
        ],
        'the name arrived through the router, which had already parsed it; what refused it is a handler that'
        . ' does not serve that name' => ['AgentUnknownActionException', 'AgentUnknownSignalException'],
        'the topology declares a payload DTO class that does not resolve — our own registry, read before any'
        . ' payload is' => ['BrokenSignalPayloadDtoException'],
        'the syscall named by the class failed; an errno says what the kernel refused, never what the bytes'
        . ' said' => [
            'SocketAcceptException',
            'SocketBindException',
            'SocketCloseException',
            'SocketConnectException',
            'SocketCreateException',
            'SocketGetPeerNameException',
            'SocketListenException',
            'SocketReadException',
            'SocketSelectException',
            'SocketSetNonBlockException',
            'SocketSetOptionException',
            'SocketWriteException',
        ],
        'one errno of the socket API given a name: the connection failed beside the reading rather than in it.'
        . ' MessageTooLongException is the one of this family that does judge the input, and it is marked' => [
            'AccessDeniedException',
            'AddressFamilyNotSupportedException',
            'AddressInUseException',
            'AddressNotAvailableException',
            'AlreadyConnectedException',
            'BrokenPipeException',
            'ConnectionAbortedException',
            'ConnectionRefusedException',
            'ConnectionResetException',
            'ConnectionTimeoutException',
            'HostUnreachableException',
            'InterruptedException',
            'InvalidSocketException',
            'NetworkDownException',
            'NetworkResetException',
            'NetworkUnreachableException',
            'NoBufferSpaceException',
            'NotConnectedException',
            'OperationNotPermittedException',
            'OutOfMemoryException',
            'TooManyOpenFilesException',
        ],
        'the node judges its own configuration or placement, decided from our config rather than from anything'
        . ' a peer sent' => [
            'ClusterConfigurationException',
            'ClusterDisabledException',
            'ClusterException',
            'PlacementCapabilityException',
        ],
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
     * @return iterable<Violation> One entry per class declaration that answers neither way
     */
    public function check(string $relativePath, array $tokens): iterable
    {
        $inWireDirectory = $this->isWireExceptionDirectory($relativePath);

        foreach ($this->declaredClasses($tokens) as $declaration) {
            [$name, $parent, $marked, $line] = $declaration;

            if (in_array($name, self::MARKED_BASES, true)) {
                if (!$marked) {
                    yield new Violation(
                        self::ID,
                        $relativePath,
                        $line,
                        $name . ' is a base its children inherit the marker through, and its own declaration'
                            . ' no longer implements ' . self::MARKER,
                    );
                }

                continue;
            }

            if (!$inWireDirectory || $marked || in_array($parent, self::MARKED_BASES, true)) {
                continue;
            }

            if (in_array($name, $this->exemptNames(), true)) {
                continue;
            }

            yield new Violation(
                self::ID,
                $relativePath,
                $line,
                $name . ' is declared where input is parsed and carries no ' . self::MARKER . '; implement it,'
                    . ' extend a marked base, or name the class in the rule\'s exempt list with a reason',
            );
        }
    }

    /**
     * The path is read relative to the scanned root, and only the directory holding
     * the file is compared, so a fixture tree that repeats a judged directory under a
     * prefix of its own is judged by the very same code as the real sources.
     *
     * @param string $relativePath File path relative to the scanned root
     * @return bool True when the file sits directly in one of the judged directories
     */
    private function isWireExceptionDirectory(string $relativePath): bool
    {
        $directory = '/' . dirname($relativePath);

        foreach (self::WIRE_EXCEPTION_DIRS as $wireDirectory) {
            if (str_ends_with($directory, '/' . $wireDirectory)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reads every class the file declares, with what it extends and whether the marker
     * is on it. An anonymous class and the `::class` constant both reach the tokenizer
     * as `T_CLASS` and neither declares a name, so both are walked past; an interface
     * is not read at all, since the marker itself is one.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return array<int, array{0: string, 1: ?string, 2: bool, 3: int}> Name, parent, marked flag and line
     */
    private function declaredClasses(array $tokens): array
    {
        $significant = array_values(array_filter(
            $tokens,
            static fn(string|array $token): bool => !is_array($token)
                || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        $declared = [];
        foreach ($significant as $index => $token) {
            if (!is_array($token) || $token[0] !== T_CLASS) {
                continue;
            }

            $previous = $significant[$index - 1] ?? null;
            if (is_array($previous) && in_array($previous[0], [T_DOUBLE_COLON, T_NEW], true)) {
                continue;
            }

            $name = $significant[$index + 1] ?? null;
            if (!is_array($name) || $name[0] !== T_STRING) {
                continue;
            }

            $header = $this->headerNames($significant, $index + 2);
            $declared[] = [$name[1], $header['parent'], in_array(self::MARKER, $header['interfaces'], true), $name[2]];
        }

        return $declared;
    }

    /**
     * Walks the declaration head up to the body and splits what it names. A name is
     * written either short or fully qualified and reaches the tokenizer whole, so only
     * its tail says which class is meant.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Significant tokens of the file
     * @param int $start Index of the token following the class name
     * @return array{parent: ?string, interfaces: array<int, string>} What the head extends and implements
     */
    private function headerNames(array $tokens, int $start): array
    {
        $parent = null;
        $interfaces = [];
        $collecting = null;

        for ($cursor = $start; isset($tokens[$cursor]); $cursor++) {
            $token = $tokens[$cursor];
            if ($token === '{' || $token === ';') {
                break;
            }
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_EXTENDS || $token[0] === T_IMPLEMENTS) {
                $collecting = $token[0];
                continue;
            }

            if ($collecting === null || !$this->isNameToken($token)) {
                continue;
            }

            if ($collecting === T_EXTENDS) {
                $parent = $this->shortName($token[1]);
                continue;
            }

            $interfaces[] = $this->shortName($token[1]);
        }

        return ['parent' => $parent, 'interfaces' => $interfaces];
    }

    /**
     * @param array{0: int, 1: string, 2: int} $token Token from a declaration head
     * @return bool True when the token spells a class name rather than punctuation
     */
    private function isNameToken(array $token): bool
    {
        return in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true);
    }

    /**
     * @param string $name Class name as the declaration spells it
     * @return string The name without its namespace
     */
    private function shortName(string $name): string
    {
        $separator = strrpos($name, '\\');

        return $separator === false ? $name : substr($name, $separator + 1);
    }

    /**
     * @return array<int, string> Every exempt class name, whichever reason it is listed under
     */
    private function exemptNames(): array
    {
        return array_merge(...array_values(self::EXEMPT));
    }
}
