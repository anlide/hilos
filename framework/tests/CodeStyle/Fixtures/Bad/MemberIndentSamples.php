<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad;

use Override;

/**
 * Trait used to seed an off-grid trait use statement.
 */
trait MemberIndentSampleTrait
{
}

/**
 * Enum used to seed an off-grid enum case statement.
 */
enum MemberIndentSampleEnum
{
  case Pending;
}

/**
 * Deliberately broken sample: each form below carries a mis-indented declaration line
 * directly inside a class, enum, or anonymous class body.
 *
 * MEMBER-INDENT must report exactly one hit per seeded form on its starting token line.
 */
final class MemberIndentSamples
{
  use MemberIndentSampleTrait;

	public const string TAB_CONST = 'tab';

  private string $misalignedProperty = 'property';

      /**
       * Docblock indented six spaces instead of four.
       */
    public function docblockAtSix(): void
    {
    }

  #[Override]
    public function attributeOffGrid(): void
    {
    }

    /**
     * Replicates the HIL-959 indentation shape where the signature was indented eight spaces.
     */
        public function hil959Signature(): void
    {
    }

  static function staticWithoutVisibility(): void
  {
  }

    /**
     * @return object Anonymous class instance
     */
    public function anonymousClassReturn(): object
    {
        return new class {
                public function misalignedReturnMember(): void
                {
                }
        };
    }

    /**
     * @param object $argument Anonymous class argument
     */
    public function anonymousClassArgument(object $argument): void
    {
        $this->anonymousClassArgument(new class {
                public function misalignedArgumentMember(): void
                {
                }
        });
    }
}
