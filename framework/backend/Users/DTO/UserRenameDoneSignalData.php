<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * The person's agent → users library: the name is written, or why not (HIL-1404).
 *
 * What {@see HilosSignalConstants::HILOS_USER_RENAME_DONE} carries. The ask comes back whole, so
 * {@see AbstractUsersLibraryAgent} knows whom to answer and under which name without holding
 * anything between the hops. The journal row is named by id: null when nothing was written, either
 * because the person already carried the name or because the write was refused. The refusal is the
 * three fields of {@see ActionRefusal}.
 */
final class UserRenameDoneSignalData extends BaseDTO implements SignalDataInterface
{
    public const string ask = 'ask';
    public const string renameId = 'renameId';
    public const string error = 'error';
    public const string errorType = 'errorType';
    public const string errorDetail = 'errorDetail';

    /**
     * @param UserRenameSignalData $ask The ask being answered, untouched
     * @param ?int $renameId Journal row of the rename, or null when nothing was written
     * @param ?string $error Why the name was not written, or null when it went through
     * @param ?string $errorType Class name of the failure the refusal stands for, or null when nothing was held back
     * @param ?string $errorDetail Original message of that failure, or null when nothing was held back
     */
    public function __construct(
        public readonly UserRenameSignalData $ask,
        public readonly ?int $renameId,
        public readonly ?string $error,
        public readonly ?string $errorType,
        public readonly ?string $errorDetail,
    ) {
    }

    /**
     * Answers an ask with its outcome: the agent states the row or why not, the rest is the ask's.
     *
     * @param UserRenameSignalData $ask The ask being answered
     * @param ?int $renameId Journal row of the rename, or null when nothing was written
     * @param ?ActionRefusal $refusal Why the name was not written, or null when it went through
     * @return self Answer carrying the ask back
     */
    public static function to(UserRenameSignalData $ask, ?int $renameId, ?ActionRefusal $refusal): self
    {
        return new self($ask, $renameId, $refusal?->reason, $refusal?->errorType, $refusal?->errorDetail);
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Rename answer
     * @throws InvalidFormatException When the ask or the outcome fields are malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(
            ask: UserRenameSignalData::fromArray(self::requireArray($data, self::ask)),
            renameId: self::optionalInt($data, self::renameId),
            error: self::optionalString($data, self::error),
            errorType: self::optionalString($data, self::errorType),
            errorDetail: self::optionalString($data, self::errorDetail),
        );
    }

    /** @return array<string, mixed> Transport payload */
    public function toArray(): array
    {
        return [
            self::ask => $this->ask->toArray(),
            self::renameId => $this->renameId,
            self::error => $this->error,
            self::errorType => $this->errorType,
            self::errorDetail => $this->errorDetail,
        ];
    }
}
