<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Users library → admin page: what became of the rename (HIL-771).
 *
 * What {@see HilosSignalConstants::HILOS_USER_ADMIN_RENAME_DONE} carries, and the second half
 * of a two-step admin submit: the page handed the work over and stopped owing the caller an
 * answer, so this frame is the answer. It travels back with the accept key and the request id
 * it was given, which is what makes it land on the submit that asked.
 *
 * One outcome of two, told apart by the refusal: a sentence means the person was not renamed
 * and says why, and null means they were.
 */
final class AdminRenameDoneSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $acceptKey Accept key of the admin who asked
     * @param ?string $requestId Client-minted request id of a tracked submit, or null when untracked
     * @param ?string $error Why the rename was refused, or null when it went through
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly ?string $requestId = null,
        public readonly ?string $error = null,
    ) {
    }

    /**
     * Convert DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            'acceptKey' => $this->acceptKey,
            'requestId' => $this->requestId,
            'error' => $this->error,
        ];
    }

    /**
     * Create DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no connection to answer
     */
    public static function fromArray(array $data): static
    {
        return new static(
            acceptKey: self::requireString($data, 'acceptKey'),
            requestId: self::optionalString($data, 'requestId'),
            error: self::optionalString($data, 'error'),
        );
    }
}
