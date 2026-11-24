<?php

declare(strict_types=1);

namespace Demo\Chat\Moderation;

use Hilos\Runtime\State\Item\HilosModerationCheck;

/** @internal A scheduler coordinate. The decision and all content remain in the runtime rows. */
final readonly class MessageModerationDeadline
{
    /**
     * @param string $acceptKey Connection that accepted the draft
     * @param ?string $kind Check kind, or null for an accepted publication
     * @param string $token Check request id or publication hold id
     * @param int $userId Author of this attempt
     * @param int $deadlineAt Original deadline in epoch milliseconds
     */
    public function __construct(
        public string $acceptKey,
        public ?string $kind,
        public string $token,
        public int $userId,
        public int $deadlineAt,
    ) {
    }

    /** @return string Schedule slot for this check kind or connection publication */
    public function address(): string
    {
        return $this->kind === null ? $this->acceptKey : HilosModerationCheck::keyFor($this->acceptKey, $this->kind);
    }
}
