<?php

declare(strict_types=1);

namespace Hilos\Auth\Verification;

use Hilos\Auth\MagicLink\MagicLinkUrl;

/**
 * VerificationDeliverable - what a freshly issued challenge hands its transport (HIL-606).
 *
 * The seam used to carry one string, and that worked while every type delivered one
 * secret. Magic-link sign-in delivers two in one letter - a clickable URL and the six
 * digits a person on a second device types instead - so the transport now receives a
 * value that says which shape it got: {@see code()} for the single-secret types,
 * {@see magicLink()} for the pair.
 *
 * A type rather than a second optional argument on {@see VerificationDeliverer::deliver()}
 * on purpose. An optional `?string $link` would be a flag in the signature of a seam:
 * every implementation would have to re-derive "am I in the link case" from a null check,
 * and nothing would stop a caller passing a link for a type that has none. Typing the
 * shape instead of null-checking it is the precedent set by HIL-576.
 *
 * The URL is assembled one level above the transport, in {@see VerificationService::issue()},
 * because there are four deliverers and all of them owe the recipient the same address -
 * a URL built inside the mail deliverer would leave the dev-stub log with a token nobody
 * can click ({@see MagicLinkUrl::build()}). What is STORED is unaffected either way: each
 * half is hashed on its own challenge row.
 */
final readonly class VerificationDeliverable
{
    /**
     * @param string $code Plaintext code the recipient types, always present
     * @param ?string $link Assembled sign-in URL, null for every type that delivers no link
     */
    private function __construct(
        public string $code,
        public ?string $link,
    ) {
    }

    /**
     * Builds the deliverable of a type whose whole content is one typed secret.
     *
     * @param string $code Plaintext code (or token) to deliver
     * @return self Deliverable carrying no link
     */
    public static function code(string $code): self
    {
        return new self($code, null);
    }

    /**
     * Builds the deliverable of a magic-link letter: the address to click and the code to type.
     *
     * @param string $url Assembled sign-in URL the recipient clicks
     * @param string $code Plaintext companion code the recipient may type instead
     * @return self Deliverable carrying both halves of the letter
     */
    public static function magicLink(string $url, string $code): self
    {
        return new self($code, $url);
    }
}
