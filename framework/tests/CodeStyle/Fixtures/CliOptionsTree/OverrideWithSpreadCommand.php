<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\CliOptionsTree;

/**
 * Spreads the parent's OPTIONS, so the inherited read stays declared.
 */
final class OverrideWithSpreadCommand extends AbstractParentCommand
{
    /** @var list<string> Option names this command reads */
    public const array OPTIONS = [
        ...parent::OPTIONS,
    ];
}
