<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Agents;

/** The audience prepared with a full product page answer. */
final readonly class ProductViewerAnswer
{
    /**
     * @param array<string, ?string> $viewers Viewer keys by accept key, including tabs awaiting identity
     * @param array<string, int> $groups Number of answered tabs per known identity
     * @param int $otherViewers Distinct viewers besides the recipient
     */
    public function __construct(
        public array $viewers,
        public array $groups,
        public int $otherViewers,
    ) {
    }
}
