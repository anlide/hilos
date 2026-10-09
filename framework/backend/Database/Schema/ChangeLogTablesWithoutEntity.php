<?php

declare(strict_types=1);

namespace Hilos\Database\Schema;

use Hilos\Backup\Anonymization\AnonymizationStrategy;

/**
 * The separate journal database's six tables and their restore-time verdicts.
 */
final class ChangeLogTablesWithoutEntity implements TablesWithoutEntityProvider
{
    /**
     * @return list<string> Journal tables outside the ORM
     */
    public static function tables(): array
    {
        return array_keys(self::pii());
    }

    /**
     * @return array<string, array<string, AnonymizationStrategy>> Personal columns by table
     */
    public static function pii(): array
    {
        return [
            'hilos_change_log_table' => [],
            'hilos_change_log_field' => [],
            'hilos_change_log_receipt' => [
                'actor_user_id' => AnonymizationStrategy::NULLIFY,
                'subject_user_id' => AnonymizationStrategy::NULLIFY,
                'session_id' => AnonymizationStrategy::NULLIFY,
                'agent' => AnonymizationStrategy::MASK,
                'source' => AnonymizationStrategy::MASK,
            ],
            'hilos_change_log' => [
                'record_key' => AnonymizationStrategy::MASK,
                'record_key_hash' => AnonymizationStrategy::NULLIFY,
            ],
            'hilos_change_log_change' => [
                'old_value' => AnonymizationStrategy::MASK,
                'new_value' => AnonymizationStrategy::MASK,
            ],
            'hilos_change_log_value' => [
                'old_value' => AnonymizationStrategy::MASK,
                'new_value' => AnonymizationStrategy::MASK,
            ],
        ];
    }

    /**
     * @return array<string, list<string>> Reviewed non-personal columns by table
     */
    public static function piiNotPersonal(): array
    {
        return [
            'hilos_change_log_table' => ['id', 'name'],
            'hilos_change_log_field' => ['id', 'table_id', 'name'],
            'hilos_change_log_receipt' => ['id', 'created_at', 'channel', 'action', 'open_handovers'],
            'hilos_change_log' => ['id', 'created_at', 'receipt_id', 'table_id', 'mutation_type'],
            'hilos_change_log_change' => [
                'id',
                'created_at',
                'log_id',
                'field_id',
                'kind',
                'old_present',
                'new_present',
            ],
            'hilos_change_log_value' => ['id', 'created_at', 'change_id'],
        ];
    }
}
