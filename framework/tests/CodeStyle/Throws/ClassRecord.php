<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Throws;

/**
 * One class, interface, trait or enum as the index read it, with the links a call
 * target is resolved through, the property types a receiver is resolved through and
 * the declarations a rule judges the class itself by — its constants, the qualified
 * class names stored beside their raw value text, whether it is abstract, and where
 * it stands.
 *
 * The types a class-level `@property-read` or `@property` tag writes down are kept
 * apart from the real declarations rather than merged into them: a declaration inside
 * the class is stronger than a tag on the same class, and a tag naming a class the
 * index does not hold must not look found — a merged map could say neither.
 */
final readonly class ClassRecord
{
    /**
     * @param string $name Fully qualified name
     * @param string $path File path, addressed from the root the index was built over
     * @param ?string $parent Fully qualified parent class, null when there is none
     * @param array<int, string> $interfaces Fully qualified implemented or extended interfaces
     * @param array<int, string> $traits Fully qualified used traits
     * @param array<string, MethodRecord> $methods Methods keyed by lowercased name
     * @param array<string, string> $propertyTypes Declared type by property name, a leading `$` marking a static one
     * @param array<string, string> $docPropertyTypes Declared type by property name, as a class-level @property-read or @property tag wrote it down
     * @param array<string, string> $constants Raw value text by constant name, as it stands after the `=`
     * @param array<string, string> $constantClasses Fully qualified class by constant name, for a constant whose value is `X::class`
     * @param bool $isAbstract True when the declaration carries the `abstract` modifier
     * @param int $line Line the declaration sits on, which is where a hit about the class as a whole is reported
     */
    public function __construct(
        public string $name,
        public string $path,
        public ?string $parent,
        public array $interfaces,
        public array $traits,
        public array $methods,
        public array $propertyTypes,
        public array $docPropertyTypes,
        public array $constants,
        public array $constantClasses,
        public bool $isAbstract,
        public int $line,
    ) {
    }

    /**
     * @return string The name without its namespace, the way a report names a class
     */
    public function shortName(): string
    {
        $separator = strrpos($this->name, '\\');

        return $separator === false ? $this->name : substr($this->name, $separator + 1);
    }
}
