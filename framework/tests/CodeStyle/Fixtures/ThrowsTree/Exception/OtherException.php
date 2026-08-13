<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception;

/**
 * A sibling of {@see NarrowException}: neither covers the other, which is what the
 * seeded "narrow tag over a wider callee" case needs.
 */
final class OtherException extends TreeException
{
}
