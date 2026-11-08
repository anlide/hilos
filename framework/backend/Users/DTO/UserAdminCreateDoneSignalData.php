<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Users library → sessions holder: the new administrator's id or a refusal. */
final class UserAdminCreateDoneSignalData extends BaseDTO implements SignalDataInterface
{
    public const string request = 'request';
    public const string userId = 'userId';
    public const string error = 'error';
    public const string errorType = 'errorType';
    public const string errorDetail = 'errorDetail';

    /**
     * @param UserAdminCreateSignalData $request Original request, untouched
     * @param ?int $userId Created administrator, or null on refusal
     * @param ?string $error Refusal words, or null on success
     * @param ?string $errorType Internal failure class, when withheld from the refusal
     * @param ?string $errorDetail Internal failure message, when withheld from the refusal
     * @throws InvalidFormatException When there is no single outcome or the created id is not positive
     */
    public function __construct(
        public readonly UserAdminCreateSignalData $request,
        public readonly ?int $userId,
        public readonly ?string $error,
        public readonly ?string $errorType,
        public readonly ?string $errorDetail,
    ) {
        if (($userId === null) === ($error === null) || ($userId !== null && $userId <= 0)) {
            throw new InvalidFormatException('Admin creation needs exactly one positive user id or refusal');
        }
    }

    /**
     * @param UserAdminCreateSignalData $request Request being answered
     * @param int $userId Created administrator
     * @return self Successful creation answer
     * @throws InvalidFormatException When the created id is not positive
     */
    public static function created(UserAdminCreateSignalData $request, int $userId): self
    {
        return new self($request, $userId, null, null, null);
    }

    /**
     * @param UserAdminCreateSignalData $request Request being answered
     * @param ActionRefusal $refusal Why creation failed
     * @return self Refused creation answer
     * @throws InvalidFormatException When the answer has no single outcome
     */
    public static function refused(UserAdminCreateSignalData $request, ActionRefusal $refusal): self
    {
        return new self($request, null, $refusal->reason, $refusal->errorType, $refusal->errorDetail);
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Administrator creation answer
     * @throws InvalidFormatException When the request or outcome is malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(
            request: UserAdminCreateSignalData::fromArray(self::requireArray($data, self::request)),
            userId: self::optionalInt($data, self::userId),
            error: self::optionalString($data, self::error),
            errorType: self::optionalString($data, self::errorType),
            errorDetail: self::optionalString($data, self::errorDetail),
        );
    }

    /** @return array<string, mixed> Transport payload */
    public function toArray(): array
    {
        return [
            self::request => $this->request->toArray(),
            self::userId => $this->userId,
            self::error => $this->error,
            self::errorType => $this->errorType,
            self::errorDetail => $this->errorDetail,
        ];
    }
}
