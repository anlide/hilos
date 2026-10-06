<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\LanguageNames as EntityLanguageNames;
use Hilos\Database\Entity\Item\LanguageName as EntityLanguageName;
use Hilos\Database\Object\Item\LanguageName as ObjectLanguageName;
use Hilos\Database\Object\Objects;

/**
 * Translated language names, loaded as a whole on first read.
 *
 * @extends Objects<ObjectLanguageName>
 * @method ObjectLanguageName|null offsetGet(mixed $offset)
 */
class LanguageNames extends Objects
{
    public const string OBJECT_CLASS = ObjectLanguageName::class;
    public const string ENTITY_COLLECTION_CLASS = EntityLanguageNames::class;
    public const string COLLECTION_KEY = HilosDbContext::languageNames;

    /**
     * @param int $languageId Named language id
     * @param int $inLanguageId Writing language id
     * @return ?ObjectLanguageName Base row, or null
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the query is invalid
     */
    public function findBase(int $languageId, int $inLanguageId): ?ObjectLanguageName
    {
        return $this->findOne($languageId, $inLanguageId, null);
    }

    /**
     * @param int $languageId Named language id
     * @param int $inLanguageId Writing language id
     * @param int $localeId Override locale id
     * @return ?ObjectLanguageName Override row, or null
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the query is invalid
     */
    public function findOverride(int $languageId, int $inLanguageId, int $localeId): ?ObjectLanguageName
    {
        return $this->findOne($languageId, $inLanguageId, $localeId);
    }

    /**
     * @param int $languageId Named language id
     * @param int $inLanguageId Writing language id
     * @return list<ObjectLanguageName> Overrides for this base
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the query is invalid
     */
    public function overridesFor(int $languageId, int $inLanguageId): array
    {
        $rows = static::entityClass()::get(
            '`' . EntityLanguageName::language_id . '` = ? AND `' . EntityLanguageName::in_language_id . '` = ?'
                . ' AND `' . EntityLanguageName::locale_id . '` IS NOT NULL',
            [$languageId, $inLanguageId],
        );
        $names = [];
        foreach ($rows as $row) {
            if ($row->id !== null) {
                if (!isset($this->objects[$row->id])) {
                    $this->hydrate($row->id, static::OBJECT_CLASS::fromEntity($row));
                }
                $names[] = $this->objects[$row->id];
            }
        }
        return $names;
    }

    /**
     * @param int $languageId Named language id
     * @param int $inLanguageId Writing language id
     * @param ?int $localeId Null for the base, otherwise override locale
     * @return ?ObjectLanguageName Matching row, or null
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the query is invalid
     */
    private function findOne(int $languageId, int $inLanguageId, ?int $localeId): ?ObjectLanguageName
    {
        $where = '`' . EntityLanguageName::language_id . '` = ? AND `' . EntityLanguageName::in_language_id . '` = ?'
            . ' AND `' . EntityLanguageName::locale_id . '`' . ($localeId === null ? ' IS NULL' : ' = ?');
        $params = $localeId === null ? [$languageId, $inLanguageId] : [$languageId, $inLanguageId, $localeId];
        $row = static::entityClass()::get($where, $params)->first();
        if ($row?->id === null) {
            return null;
        }
        if (!isset($this->objects[$row->id])) {
            $this->hydrate($row->id, static::OBJECT_CLASS::fromEntity($row));
        }
        return $this->objects[$row->id];
    }
}
