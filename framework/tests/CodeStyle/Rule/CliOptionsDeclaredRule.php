<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\Throws\ClassRecord;
use Hilos\Tests\CodeStyle\Throws\CrossFileRule;
use Hilos\Tests\CodeStyle\Throws\MethodRecord;
use Hilos\Tests\CodeStyle\Throws\OptionAccess;
use Hilos\Tests\CodeStyle\Throws\SourceIndex;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Enforces the options list of commands.md: every option a command reads is named
 * in OPTIONS, and the name is the same string on both sides.
 *
 * The defect is a command that asks for a key the gate has already dropped. The
 * list is read off the class before the command runs, and a key that is not on it
 * never arrives in `$options`, so the read sees a missing value and cannot tell
 * that the operator did pass the option. The declaration is usually inherited and
 * the read often sits on a parent or a trait, which is why the check is cross-file.
 *
 * Only concrete classes are judged. An abstract command is a legal place to
 * declare and to read, and it is never itself required to answer: the class that
 * runs is the concrete one, and its OPTIONS is what the gate reads.
 *
 * A name is compared by its value, not by which constant spelled it. `self::X`
 * and a literal of the same string are one option. A key that is not a literal
 * and not a constant is a finding on its own: the guard cannot name it, so it
 * cannot know that the list covers it.
 *
 * Two things are not judged, on purpose. An option that is declared and never
 * read is the command's own business. A variable that holds the same array under
 * another name is invisible here: the gate's parameter is `$options`, and a copy
 * is a different variable.
 */
final class CliOptionsDeclaredRule implements CrossFileRule
{
    public const string ID = 'CLI-OPTIONS-DECLARED';

    private const string DOC = 'docs/agents/cli/commands.md';

    /** The interface every command reaches, and the only thing that makes a class a command here. */
    private const string COMMAND_INTERFACE = 'Hilos\Core\CLI\Commands\CommandInterface';

    /** Constant the option names are declared through. */
    private const string OPTIONS = 'OPTIONS';

    /** Spread the rule expands, and the only spread a declaration is written with. */
    private const string PARENT_OPTIONS = 'parent::options';

    private string $commandInterface;

    /**
     * @param string $commandInterface Fully qualified interface every judged command reaches
     */
    private function __construct(string $commandInterface)
    {
        $this->commandInterface = $commandInterface;
    }

    /**
     * @return self Rule that judges the command interface of the repository
     */
    public static function forCommandInterface(): self
    {
        return new self(self::COMMAND_INTERFACE);
    }

