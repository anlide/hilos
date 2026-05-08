<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\DTO\FrontendChangesDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Server → client: current connection-local chat session state.
 */
final class SelfConnectionSignalData extends BaseDTO implements SignalDataInterface
{
    public const string selfConnection = 'selfConnection';
    public const string userId = 'userId';
    public const string connectedAt = 'connectedAt';
    public const string messageRateLimitSecondsRemaining = 'messageRateLimitSecondsRemaining';
    public const string outboundModerationState = 'outboundModerationState';
    public const string fileUploadState = 'fileUploadState';
    public const string phase = 'phase';
    public const string clientUploadId = 'clientUploadId';
    public const string errorCode = 'errorCode';
    public const string errorMessage = 'errorMessage';
    public const string fileUploadProgress = 'fileUploadProgress';
    public const string filename = 'filename';
    public const string uploadedBytes = 'uploadedBytes';
    public const string totalBytes = 'totalBytes';
    private const string frontend = 'frontend';

    /**
     * @param array<string, mixed> $selfConnection Browser-safe connection summary
     * @param ?FrontendChangesDTO $frontend Optional frontend state collection changes
     */
    public function __construct(
        public readonly array $selfConnection = [],
        private readonly ?FrontendChangesDTO $frontend = null,
    ) {
    }

    /**
     * Creates a self-connection update signal that only carries frontend state changes.
     */
    public static function fromFrontendChanges(FrontendChangesDTO $frontend): self
    {
        return new self(frontend: $frontend);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [];
        if ($this->selfConnection !== []) {
            $data[self::selfConnection] = $this->selfConnection;
        }
        $frontend = $this->frontend?->toArray();
        if ($frontend !== null && $frontend !== []) {
            $data[self::frontend] = $frontend;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        $selfConnection = $data[self::selfConnection] ?? [];
        $frontend = $data[self::frontend] ?? null;

        return new static(
            selfConnection: is_array($selfConnection) ? $selfConnection : [],
            frontend: is_array($frontend) ? FrontendChangesDTO::fromArray($frontend) : null,
        );
    }
}
