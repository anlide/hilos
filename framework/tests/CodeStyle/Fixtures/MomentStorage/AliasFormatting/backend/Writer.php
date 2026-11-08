<?php

declare(strict_types=1);

namespace MomentAlias;

use MomentFixtures\EventEntity;

class Writer
{
    public function write(EventEntity $entity): void
    {
        $now =
            time(/* clock */);
        // Same significant tokens, with different line numbers and a doc comment.
        $entity /* receiver */ -> at = ( int ) /** value */ $now;
    }
}
