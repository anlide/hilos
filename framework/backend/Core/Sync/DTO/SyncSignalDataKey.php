<?php

declare(strict_types=1);

namespace Hilos\Core\Sync\DTO;

/**
 * Payload field keys for DB and RT sync signal data DTOs.
 */
final class SyncSignalDataKey
{
    public const string COLLECTION_KEY = 'collectionKey';
    public const string ID_STRING = 'idString';
    public const string STATE_ID = 'stateId';
    public const string ROW = 'row';
}
