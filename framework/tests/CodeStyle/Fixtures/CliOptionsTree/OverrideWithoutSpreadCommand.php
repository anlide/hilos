<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\CliOptionsTree;

/**
 * Replaces the parent's OPTIONS and does not spread it, so the inherited read
 * names an option this command no longer declares.
 */
final class OverrideWithoutSpreadCommand extends AbstractParentCommand
{
    /** @var list<string> Option names this command reads */
    public const array OPTIONS = ['own'];
}
