<?php

declare(strict_types=1);

namespace Hilos\Testing;

use Attribute;

/** Opts a concrete test class into the rollback integration lifecycle. */
#[Attribute(Attribute::TARGET_CLASS)]
final class IntegrationTest
{
}
