<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Browser;

/** Compact comparison values attached to the worker-local resource of an answered card. */
final readonly class ProductPageBasis
{
    /**
     * @param int $categoryId Category the answer read
     * @param string $fingerprint Digest of the source values carried by the card
     */
    public function __construct(
        public int $categoryId,
        public string $fingerprint,
    ) {
    }
}
