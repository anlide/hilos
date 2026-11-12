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
 * The person's agent → sessions library: the trust is extended, there is no pair to extend, or why not (HIL-1907).
 *
 * What {@see HilosSignalConstants::HILOS_USER_BROWSER_TRUST_RENEW_DONE} carries. The request comes
 * back whole; {@see AbstractSessionsLibraryAgent} reads whether the pair stood from renewed and a
 * refusal, the three fields of {@see ActionRefusal}, into its log. A pair granted later already
 * counts as renewed: the request found it standing and left it as it was.
 */
final class UserBrowserTrustRenewDoneSignalData extends BaseDTO implements SignalDataInterface
{
    public const string request = 'request';
    public const string renewed = 'renewed';
    public const string error = 'error';
    public const string errorType = 'errorType';
    public const string errorDetail = 'errorDetail';

    /**
     * @param UserBrowserTrustRenewSignalData $request The request being answered, untouched
     * @param bool $renewed Whether the pair stood - extended now or granted later already; false when there was none or on a refusal
     * @param ?string $error Why the trust was not extended, or null when the request was carried out
     * @param ?string $errorType Class name of the failure the refusal stands for, or null when nothing was held back
     * @param ?string $errorDetail Original message of that failure, or null when nothing was held back
     * @throws InvalidFormatException When the answer is both renewed and refused
     */
    public function __construct(
        public readonly UserBrowserTrustRenewSignalData $request,
        public readonly bool $renewed,
        public readonly ?string $error,
        public readonly ?string $errorType,
        public readonly ?string $errorDetail,
    ) {
        if ($renewed && $error !== null) {
            throw new InvalidFormatException('A renewed trust carries no refusal');
        }
    }

    /**
     * @param UserBrowserTrustRenewSignalData $request The request being answered
     * @param bool $renewed Whether the pair stood, read only when nothing was refused
     * @param ?ActionRefusal $refusal Why the trust was not extended, or null when the request was carried out
     * @return self Answer carrying the request back, renewed only when nothing was refused
     * @throws InvalidFormatException When the answer is both renewed and refused
     */
    public static function to(UserBrowserTrustRenewSignalData $request, bool $renewed, ?ActionRefusal $refusal): self
    {
        return new self($request, $renewed && $refusal === null, $refusal?->reason, $refusal?->errorType, $refusal?->errorDetail);
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Trust renewal answer
     * @throws InvalidFormatException When the request or the outcome fields are malformed, or the answer is both renewed and refused
     */
    public static function fromArray(array $data): static
    {
        return new static(
            request: UserBrowserTrustRenewSignalData::fromArray(self::requireArray($data, self::request)),
            renewed: self::requireBool($data, self::renewed),
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
            self::renewed => $this->renewed,
            self::error => $this->error,
            self::errorType => $this->errorType,
            self::errorDetail => $this->errorDetail,
        ];
    }
}
