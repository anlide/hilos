<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Schema;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\Schema\TablesWithoutEntityProvider;

/** Historical table verdicts needed before a restore's forward migrations remove the retired tables. */
final class ChatTablesWithoutEntity implements TablesWithoutEntityProvider
{
    private const string LEGACY_MODERATOR_PROMPT_PIECE = 'moderator_prompt_piece';

    /** @return list<string> Retired table names, derived from their verdicts */
    public static function tables(): array
    {
        return array_keys(self::pii());
    }

    /** @return array<string, array<string, AnonymizationStrategy>> Retired tables with no personal columns to rewrite */
    public static function pii(): array
    {
        return [self::LEGACY_MODERATOR_PROMPT_PIECE => []];
    }

    /** @return array<string, list<string>> Exact non-personal columns declared by the retired Entities */
    public static function piiNotPersonal(): array
    {
        return [self::LEGACY_MODERATOR_PROMPT_PIECE => ['id', 'section', 'prompt_piece']];
    }
}
