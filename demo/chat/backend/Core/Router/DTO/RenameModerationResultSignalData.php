<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Demo\Chat\Constants\ChatSignalConstants;
use Hilos\BaseDTO;
use Hilos\Core\Router\ActionErrorSignalDataInterface;

/**
 * Result of async moderation for a user-initiated display-name change.
 */
final class RenameModerationResultSignalData extends BaseDTO implements ActionErrorSignalDataInterface
{
    /**
     * @param string $acceptKey WebSocket accept key that initiated the rename
     * @param int $userId User id that initiated the rename
     * @param string $newName Requested display name
     * @param bool $allow Whether the name is allowed
     * @param string $reason Moderation reason
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly int $userId,
        public readonly string $newName,
        public readonly bool $allow,
        public readonly string $reason,
    ) {
    }

    public function getAcceptKey(): string
    {
        return $this->acceptKey;
    }

    public function getActionErrorName(): string
    {
        return ChatSignalConstants::RENAME;
    }

    /**
     * @return array{newName: string}
     */
    public function getActionErrorPayload(): array
    {
        return [
            'newName' => $this->newName,
        ];
    }

    /**
     * @return array<string, int|string|bool>
     */
    public function toArray(): array
    {
        return [
            'acceptKey' => $this->acceptKey,
            'userId' => $this->userId,
            'newName' => $this->newName,
            'allow' => $this->allow,
            'reason' => $this->reason,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     */
    public static function fromArray(array $data): static
    {
        return new static(
            acceptKey: (string)($data['acceptKey'] ?? ''),
            userId: (int)($data['userId'] ?? 0),
            newName: (string)($data['newName'] ?? ''),
            allow: (bool)($data['allow'] ?? false),
            reason: (string)($data['reason'] ?? ''),
        );
    }
}
