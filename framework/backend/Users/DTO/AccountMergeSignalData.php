<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Project agent → sessions library: fold this account into that one (HIL-378, HIL-729).
 *
 * What {@see HilosSignalConstants::HILOS_ACCOUNT_MERGE} carries, and the browser's way into
 * the same core an operator reaches through {@see CliCommands::ACCOUNT_MERGE}. The admin
 * table submits the merge as a page action; the page cannot run it, because the merge ends
 * in the loser's live sessions being signed out and those sessions are the library's, so it
 * names the two accounts here and lets the library do the work.
 *
 * The accept key is the person waiting, not a party to the merge: the library hands it back
 * untouched on {@see HilosSignalConstants::HILOS_ACCOUNT_MERGE_RESULT} so the project can ack
 * the one connection that asked, under the name its own surface listens for. There is no
 * password fate among the fields on purpose - naming which of two passwords survives is an
 * operator's decision on a command line, and the admin surface has no control for it
 * (HIL-411); a merge that needs the decision is refused rather than guessed at.
 */
final class AccountMergeSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param int $survivorUserId Survivor user id that absorbs the loser
     * @param int $loserUserId Loser user id folded into the survivor
     * @param string $acceptKey Initiating connection accept key to ack with the result
     */
    public function __construct(
        public readonly int $survivorUserId,
        public readonly int $loserUserId,
        public readonly string $acceptKey,
    ) {
    }

    /**
     * Convert DTO to array for transport.
     *
     * @return array<string, int|string> DTO data as array
     */
    public function toArray(): array
    {
        return [
            'survivorUserId' => $this->survivorUserId,
            'loserUserId' => $this->loserUserId,
            'acceptKey' => $this->acceptKey,
        ];
    }

    /**
     * Create DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names neither side of the merge or no key to ack
     */
    public static function fromArray(array $data): static
    {
        return new static(
            survivorUserId: self::requireInt($data, 'survivorUserId'),
            loserUserId: self::requireInt($data, 'loserUserId'),
            acceptKey: self::requireString($data, 'acceptKey'),
        );
    }
}
