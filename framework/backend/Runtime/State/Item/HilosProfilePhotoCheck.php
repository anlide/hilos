<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Hilos\Core\Exception\InvalidFormatException;

/** A profile photo awaiting a project's verdict on one browser connection. */
final class HilosProfilePhotoCheck extends RtState
{
    public const string RT_COLLECTION = 'hilosProfilePhotoChecks';

    public const string acceptKey = 'acceptKey';
    public const string userId = 'userId';
    public const string clientUploadId = 'clientUploadId';
    public const string startedAt = 'startedAt';

    private(set) string $acceptKey = '';
    private(set) int $userId = 0;
    private(set) string $clientUploadId = '';
    private(set) int $startedAt = 0;

    /**
     * @param string $acceptKey Connection awaiting the verdict
     * @param int $userId Person whose photo is checked
     * @param string $clientUploadId Pending upload id
     * @param int $startedAt Start moment in epoch milliseconds
     * @return static New pending check
     */
    public static function create(string $acceptKey, int $userId, string $clientUploadId, int $startedAt): static
    {
        $instance = new static();
        $instance->acceptKey = $acceptKey;
        $instance->userId = $userId;
        $instance->clientUploadId = $clientUploadId;
        $instance->startedAt = $startedAt;
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized check
     * @return static Restored check
     * @throws InvalidFormatException When a required field is absent or malformed
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->acceptKey = self::requireString($row, self::acceptKey);
        $instance->userId = self::requireInt($row, self::userId);
        $instance->clientUploadId = self::requireString($row, self::clientUploadId);
        $instance->startedAt = self::requireInt($row, self::startedAt);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /** @return string Runtime collection key */
    public static function getRtCollectionKey(): string
    {
        return self::RT_COLLECTION;
    }

    /** @return string Accept key of the connection awaiting a verdict */
    public function getId(): string
    {
        return $this->acceptKey;
    }

    /** @return array<string, mixed> Full row for runtime synchronization */
    public function toArray(): array
    {
        return [
            self::acceptKey => $this->acceptKey,
            self::userId => $this->userId,
            self::clientUploadId => $this->clientUploadId,
            self::startedAt => $this->startedAt,
        ];
    }
}
