<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
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
    public const string attachmentDrafts = 'attachmentDrafts';
    public const string fileUploadProgress = 'fileUploadProgress';
    public const string filename = 'filename';
    public const string uploadedBytes = 'uploadedBytes';
    public const string totalBytes = 'totalBytes';

    /**
     * @param array<string, mixed> $selfConnection Browser-safe connection summary
     */
    public function __construct(
        public readonly array $selfConnection,
    ) {
    }

    /**
     * @return array{selfConnection: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [self::selfConnection => $this->selfConnection];
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        $selfConnection = $data[self::selfConnection] ?? [];

        return new static(selfConnection: is_array($selfConnection) ? $selfConnection : []);
    }
}
