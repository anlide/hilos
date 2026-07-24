<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\HilosUser\DTO;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;
use Hilos\Constants\SignalPayloadConstants;

/**
 * DTO for the Hilos users "merge account into this" action payload (HIL-378).
 *
 * The survivor is the acted-on row; the loser is picked in the confirm dialog.
 * Both ids travel in the payload because {@see \Demo\Chat\Pages\Hilos\Users\UserPage::onAction()}
 * receives no route params, so the survivor cannot be read back from the page
 * subscription — this mirrors {@see HilosUserUpdateActionDTO} carrying its own id.
 */
final class HilosUserMergeActionDTO extends ChatActionPayloadDTO
{
    /**
     * @param int $survivorUserId Survivor user id that absorbs the loser (the acted-on row)
     * @param int $loserUserId Loser user id folded into the survivor
     */
    public function __construct(
        public readonly int $survivorUserId,
        public readonly int $loserUserId,
    ) {
    }

    public function getAction(): string
    {
        return ChatSignalConstants::ACCOUNT_MERGE;
    }

    /**
     * @param array<string, mixed> $data Raw payload (may contain FIELD_DATA wrapper)
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (is_array($inner) && isset($inner[SignalPayloadConstants::FIELD_DATA]) && is_array($inner[SignalPayloadConstants::FIELD_DATA])) {
            $inner = $inner[SignalPayloadConstants::FIELD_DATA];
        }

        return new static(
            survivorUserId: (int)($inner['survivorUserId'] ?? 0),
            loserUserId: (int)($inner['loserUserId'] ?? 0),
        );
    }

    /**
     * @return array{survivorUserId: int, loserUserId: int}
     */
    public function toArray(): array
    {
        return [
            'survivorUserId' => $this->survivorUserId,
            'loserUserId' => $this->loserUserId,
        ];
    }
}
