<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * The person's agent → sessions library: the command's admin flag is written, or why not (HIL-1404).
 *
 * What {@see HilosSignalConstants::HILOS_USER_ADMIN_COMMAND_DONE} carries. The request comes back
 * whole, so {@see AbstractSessionsLibraryAgent} binds the session or tells the tabs and answers the
 * parked command from the frame alone; the refusal is the three fields of {@see ActionRefusal}.
 */
final class UserAdminCommandDoneSignalData extends BaseDTO implements SignalDataInterface
{
    public const string request = 'request';
    public const string error = 'error';
    public const string errorType = 'errorType';
    public const string errorDetail = 'errorDetail';

    /**
     * @param UserAdminCommandSignalData $request The request being answered, untouched
     * @param ?string $error Why the flag was not written, or null when it went through
     * @param ?string $errorType Class name of the failure the refusal stands for, or null when nothing was held back
     * @param ?string $errorDetail Original message of that failure, or null when nothing was held back
     */
    public function __construct(
        public readonly UserAdminCommandSignalData $request,
        public readonly ?string $error,
        public readonly ?string $errorType,
        public readonly ?string $errorDetail,
    ) {
    }

    /**
     * @param UserAdminCommandSignalData $request The request being answered
     * @param ?ActionRefusal $refusal Why the flag was not written, or null when it went through
     * @return self Answer carrying the request back
     */
    public static function to(UserAdminCommandSignalData $request, ?ActionRefusal $refusal): self
    {
        return new self($request, $refusal?->reason, $refusal?->errorType, $refusal?->errorDetail);
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Command admin flag answer
     * @throws InvalidFormatException When the request or the outcome fields are malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(
            request: UserAdminCommandSignalData::fromArray(self::requireArray($data, self::request)),
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
            self::error => $this->error,
            self::errorType => $this->errorType,
            self::errorDetail => $this->errorDetail,
        ];
    }
}
