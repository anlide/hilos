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
 * The person's agent → sessions library: the trust is taken away, or why not (HIL-1407).
 *
 * What {@see HilosSignalConstants::HILOS_USER_BROWSER_TRUST_REVOKE_DONE} carries. The request comes
 * back whole; {@see AbstractSessionsLibraryAgent} reads only a refusal, the three fields of
 * {@see ActionRefusal}, into its log.
 */
final class UserBrowserTrustRevokeDoneSignalData extends BaseDTO implements SignalDataInterface
{
    public const string request = 'request';
    public const string error = 'error';
    public const string errorType = 'errorType';
    public const string errorDetail = 'errorDetail';

    /**
     * @param UserBrowserTrustRevokeSignalData $request The request being answered, untouched
     * @param ?string $error Why the trust was not taken away, or null when it was
     * @param ?string $errorType Class name of the failure the refusal stands for, or null when nothing was held back
     * @param ?string $errorDetail Original message of that failure, or null when nothing was held back
     */
    public function __construct(
        public readonly UserBrowserTrustRevokeSignalData $request,
        public readonly ?string $error,
        public readonly ?string $errorType,
        public readonly ?string $errorDetail,
    ) {
    }

    /**
     * @param UserBrowserTrustRevokeSignalData $request The request being answered
     * @param ?ActionRefusal $refusal Why the trust was not taken away, or null when it was
     * @return self Answer carrying the request back
     */
    public static function to(UserBrowserTrustRevokeSignalData $request, ?ActionRefusal $refusal): self
    {
        return new self($request, $refusal?->reason, $refusal?->errorType, $refusal?->errorDetail);
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Trust revocation answer
     * @throws InvalidFormatException When the request or the outcome fields are malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(
            request: UserBrowserTrustRevokeSignalData::fromArray(self::requireArray($data, self::request)),
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
