<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Users\AccountMergeSummary;

/**
 * Sessions library → project agent: this is what the merge did (HIL-729).
 *
 * What {@see HilosSignalConstants::HILOS_ACCOUNT_MERGE_RESULT} carries, and the answer to
 * {@see AccountMergeSignalData} alone: an operator on the command channel is answered where
 * the work happened, because a parked socket is the library's to reply to, while a browser is
 * waiting under an ack name only its own project knows.
 *
 * The outcome is ONE field of two types rather than two nullable ones, because a merge has
 * one outcome and never half of each: what came back is either the summary or the sentence
 * that says why there is none. A refusal travels as its TEXT rather than as an exception
 * re-thrown on the far side - the guard that refused ran in another process, and what the
 * person asking is owed is the sentence it produced, not a stack it cannot see.
 *
 * The accept key comes back untouched from the request, so the project acks the one
 * connection that asked and nothing else - a merge is not news to anyone else's tab.
 */
final class AccountMergeResultSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $acceptKey Initiating connection accept key, carried over from the request
     * @param AccountMergeSummary|string $outcome What moved, or the sentence saying why nothing did
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly AccountMergeSummary|string $outcome,
    ) {
    }

    /**
     * Convert DTO to array for transport.
     *
     * The two outcomes take two keys rather than one, so a reader of the raw frame - a log
     * line, a test - can tell a refusal from a summary without knowing which shape to expect.
     *
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            'acceptKey' => $this->acceptKey,
            'summary' => is_string($this->outcome) ? null : $this->outcome->toArray(),
            'error' => is_string($this->outcome) ? $this->outcome : null,
        ];
    }

    /**
     * Create DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no key to ack, or not exactly one outcome
     */
    public static function fromArray(array $data): static
    {
        $acceptKey = self::requireString($data, 'acceptKey');
        $summary = self::optionalArray($data, 'summary');
        $error = self::optionalString($data, 'error');
        if ($summary !== null && $error !== null) {
            throw new InvalidFormatException('A merge result carries what moved or why nothing did, never both');
        }

        if ($summary !== null) {
            return new static($acceptKey, AccountMergeSummary::fromArray($summary));
        }

        if ($error === null) {
            throw new InvalidFormatException('A merge result carries neither what moved nor why nothing did');
        }

        return new static($acceptKey, $error);
    }
}