    /**
     * The interface is named from outside for the fixtures alone. A toy tree cannot
     * declare the production name — the fixture roots follow PSR-4 like every other
     * file under `framework/tests`, and a second declaration of a real interface
     * would collide with the one the autoloader already answers with.
     *
     * @param string $commandInterface Fully qualified interface every judged command reaches
     * @return self Rule that judges the named interface by the very same code
     */
    public static function forInterface(string $commandInterface): self
    {
        return new self($commandInterface);
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
     * @param SourceIndex $index Indexed source tree of every production root
     * @return iterable<Violation> One entry per read a concrete command does not declare, ordered by file and line
     */
    public function check(SourceIndex $index): iterable
    {
        $violations = [];
        foreach ($index->classes() as $class) {
            $violations = [...$violations, ...$this->judge($index, $class)];
        }

        usort($violations, static fn(Violation $left, Violation $right): int
            => [$left->relativePath, $left->line, $left->message] <=> [$right->relativePath, $right->line, $right->message]);

        yield from $violations;
    }

    /**
     * @param SourceIndex $index Indexed source tree
     * @param ClassRecord $class Class to judge
     * @return array<int, Violation> Hits on this command, empty when it is not a concrete command or every read is declared
     */
    private function judge(SourceIndex $index, ClassRecord $class): array
    {
        if (!$this->isCommand($index, $class)) {
            return [];
        }

        $declared = [];
        foreach ($this->declaredNames($index, $class) as $name) {
            $declared[$name] = true;
        }

        $violations = [];
        foreach ($this->applicableMethods($index, $class) as [$declaring, $binding, $method]) {
            foreach ($method->optionAccesses as $access) {
                $violation = $this->judgeAccess($index, $class, $binding, $declaring, $access, $declared);
                if ($violation !== null) {
                    $violations[] = $violation;
                }
            }
        }

        return $violations;
    }

    /**
     * A class is a command when it or a parent implements the command interface.
     * The interface itself is not a command: nothing concrete runs it. An abstract
     * class is not judged either — the concrete descendant answers for the reads
     * it inherits.
     *
     * @param SourceIndex $index Indexed source tree
     * @param ClassRecord $class Class to place
     * @return bool True when the class is a concrete command
     */
    private function isCommand(SourceIndex $index, ClassRecord $class): bool
    {
        if ($class->isAbstract || $class->name === $this->commandInterface) {
            return false;
        }

        $seen = [];
        $current = $class;
        while (!isset($seen[strtolower($current->name)])) {
            if (in_array($this->commandInterface, $current->interfaces, true)) {
                return true;
            }
            $seen[strtolower($current->name)] = true;
            if ($current->parent === null) {
                return false;
            }
            $parent = $index->find($current->parent);
            if ($parent === null) {
                return false;
            }
            $current = $parent;
        }

        return false;
    }

    /**
     * Methods that can run for this command: its own, then its traits, then the
     * same on each parent for names the child did not override. A private method
     * is never an override, so a parent's private helper is judged beside the
     * child's method of the same name.
     *
     * @param SourceIndex $index Indexed source tree
     * @param ClassRecord $command Concrete command being judged
     * @return array<int, array{0: ClassRecord, 1: ClassRecord, 2: MethodRecord}> Declaring class, class `self` binds to, and the method
     */
    private function applicableMethods(SourceIndex $index, ClassRecord $command): array
    {
        $found = [];
        $seen = [];
        $visited = [];
        $current = $command;
        while ($current !== null && !isset($visited[strtolower($current->name)])) {
            $visited[strtolower($current->name)] = true;
            foreach ($current->methods as $method) {
                $this->takeMethod($found, $seen, $current, $current, $method);
            }
            $traits = [];
            $this->takeTraits($index, $current, $current, $found, $seen, $traits);
            $current = $current->parent === null ? null : $index->find($current->parent);
        }

        return $found;
    }

    /**
     * @param array<int, array{0: ClassRecord, 1: ClassRecord, 2: MethodRecord}> $found Methods collected so far
     * @param array<string, true> $seen Method keys already taken, so an override hides the parent body
     * @param ClassRecord $declaring Class or trait the method is written on
     * @param ClassRecord $binding Class `self` and `parent` resolve against
     * @param MethodRecord $method Method to take
     */
    private function takeMethod(array &$found, array &$seen, ClassRecord $declaring, ClassRecord $binding, MethodRecord $method): void
    {
        $name = strtolower($method->name);
        $key = $method->visibility === MethodRecord::VISIBILITY_PRIVATE
            ? strtolower($declaring->name) . '::' . $name
            : $name;
        if (isset($seen[$key]) || !$method->hasBody) {
            return;
        }
        $seen[$key] = true;
        if ($method->optionAccesses === []) {
            return;
        }
        $found[] = [$declaring, $binding, $method];
    }

    /**
     * @param SourceIndex $index Indexed source tree
     * @param ClassRecord $owner Class or trait whose `use` list is being walked
     * @param ClassRecord $binding Class `self` and `parent` resolve against
     * @param array<int, array{0: ClassRecord, 1: ClassRecord, 2: MethodRecord}> $found Methods collected so far
     * @param array<string, true> $seen Method keys already taken
     * @param array<string, true> $visited Traits already walked, so a trait used twice is read once
     */
    private function takeTraits(
        SourceIndex $index,
        ClassRecord $owner,
        ClassRecord $binding,
        array &$found,
        array &$seen,
        array &$visited,
    ): void {
        foreach ($owner->traits as $traitName) {
            $key = strtolower($traitName);
            if (isset($visited[$key])) {
                continue;
            }
            $visited[$key] = true;
            $trait = $index->find($traitName);
            if ($trait === null) {
                continue;
            }
            foreach ($trait->methods as $method) {
                $this->takeMethod($found, $seen, $trait, $binding, $method);
            }
            $this->takeTraits($index, $trait, $binding, $found, $seen, $visited);
        }
    }

    /**
     * @param SourceIndex $index Indexed source tree
     * @param ClassRecord $command Concrete command being judged
     * @param ClassRecord $binding Class the read's `self` and `parent` resolve against
     * @param ClassRecord $declaring Class or trait the read is written in
     * @param OptionAccess $access One read or handoff
     * @param array<string, true> $declared Option names OPTIONS resolves to
     * @return ?Violation The hit, or null when the read is declared or the handoff stays in the command
     */
    private function judgeAccess(
        SourceIndex $index,
        ClassRecord $command,
        ClassRecord $binding,
        ClassRecord $declaring,
        OptionAccess $access,
        array $declared,
    ): ?Violation {
        if ($access->kind === OptionAccess::KIND_HANDOFF) {
            if ($this->isOwnCall($access)) {
                return null;
            }

            return new Violation(
                self::ID,
                $declaring->path,
                $access->line,
                $command->shortName() . ' passes $options whole to ' . $this->handoffTarget($access),
            );
        }

        $name = $access->kind === OptionAccess::KIND_KEY
            ? $this->optionName($index, $command, $binding, $access)
            : null;
        if ($name === null) {
            return new Violation(
                self::ID,
                $declaring->path,
                $access->line,
                $command->shortName() . ' reads $options with a key this guard cannot name',
            );
        }
        if (isset($declared[$name])) {
            return null;
        }

        return new Violation(
            self::ID,
            $declaring->path,
            $access->line,
            $command->shortName() . ' reads option "' . $name . '" and OPTIONS does not name it',
        );
    }

    /**
     * @param OptionAccess $access Handoff to classify
     * @return bool True when the callee is a direct method of the command's own hierarchy
     */
    private function isOwnCall(OptionAccess $access): bool
    {
        return in_array($access->calleeBase, [
            OptionAccess::BASE_THIS,
            OptionAccess::BASE_SELF,
            OptionAccess::BASE_STATIC,
            OptionAccess::BASE_PARENT,
        ], true);
    }

    /**
     * @param OptionAccess $access Handoff whose callee is outside the command
     * @return string How the report names the callee
     */
    private function handoffTarget(OptionAccess $access): string
    {
        if ($access->calleeBase === null || $access->callee === null) {
            return 'a call this guard cannot name';
        }
        if ($access->calleeBase === OptionAccess::BASE_FUNCTION && $access->callee !== '') {
            return $access->callee . '()';
        }
        if (
            $access->calleeBase !== ''
            && $access->calleeBase !== OptionAccess::BASE_FUNCTION
            && !str_starts_with($access->calleeBase, '$')
        ) {
            $separator = strrpos($access->calleeBase, '\\');
            $short = $separator === false ? $access->calleeBase : substr($access->calleeBase, $separator + 1);
            if ($access->callee === OptionAccess::CONSTRUCT) {
                return 'new ' . $short . '()';
            }
            if ($access->callee !== '') {
                return $short . '::' . $access->callee . '()';
            }
        }

        return 'a call this guard cannot name';
    }

    /**
     * @param SourceIndex $index Indexed source tree
     * @param ClassRecord $command Concrete command being judged, which is what `static::` binds to
     * @param ClassRecord $binding Class `self` and `parent` bind to
     * @param OptionAccess $access Key read whose name is a literal or a constant
     * @return ?string The option name, or null when the constant's value is not a string literal
     */
    private function optionName(SourceIndex $index, ClassRecord $command, ClassRecord $binding, OptionAccess $access): ?string
    {
        if ($access->literal !== null) {
            return $access->literal;
        }
        if ($access->constant === null) {
            return null;
        }

        $className = $this->constantClass($command, $binding, $access);
        if ($className === null) {
            return null;
        }

        return $this->stringLiteral($index->resolveConstant($className, $access->constant));
    }

    /**
     * @param ClassRecord $command Concrete command being judged
     * @param ClassRecord $binding Class `self` and `parent` bind to
     * @param OptionAccess $access Key read written as a constant
     * @return ?string Fully qualified class the constant is read on, or null when `parent` has none
     */
    private function constantClass(ClassRecord $command, ClassRecord $binding, OptionAccess $access): ?string
    {
        if ($access->scope === OptionAccess::BASE_SELF) {
            return $binding->name;
        }
        if ($access->scope === OptionAccess::BASE_STATIC) {
            return $command->name;
        }
        if ($access->scope === OptionAccess::BASE_PARENT) {
            return $binding->parent;
        }

        return $access->class;
    }

    /**
     * @param SourceIndex $index Indexed source tree
     * @param ClassRecord $command Concrete command whose OPTIONS is being resolved
     * @return array<int, string> Option names the nearest OPTIONS resolves to, spreads included
     */
    private function declaredNames(SourceIndex $index, ClassRecord $command): array
    {
        $names = [];
        $this->collectNames($index, $command, $command, [], $names);

        return array_keys($names);
    }

    /**
     * @param SourceIndex $index Indexed source tree
     * @param ClassRecord $command Concrete command being judged
     * @param ClassRecord $scope Class the walk is currently resolving OPTIONS on
     * @param array<string, true> $stack Classes already on this expansion, so a cycle stops
     * @param array<string, true> $names Names collected so far
     */
    private function collectNames(
        SourceIndex $index,
        ClassRecord $command,
        ClassRecord $scope,
        array $stack,
        array &$names,
    ): void {
        $owner = $this->optionsOwner($index, $scope);
        if ($owner === null) {
            return;
        }
        $key = strtolower($owner->name);
        if (isset($stack[$key])) {
            return;
        }
        $stack[$key] = true;
        $text = $owner->qualifiedConstants[self::OPTIONS] ?? null;
        if ($text === null) {
            return;
        }
        foreach ($this->elements($text) as $element) {
            if (str_starts_with($element, '...')) {
                $this->collectSpread($index, $command, $owner, $stack, $names, substr($element, 3));
                continue;
            }
            $name = $this->elementName($index, $command, $owner, $element);
            if ($name !== null) {
                $names[$name] = true;
            }
        }
    }

    /**
     * @param SourceIndex $index Indexed source tree
     * @param ClassRecord $command Concrete command being judged
     * @param ClassRecord $owner Class whose OPTIONS contains the spread
     * @param array<string, true> $stack Classes already on this expansion
     * @param array<string, true> $names Names collected so far
     * @param string $target Text after `...`
     */
    private function collectSpread(
        SourceIndex $index,
        ClassRecord $command,
        ClassRecord $owner,
        array $stack,
        array &$names,
        string $target,
    ): void {
        if (strtolower($target) !== self::PARENT_OPTIONS || $owner->parent === null) {
            return;
        }
        $parent = $index->find($owner->parent);
        if ($parent === null) {
            return;
        }
        $this->collectNames($index, $command, $parent, $stack, $names);
    }

    /**
     * The nearest class that declares OPTIONS. The interface's empty default is not
     * on the parent chain, and an absent declaration names nothing, which is the
     * same set as that default.
     *
     * @param SourceIndex $index Indexed source tree
     * @param ClassRecord $class Class the walk starts at
     * @return ?ClassRecord The class whose OPTIONS wins, or null when the chain declares none
     */
    private function optionsOwner(SourceIndex $index, ClassRecord $class): ?ClassRecord
    {
        $seen = [];
        $current = $class;
        while ($current !== null && !isset($seen[strtolower($current->name)])) {
            if (array_key_exists(self::OPTIONS, $current->qualifiedConstants)) {
                return $current;
            }
            $seen[strtolower($current->name)] = true;
            $current = $current->parent === null ? null : $index->find($current->parent);
        }

        return null;
    }

    /**
     * @param string $text Qualified value text of an OPTIONS constant
     * @return array<int, string> Elements, spreads included, quotes still on the literals
     */
    private function elements(string $text): array
    {
        if (!str_starts_with($text, '[') || !str_ends_with($text, ']')) {
            return [];
        }
        $inner = substr($text, 1, -1);
        if ($inner === '') {
            return [];
        }

        $elements = [];
        $current = '';
        $depth = 0;
        $quote = null;
        $length = strlen($inner);
        for ($index = 0; $index < $length; $index++) {
            $char = $inner[$index];
            if ($quote !== null) {
                $current .= $char;
                if ($char === '\\' && $index + 1 < $length) {
                    $current .= $inner[++$index];
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === "'" || $char === '"') {
                $quote = $char;
                $current .= $char;
                continue;
            }
            if ($char === '[' || $char === '(') {
                $depth++;
                $current .= $char;
                continue;
            }
            if (($char === ']' || $char === ')') && $depth > 0) {
                $depth--;
                $current .= $char;
                continue;
            }
            if ($char === ',' && $depth === 0) {
                if ($current !== '') {
                    $elements[] = $current;
                }
                $current = '';
                continue;
            }
            $current .= $char;
        }
        if ($current !== '') {
            $elements[] = $current;
        }

        return $elements;
    }

    /**
     * @param SourceIndex $index Indexed source tree
     * @param ClassRecord $command Concrete command being judged
     * @param ClassRecord $owner Class that declares the element
     * @param string $element One element of OPTIONS, as the qualified text spells it
     * @return ?string The option name, or null when the element is not a string literal or a constant of one
     */
    private function elementName(SourceIndex $index, ClassRecord $command, ClassRecord $owner, string $element): ?string
    {
        $literal = $this->stringLiteral($element);
        if ($literal !== null) {
            return $literal;
        }
        $separator = strrpos($element, '::');
        if ($separator === false) {
            return null;
        }
        $constant = substr($element, $separator + 2);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $constant) !== 1) {
            return null;
        }

        $head = substr($element, 0, $separator);
        $className = match (strtolower($head)) {
            OptionAccess::BASE_SELF => $owner->name,
            OptionAccess::BASE_STATIC => $command->name,
            OptionAccess::BASE_PARENT => $owner->parent,
            default => $head,
        };
        if ($className === null) {
            return null;
        }

        return $this->stringLiteral($index->resolveConstant($className, $constant));
    }

    /**
     * @param ?string $raw Value text of a constant, quotes included when it is a string
     * @return ?string The text inside the quotes, or null when the value is not a string literal
     */
    private function stringLiteral(?string $raw): ?string
    {
        if ($raw === null || strlen($raw) < 2) {
            return null;
        }
        $quote = $raw[0];
        if (($quote !== "'" && $quote !== '"') || !str_ends_with($raw, $quote)) {
            return null;
        }
        $inner = substr($raw, 1, -1);
        if (!str_contains($inner, '\\')) {
            return $inner;
        }
        if ($quote === "'") {
            return str_replace(["\\\\", "\\'"], ['\\', "'"], $inner);
        }

        return stripcslashes($inner);
    }
}
