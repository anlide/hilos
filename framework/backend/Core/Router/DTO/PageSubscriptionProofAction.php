<?php

declare(strict_types=1);

namespace Hilos\Core\Router\DTO;

/** Opts a page action into receiving proof metadata from its original action envelope. */
interface PageSubscriptionProofAction
{
    /**
     * @param ?string $pageSubscriptionProof Proof carried by the original action frame
     * @param ?string $deliveryId Subscription authority attested by the receiving socket
     * @return static Payload with its envelope metadata attached for the handler
     */
    public function withPageSubscriptionProof(?string $pageSubscriptionProof, ?string $deliveryId): static;
}
