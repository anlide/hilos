<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Worker\WorkerDTO;

/** Terminal binary deliveries, reported without payloads or caller-supplied byte counts. */
final class WorkerUploadConsumedDTO extends WorkerDTO
{
    public const string RECEIPT_IDS = 'receiptIds';
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_UPLOAD_CONSUMED;

    /**
     * @param list<string> $receiptIds Receipts processed or finally discarded by this worker channel
     * @throws InvalidFormatException When receipts are not a list of strings
     */
    public function __construct(public readonly array $receiptIds)
    {
        if (!array_is_list($receiptIds)) {
            throw new InvalidFormatException('Upload receipts must be a list of strings');
        }
        foreach ($receiptIds as $receiptId) {
            if (!is_string($receiptId)) {
                throw new InvalidFormatException('Upload receipts must be a list of strings');
            }
        }
    }

    /** @return string Worker message type */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /** @return array<string, mixed> Worker wire payload */
    public function toArray(): array
    {
        return [self::TYPE => self::MESSAGE_TYPE, self::RECEIPT_IDS => $this->receiptIds];
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static Decoded terminal receipts
     * @throws InvalidFormatException When receiptIds is absent or is not a list of strings
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireArray($data, self::RECEIPT_IDS));
    }
}
