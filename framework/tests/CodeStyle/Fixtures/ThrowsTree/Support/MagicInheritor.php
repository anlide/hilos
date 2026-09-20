<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support;

use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception\NarrowException;
use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception\OtherException;

/** Seeds a magic read whose reader is declared only on the parent. */
final class MagicInheritor extends MagicHolder
{
    /**
     * @return string What the quiet source said
     * @throws NarrowException The live contract of the inherited reader
     * @throws OtherException A tag the inherited reader does not back
     */
    public function keepsADeadTagPastAnInheritedReader(): string
    {
        return $this->quiet->speak();
    }
}
