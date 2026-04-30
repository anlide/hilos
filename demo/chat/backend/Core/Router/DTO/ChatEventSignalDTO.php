<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\Core\Exception\NotImplementedException;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Router\DTO\FrontendChangesDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Table\DTO\TableSnapshotDTO;

/**
 * ChatEventSignalDTO - Signal data for chat events.
 *
 * Simple pass-through of entities to frontend.
 * Optional frontend payload carries explicit frontend state collection changes.
 * Optional tables payload for full snapshot responses (e.g. admin page with users table).
 * Optional user session fields for page subscribe (outbound moderation, attachment drafts, upload progress)
 * when {@see self::$includeUserSessionFields} is true.
 */
final class ChatEventSignalDTO extends SignalData implements SignalDataInterface
{
    /**
     * Creates chat event signal DTO.
     *
     * @param EntitiesChangesDTO $entities Entity changes
     * @param array<string, TableSnapshotDTO> $tables Table key → full snapshot DTO
     * @param ?array<string, mixed> $outboundModerationState Current outbound moderation UI state or null
     * @param list<array<string, mixed>> $attachmentDrafts Uploaded attachment drafts for this connection
     * @param ?array<string, mixed> $fileUploadProgress In-flight binary upload progress or null
     * @param bool $includeUserSessionFields When true, merge session keys into the payload
     * @param ?FrontendChangesDTO $frontend Explicit frontend state collection changes
     */
    public function __construct(
        public readonly EntitiesChangesDTO $entities,
        public readonly array $tables = [],
        public readonly ?array $outboundModerationState = null,
        public readonly array $attachmentDrafts = [],
        public readonly ?array $fileUploadProgress = null,
        public readonly bool $includeUserSessionFields = false,
        public readonly ?FrontendChangesDTO $frontend = null,
    ) {
        parent::__construct($this->toArray());
    }

    /**
     * Convert DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        $data = ['entities' => $this->entities->toArray()];
        $frontend = $this->frontend?->toArray();
        if ($frontend !== null && $frontend !== []) {
            $data['frontend'] = $frontend;
        }
        if (!empty($this->tables)) {
            $tablesArr = array_map(function ($dto) {
                return $dto->toArray();
            }, $this->tables);
            $data['tables'] = $tablesArr;
        }
        if ($this->includeUserSessionFields) {
            $data['outboundModerationState'] = $this->outboundModerationState;
            $data['attachmentDrafts'] = $this->attachmentDrafts;
            $data['fileUploadProgress'] = $this->fileUploadProgress;
        }
        return $data;
    }

    /**
     * Create DTO from array (not implemented - response is created directly).
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws NotImplementedException Deserialization is not implemented
     */
    public static function fromArray(array $data): static
    {
        throw new NotImplementedException('ChatEventSignalDTO::fromArray() is not implemented');
    }
}
