<?php

declare(strict_types=1);

namespace Hilos\Auth\Session\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionReplyDTO;

/**
 * OAuthResumeReplyDTO - whether the session holder knows the trip a tab presented (HIL-1044).
 *
 * The answer says nothing about how the sign-in went: that arrives the way it always does, on the
 * result signal or as the identity coming up. What it says is whether anything is still coming.
 * A holder that knows the key has moved the outcome onto this connection; one that does not has
 * nothing to give, and the tab ends its trip rather than wait for a frame that no one owes it.
 */
final class OAuthResumeReplyDTO extends ActionReplyDTO
{
    /** Wire key for whether the presented trip is known. */
    public const string known = 'known';

    /**
     * @param bool $known Whether the holder keeps a trip under the presented key for this session
     */
    public function __construct(
        public readonly bool $known,
    ) {
    }

    /**
     * @return array<string, bool> Reply payload
     */
    public function toArray(): array
    {
        return [self::known => $this->known];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static Restored DTO instance
     * @throws InvalidFormatException When the answer is missing or not a boolean
     */
    public static function fromArray(array $data): static
    {
        return new static(known: self::requireBool($data, self::known));
    }
}
