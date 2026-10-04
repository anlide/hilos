<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth;

/** The provider account proved by a signed first sign-in token (HIL-1235). */
final readonly class OAuthAccountTokenData
{
    /**
     * @param string $provider Provider key
     * @param string $subject Provider-immutable account id
     * @param ?string $email Provider-reported address, when supplied
     * @param string $displayName Name reported by the provider
     */
    public function __construct(
        public string $provider,
        public string $subject,
        public ?string $email,
        public string $displayName,
    ) {
    }
}
