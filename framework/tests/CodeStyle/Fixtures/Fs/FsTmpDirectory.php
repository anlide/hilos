<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Fs;

use Hilos\Utils\Helpers\RandomHelper;

/**
 * Negative sample under one of the paths the rule's list names, repeated here the
 * way the empty-string fixtures repeat the segments of the real zone. It must stay
 * silent: a temporary directory's name only has to be unlikely to collide, and a
 * hit here would mean the exemption stopped working and every listed caller would
 * light up next.
 *
 * The path is what the rule matches, so the file has to be spelled exactly like the
 * entry that allows it; the body is a fixture and only pretends to be that class.
 */
final class FsTmpDirectory
{
    /**
     * @return string Name a colliding value would only cost a retry
     */
    public function name(): string
    {
        return 'hilos-' . RandomHelper::hex(16);
    }
}
