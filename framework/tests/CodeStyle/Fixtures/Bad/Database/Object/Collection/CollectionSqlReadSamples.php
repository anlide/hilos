<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad\Database\Object\Collection;

class CollectionSqlReadSamples extends BaseCollection
{
    public function reads(): void
    {
        static::entityClass()::get();
        self::entityClass()::count();
        parent::entityClass()::get();
        StAtIc::EnTiTyClAsS()::CoUnT();
        SeLf :: ENTITYCLASS ( ) :: GET ( );
        PaReNt /* gap */ :: entityClass /* gap */ ( /* gap */ ) :: count /* gap */ ( );
        static
            ::entityClass()
            ::get(
            );
        static::entityClass()::get(); self::entityClass()::count();

        static::entityClass()::get(); // fresh-sql:
        self::entityClass()::count(); // fresh-sql:
        // fresh-sql: The transaction must read rows absent from this mirror.
        static::entityClass()::get();
        static::entityClass()
            ::count(); // fresh-sql: The transaction must read rows absent from this mirror.
        static::entityClass()::get(); // The former fresh SQL reason was removed.
        parent::entityClass()::count(); /* fresh-sql: Rows are absent from this mirror. */
        self::entityClass()::get(); # fresh-sql: Rows are absent from this mirror.
        static::entityClass()::count(); /** fresh-sql: Rows are absent from this mirror. */
        static::entityClass()::get(); $marker = '// fresh-sql: Rows are absent from this mirror.';
        // fresh-sql: The transaction must read rows absent from this mirror.
        $unrelated = 1;
        static::entityClass()::count();
        static::entityClass()::get(); // ordinary comment with // fresh-sql: a distant marker
    }
}

class BaseCollection
{
}
