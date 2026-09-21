<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good;

use Attribute;

/**
 * Attribute used to verify multiline attribute indentation.
 */
#[Attribute]
final class MemberIndentSampleAttribute
{
    /**
     * Constructor using promoted properties inside parentheses.
     *
     * @param string $first First argument
     * @param string $second Second argument
     */
    public function __construct(
        public string $first,
        public string $second,
    ) {
    }
}

/**
 * Trait used to verify trait use adaptation syntax.
 */
trait MemberIndentLookAlikeTrait
{
    public function originalMethod(): void
    {
    }
}

/**
 * Enum with backing type used to verify enum body recognition.
 */
enum MemberIndentLookAlikeEnum: string
{
    case First = 'first';
    case Second = 'second';
}

/**
 * Negative sample: look-alikes that resemble mis-indented members or class openings
 * but must produce zero hits under MEMBER-INDENT.
 */
final class MemberIndentLookAlikes
{
    use MemberIndentLookAlikeTrait {
        originalMethod as adaptedMethod;
    }

    public string $hookedProperty = '' {
        get {
            return $this->hookedProperty;
        }
        set (string $value) {
            $this->hookedProperty = $value;
        }
    }

    /**
     * Promoted constructor properties indented inside parentheses.
     *
     * @param int $promotedField Promoted field
     * @param string $anotherField Another field
     */
    public function __construct(
        public int $promotedField = 0,
        private string $anotherField = '',
    ) {
    }

    #[MemberIndentSampleAttribute(
        'first-argument',
        'second-argument',
    )]
    public function attributedMethod(): void
    {
    }

    public static function staticTarget(): void
    {
    }

    public function methodWithStaticCache(): void
    {
        static $cache = [];
        $cache[] = 1;
    }

    public function methodWithSwitch(int $value): void
    {
        switch ($value) {
            case 1:
                static::staticTarget();
                break;
        }
    }

    /**
     * @param callable $callable Callable argument
     * @param string $matched Matched string
     */
    public function consumer(callable $callable, string $matched): void
    {
    }

    public function callWithClosureAndMatch(int $input): void
    {
        $this->consumer(
            static function (): string {
                return 'result';
            },
            match ($input) {
                1 => 'one',
                default => 'other',
            },
        );
    }

    public function classReferenceBeforeBlock(): void
    {
        $ref = MemberIndentLookAlikeEnum::class;
        {
            $local = $ref;
        }
    }

    /**
     * @param string $class Class name argument
     */
    private function takeNamedClass(string $class): void
    {
    }

    public function namedClassBeforeIf(bool $condition, int $selector): void
    {
        $this->takeNamedClass(class: 'example');
        if ($condition) {
            switch ($selector) {
                case 1:
                    static::staticTarget();
                    break;
            }
        }
    }

    /**
     * @return object Nested anonymous class instance
     */
    public function nestedAnonymousClasses(): object
    {
        return new class {
            /**
             * @return object Inner anonymous class instance
             */
            public function innerFactory(): object
            {
                return new class {
                    public function innerMethod(): void
                    {
                    }
                };
            }
        };
    }

    public function stringInterpolationBeforeNextMember(): void
    {
        $variable = 'value';
        $interpolated = "prefix {$variable} suffix";
    }

    public function memberFollowingInterpolation(): void
    {
    }
}
