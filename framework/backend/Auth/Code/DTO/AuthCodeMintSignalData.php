<?php

declare(strict_types=1);

namespace Hilos\Auth\Code\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\SignalDataInterface;

/** Code agent → users library: issue only after the channel proved reachable (HIL-1411). */
final class AuthCodeMintSignalData extends BaseDTO implements SignalDataInterface
{
    public const string request = 'request';
    public const string probeToken = 'probeToken';

    /**
     * @param AuthCodeSendSignalData $request Complete code request
     * @param ?string $probeToken Channel probe handle reused by delivery
     */
    public function __construct(
        public readonly AuthCodeSendSignalData $request,
        public readonly ?string $probeToken,
    ) {
    }

    /** @return array<string, mixed> Payload for transport */
    public function toArray(): array
    {
        return [
            self::request => $this->request->toArray(),
            self::probeToken => $this->probeToken,
        ];
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Parsed signal payload
     * @throws InvalidFormatException When a field is missing, mistyped or inconsistent
     * @throws ValidationException When the nested accepted revision map is malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(
            request: AuthCodeSendSignalData::fromArray(self::requireArray($data, self::request)),
            probeToken: self::optionalString($data, self::probeToken),
        );
    }
}
