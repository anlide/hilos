<?php

declare(strict_types=1);

namespace Hilos\Testing;

use Attribute;

/** Requests cold ORM reset after rollback for a marked integration class or case. */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class BreaksOrmState
{
}
